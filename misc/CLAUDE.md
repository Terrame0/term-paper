# Проект: курсовая по базам данных (КГТУ)

Веб-стек + нагрузочный тестер PostgreSQL. Демонстрирует «реальный use-case» БД для защиты: схема рекламного агентства (`production-db`), параллельные клиенты бьют по ней, результаты тестов хранятся в **отдельной** БД (`test-db`) и отображаются в виде heatmap'ов.

## Стек

| Слой | Технология | Где |
|---|---|---|
| Окружение | Nix flake | `flake.nix`, `nix/`, `server-config.nix` |
| БД | PostgreSQL 17 (через `pg_ctl`, systemd-user units) | `nix/modules/postgres-mgr.nix` |
| Миграции | [pgschema](https://github.com/pgplex/pgschema) (декларативный diff) | `nix/modules/pgschema-mgr.nix` |
| Веб-сервер | nginx + PHP-FPM | `nix/modules/nginx-mgr.nix`, `nix/modules/php-mgr.nix` |
| Веб-страницы | PHP 8 + PDO (без фреймворка) | `php/src/main.php`, `php/src/tests.php` |
| Нагрузочный тестер | Python 3.13 + asyncio + asyncpg | `python/src/` |

## Структура

```
term-paper/
├── flake.nix                       — Nix flake, devShell, server-start/stop
├── server-config.nix               — единый конфиг (пути, имена БД, env vars)
├── schemas/
│   ├── production-db-ddl.sql       — основная схема: client, promotion, promotion_*
│   └── test-db-ddl.sql             — результаты тестов: test, test_sweep, test_cell
├── nix/
│   ├── modules/                    — менеджеры сервисов (postgres, nginx, php-fpm, pgschema, db-tester)
│   └── my-lib/                     — вспомогательные nix-функции (mk-script-union, mk-worker-scripts, cat, check-unit)
├── php/
│   ├── composer.json               — phpoffice/phpspreadsheet
│   ├── project.nix                 — composer-based derivation
│   └── src/
│       ├── main.php                — viewer схемы production-db
│       ├── tests.php               — heatmap-визуализация прогонов тестов
│       └── style.css               — общие стили (выровнен под обе страницы)
├── python/
│   ├── pyproject.toml              — пакет db-tester, dep: asyncpg
│   ├── project.nix                 — buildPythonApplication
│   └── src/
│       ├── main.py                 — точка входа CLI db-tester
│       └── scenarios.py            — словарь захардкоженных сценариев {name -> [Op]}
├── FORMATTING.md                   — code style по языкам
├── TESTER.md                       — ТЗ нагрузочного тестера
└── todo                            — заметки пользователя (не следовать слепо)
```

## Архитектура nix-окружения

### server-config.nix

Единственное место с «магией»: пути, имена сервисов, имена БД, имена env vars.

Атрибут `env-vars` мапит нормализованные UPPER_SNAKE-имена на значения:
- `PGHOST` → `pg-socket-dir`
- `PGUSER` → `db-user`  
- `PRODUCTION_DB` → `production-db-name`
- `TEST_DB` → `test-db-name`

Эти env vars экспортируются в **двух местах**:
1. `flake.nix` shellHook — для `nix develop` (python, ручной psql, etc.)
2. `php-mgr.nix` через `env[NAME] = value` в pool-config — для php-fpm

### nix/my-lib/ — переиспользуемые функции

- `mk-script-union` — создаёт `symlinkJoin` из набора shell-скриптов как одну derivation
- `mk-worker-scripts` — генерит пару `<name>-start` / `<name>-stop` для управления через `systemd-run --user`
- `cat`, `check-unit` — мелкие утилиты

### nix/modules/ — менеджеры сервисов

Каждый модуль возвращает `mk-script-union` с одним или несколькими скриптами:
- `postgres-mgr.nix` → `postgres-start`, `postgres-stop` (initdb + pg_ctl)
- `nginx-mgr.nix` → `nginx-start`, `nginx-stop`
- `php-mgr.nix` → `php-fpm-start`, `php-fpm-stop`
- `pgschema-mgr.nix` → `schema-apply` (создаёт роль/БД, применяет оба DDL)
- `pgschema.nix` — собирает Go-биннарь `pgschema` (не управляющий скрипт!)

### flake.nix

- `forEach` по `nix/modules/` подгружает все модули с подмешанными `server-config`, `flake-root`
- `server-start` / `server-stop` оборачивают цепочку: postgres → schema-apply → php-fpm → nginx
- В `devShells.default.buildInputs`: server + postgres + pgschema + db-tester + все modules

## Состояние и эфемерность

Всё состояние в **`/tmp/term-paper/`**, состоящее из:
- `postgres/data` — кластер pg
- `postgres/sockets` — сокет
- `nginx/` — pid, logs
- `php/` — fpm-socket, pid, logs

`/tmp` эфемерно, поэтому **снос — нормальный способ сброса**:
```
server-stop
rm -rf /tmp/term-paper
server-start    # initdb + schema-apply отработают заново
```

Если postgres юнит застрял в failed:
```
systemctl --user reset-failed postgres
```

## Команды разработки

В `nix develop`:

| Команда | Действие |
|---|---|
| `server-start` | поднимает postgres → применяет схемы → fpm → nginx |
| `server-stop` | останавливает в обратном порядке |
| `schema-apply` | вручную применить DDL (создаёт роль/БД при нужде) |
| `db-tester --workers W --ops-per-worker O --prefill P --scenario basic` | запустить тест |
| `psql -h "$PGHOST" -U "$PGUSER" -d "$PRODUCTION_DB"` | подключиться к prod БД |

CLI `db-tester` принимает три параметра в формате:
- `N` — фиксированное значение
- `base-mult-count` — геометрическая прогрессия (например `10-3-4` → `[10, 30, 90, 270]`)

Минимум **два** параметра должны быть диапазонами. Если три — будут запущены **3 sweep'а** (по одному на пару осей), при двух — один sweep с третьим параметром, зафиксированным на максимуме своего диапазона (или единственном значении).

Сценарии (`basic`, потенциально другие) живут в `python/src/scenarios.py` — захардкожены.

## Веб-страницы

- `http://localhost:8008/src/main.php` — viewer схемы production-db (читает `pg_catalog`)
- `http://localhost:8008/src/tests.php` — выбор `test_id`, рендер 3 heatmap'ов (по одному на sweep), p95 жирным/p50 мелким, log-цветовая шкала, общая шкала на всю страницу

URL префикс `/src/` — потому что nginx `root` указывает на корень composer-проекта, а PHP-файлы лежат в `src/`.

## Модель данных тестера

`test` (1) → `test_sweep` (1-3) → `test_cell` (N×M)

- `test` — один запуск `db-tester`
- `test_sweep` — одна 2D-сетка с фиксированной парой осей (`x_axis`, `y_axis`) и третьим параметром (`fixed_param`)
- `test_cell` — одна точка сетки с измеренными `latency_p50_ms`, `latency_p95_ms`

Имена осей хранятся в CLI-формате (`workers`, `ops_per_worker`, `prefill`), а колонки `test_cell` — в snake_case (`worker_count`, `ops_per_worker`, `prefill_rows`). PHP мапит через `AXIS_TO_COL`-словарь.

## Что НЕ делать

- **Не запускать** `init-db` — этот скрипт удалён, всё делает `schema-apply`
- **Не править** `server-config.nix` атрибуты без проверки `php-mgr.nix` (он итерирует только `env-vars`, остальные не пропадут, но имена не должны быть пустыми)
- **Не использовать** chart.js / графики — переехали на серверные heatmap'ы (см. историю в `TESTER.md`)
- **Не вводить** новые имена для существующих сущностей (`production-db` / `test-db` / `main-user`) без обновления всех мест: server-config, php main.php/tests.php (через env vars), pgschema-mgr
- **Не добавлять** uncommitted/untracked DDL/PHP/Python файлы — `flake-root = self.outPath`, который видит только git-tracked файлы; новые файлы надо `git add` (необязательно `commit`)

## Подводные камни

1. **`PGUSER` ломает первичную инициализацию.** `schema-apply` делает `unset PGUSER` в начале — чтобы `psql`/`createuser`/`createdb` шли от OS-юзера (`terrame`, он же постгрес-суперюзер после initdb). Без этого первый запуск падает с `role "main-user" does not exist`.
2. **MIME-types.** Без `include ${pkgs.nginx}/conf/mime.types` nginx отдаёт `.css` как `text/plain` и браузер игнорирует стили. Включено в `nginx-mgr.nix`.
3. **PHP-store-path меняется при правке `.php`.** `nix develop` пересобирает `php-server` derivation, но **уже работающий** nginx указывает на старый store-path. После правки `.php`/`.css` нужно `server-stop && server-start` (или хотя бы `nginx-stop && nginx-start`).
4. **`/tmp/term-paper` имя БД.** Если в `server-config.nix` поменять `production-db-name` — старая БД остаётся в кластере. `pgschema apply` создаст новую, но мусор копится. Чистка — снести `/tmp/term-paper` или вручную `DROP DATABASE`.

## Где смотреть дальше

- ТЗ тестера и история его дизайна: [TESTER.md](TESTER.md)
- Стилевые соглашения по языкам: [FORMATTING.md](FORMATTING.md)
- Заметки пользователя (могут быть устаревшие): [todo](todo)
