# ТЗ: тестер базы данных

## Контекст

Преподаватель хочет видеть реальный use-case БД — конкурентную нагрузку, а не только схему. Решение: Python-приложение, которое имитирует параллельных клиентов, бьёт по основной БД, складывает результаты тестов в **отдельную** БД, PHP-фронт показывает статистику. Тестовые таблицы вынесены в свою БД, чтобы основная схема (`promotions-db`) оставалась чистой.

## Структура

```
term-paper/
├── promotions-db-ddl.sql         (переименовать из db-ddl.sql)
├── test-results-db-ddl.sql       (новый — схема для результатов тестов)
├── php/                          (есть)
├── nix/                          (есть)
└── python/                       (новое)
    ├── src/
    │   └── main.py
    ├── scenarios/
    │   └── basic.json
    ├── pyproject.toml
    └── project.nix
```

## Две базы данных

В postgres будет жить две БД, обе под одним owner-ом `main-user`:

| БД | Назначение | DDL |
|---|---|---|
| `promotions-db` | основная схема (client, promotion, ...) | `promotions-db-ddl.sql` |
| `test-results-db` | таблицы с результатами тестов | `test-results-db-ddl.sql` |

Необходимые правки в существующем коде (за пределами этого ТЗ — делать отдельно):
- `db-ddl.sql` → `promotions-db-ddl.sql`
- [server-config.nix](server-config.nix): вместо `db-name = "test"` добавить `promotions-db-name = "promotions-db"` и `test-results-db-name = "test-results-db"`
- [nix/modules/pgschema-mgr.nix](nix/modules/pgschema-mgr.nix): применять оба DDL — по одному `pgschema apply` на каждую БД
- [php/src/main.php](php/src/main.php): подключаться к `promotions-db`
- Новый `php/src/tests.php`: подключаться к `test-results-db`

## Функциональность

CLI-команда `db-tester`:

```
db-tester --workers N --ops-per-worker M --scenario scenarios/basic.json
```

Поведение:
1. Запускает `N` параллельных корутин-воркеров
2. Каждый воркер **сам открывает своё соединение** к `promotions-db` через `asyncpg.connect(...)`, держит его весь прогон, в конце закрывает. Моделирует долгоживущего клиента (одна сессия — одно соединение)
3. Воркер выполняет `M` операций из сценария (случайный выбор операции с учётом весов)
4. Latency каждой операции замеряется обёрткой вокруг `await connection.execute(...)` через `time.perf_counter()`
5. После того как все воркеры завершились, главная корутина открывает одно соединение к `test-results-db` и пишет агрегат в `test_run` + детали по воркерам в `test_worker_result`
6. Печатает в stdout короткий статус (`run #42: 10 workers, 1000 ops, 234ms, 0 errors`)

## Сценарий нагрузки

Сценарий описывается **JSON-файлом** (`json` в stdlib Python).

Структура: список операций, каждая со своим SQL и весом. Воркер на каждом шаге случайно выбирает операцию пропорционально весу. Все операции самодостаточны (не зависят от состояния между ними) — для вставки используются генераторы значений на стороне SQL (`gen_random_uuid`, `NOW()`, `RANDOM()`), для чтения — `ORDER BY RANDOM() LIMIT N`.

Пример `python/scenarios/basic.json`:

```json
{
  "operations": [
    {
      "name": "insert_client",
      "weight": 1,
      "sql": "INSERT INTO client (name, registration_time, is_legal_entity) VALUES ('test-' || gen_random_uuid()::text, NOW(), false)"
    },
    {
      "name": "select_random_client",
      "weight": 3,
      "sql": "SELECT * FROM client ORDER BY RANDOM() LIMIT 1"
    },
    {
      "name": "join_client_promotion",
      "weight": 2,
      "sql": "SELECT c.client_id, c.name, p.name, p.cost FROM client c LEFT JOIN promotion p ON p.client_id = c.client_id ORDER BY RANDOM() LIMIT 10"
    }
  ]
}
```

Можно держать несколько сценариев: `basic.json`, `read_heavy.json`, `write_heavy.json` — и переключать флагом `--scenario`.

## Схема результатов

Новый файл `test-results-db-ddl.sql`:

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

## Nix-интеграция

- `python/project.nix` — derivation через `pkgs.python3Packages.buildPythonApplication`
- Зависимости: `asyncpg`, `click` (CLI)
- Доступ к параметрам подключения — через переменные окружения, экспортируемые из `server-config.nix` (host = `pg-socket-dir`, user = `db-user`, БД для нагрузки = `promotions-db-name`, БД для результатов = `test-results-db-name`)
- В [flake.nix](flake.nix): добавить пакет в `devShells.default.buildInputs`
- Бинарник `db-tester` доступен в `nix develop`

## PHP-интеграция

Добавить страницу `php/src/tests.php`, подключается к `test-results-db`:
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

## закрытые вопросы

тесты конфигурируем через файл, формат определи здесь:
...

один пул соединений на все воркеры

меряем задержки через asyncpg

таблиц будет достаточно, но можно попробовать в дальнейшем сделать графики, если это не слишком добавит сложности проекту