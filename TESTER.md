# ТЗ: тестер базы данных

## Контекст

Преподаватель хочет видеть реальный use-case БД — конкурентную нагрузку, а не только схему. Решение: Python-приложение, которое имитирует параллельных клиентов, бьёт по основной БД, складывает результаты тестов в **отдельную** БД, PHP-фронт показывает статистику. Тестовые таблицы вынесены в свою БД, чтобы основная схема (`production-db`) оставалась чистой.

## Структура

```
term-paper/
├── production-db-ddl.sql         (переименовать из db-ddl.sql)
├── test-db-ddl.sql               (новый — схема для результатов тестов)
├── php/                          (есть)
├── nix/                          (есть)
└── python/                       (новое)
    ├── src/
    │   ├── main.py
    │   └── scenarios.py          (захардкоженные сценарии)
    ├── pyproject.toml
    └── project.nix
```

## Две базы данных

В postgres будет жить две БД, обе под одним owner-ом `main-user`:

| БД | Назначение | DDL |
|---|---|---|
| `production-db` | основная схема (client, promotion, ...) | `production-db-ddl.sql` |
| `test-db` | таблицы с результатами тестов | `test-db-ddl.sql` |

Необходимые правки в существующем коде (за пределами этого ТЗ — делать отдельно):
- `db-ddl.sql` → `production-db-ddl.sql`
- [server-config.nix](server-config.nix): вместо `db-name = "test"` добавить `production-db-name = "production-db"` и `test-db-name = "test-db"`
- [nix/modules/pgschema-mgr.nix](nix/modules/pgschema-mgr.nix): применять оба DDL — по одному `pgschema apply` на каждую БД (обновить **обе** ссылки на `db-name`)
- [php/src/main.php](php/src/main.php): подключаться к `production-db`
- Новый `php/src/tests.php`: подключаться к `test-db`
- Старый `/tmp/term-paper` снести перед первым запуском (миграции не делаем)

## Функциональность

CLI-команда `db-tester` (все флаги обязательные, без дефолтов):

```
db-tester --workers N --ops-per-worker M --scenario basic
```

Параметры подключения берутся из env vars (см. секцию «Параметры подключения»). Сценарии захардкожены в `src/scenarios.py` (словарь `SCENARIOS: dict[str, list[Op]]`), `--scenario` — это просто ключ из этого словаря.

Поведение:
1. **Очистка `production-db`** перед прогоном: `TRUNCATE client CASCADE` (каскадно сносит promotion, address, phone, email и все promotion_*). Каждый прогон — с чистого листа
2. Запускает `N` параллельных корутин-воркеров
3. Каждый воркер **сам открывает своё соединение** к `production-db` через `asyncpg.connect(..., command_timeout=5)`, держит весь прогон, в конце закрывает. Моделирует долгоживущего клиента (одна сессия — одно соединение)
4. Воркер выполняет `M` операций из сценария (случайный выбор операции с учётом весов). На SQL-ошибке (исключения `asyncpg.exceptions.*`) воркер **продолжает работу**, инкрементирует свой счётчик `errors`. Latency упавшей операции **включается** в общую статистику (это часть реального поведения)
5. После того как все воркеры завершились, главная корутина открывает одно соединение к `test-db` и пишет агрегат в `test_run` + детали по воркерам в `test_worker_result`. `test_run.errors_count = SUM(test_worker_result.errors)`
6. Печатает в stdout короткий статус (`run #42: 10 workers, 1000 ops, 234ms, 0 errors`)

Замечание про перцентили: при малом `--ops-per-worker` (меньше ~100) `p99` будет шумным. Для осмысленных p95/p99 ставить минимум 100 операций на воркера.

## Сценарии нагрузки

Сценарии живут в `src/scenarios.py` как словарь `SCENARIOS: dict[str, list[Op]]`, где `Op` — `dataclass` с полями `name: str`, `weight: int`, `sql: str`.

Воркер на каждом шаге случайно выбирает операцию пропорционально весу. Все операции самодостаточны (не зависят от состояния между ними) — для вставки используются генераторы значений на стороне SQL (`gen_random_uuid`, `NOW()`, `RANDOM()`), для чтения — `ORDER BY RANDOM() LIMIT N`.

Пример `src/scenarios.py`:

```python
from dataclasses import dataclass

@dataclass(frozen=True)
class Op:
    name: str
    weight: int
    sql: str

SCENARIOS: dict[str, list[Op]] = {
    "basic": [
        Op("insert_client", 1, """
            INSERT INTO client (name, registration_time, is_legal_entity)
            VALUES ('test-' || gen_random_uuid()::text, NOW(), false)
        """),
        Op("select_random_client", 3,
            "SELECT * FROM client ORDER BY RANDOM() LIMIT 1"),
        Op("join_client_promotion", 2, """
            SELECT c.client_id, c.name, p.name, p.cost
            FROM client c
            LEFT JOIN promotion p ON p.client_id = c.client_id
            ORDER BY RANDOM() LIMIT 10
        """),
    ],
    # "read_heavy": [...], "write_heavy": [...]
}
```

Добавить новый сценарий = добавить ключ в словарь и пересобрать.

## Схема результатов

Новый файл `test-db-ddl.sql`:

```sql
CREATE TABLE test_run (
  run_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  started_at TIMESTAMP NOT NULL,
  finished_at TIMESTAMP NOT NULL,
  worker_count INT NOT NULL,
  ops_per_worker INT NOT NULL,
  total_duration_ms INT NOT NULL,
  errors_count INT NOT NULL
);

CREATE TABLE test_worker_result (
  worker_result_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  run_id INT NOT NULL,
  worker_id INT NOT NULL,
  ops_completed INT NOT NULL,
  latency_p50_ms NUMERIC(10,2),
  latency_p95_ms NUMERIC(10,2),
  latency_p99_ms NUMERIC(10,2),
  errors INT NOT NULL DEFAULT 0,
  FOREIGN KEY (run_id) REFERENCES test_run(run_id)
);
```

## Параметры подключения

Прокидываются через env vars (экспортируются `devShell`-ом из `server-config.nix`):

| Env var | Источник в `server-config.nix` |
|---|---|
| `PGHOST` | `pg-socket-dir` |
| `PGUSER` | `db-user` |
| `PRODUCTION_DB` | `production-db-name` |
| `TEST_DB` | `test-db-name` |

Python читает их через `os.environ` (или `os.getenv`).

## Nix-интеграция

- `python/project.nix` — derivation через `pkgs.python3Packages.buildPythonApplication`
- Зависимости: `asyncpg`
- В [flake.nix](flake.nix): добавить пакет в `devShells.default.buildInputs` и экспорт env vars (см. выше) в `mkShell.shellHook` или через `env`
- Бинарник `db-tester` доступен в `nix develop`

## PHP-интеграция

Добавить страницу `php/src/tests.php`, подключается к `test-db`:
- Список последних 20 прогонов: дата, воркеры, операций всего, длительность, ошибок
- Клик по прогону → таблица `test_worker_result` для этого `run_id`
- Только таблицы, без графиков

Маршрутизация в [nginx-mgr.nix](nix/modules/nginx-mgr.nix) — должна уже работать через `autoindex`, но проверить.

## Стиль кода

Python — отдельные правила в [FORMATTING.md](FORMATTING.md) (дописать раздел):
- Отступ 4 пробела (стандарт PEP-8, насиловать Python под 2-пробельный nix-стиль не стоит)
- snake_case для всего
- Type hints обязательны
- Форматтер — `ruff format`