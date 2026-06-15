# Соглашения по форматированию кода

Проект состоит из пяти кодовых сред: **PHP**, **SQL**, **Nix**, **Python**, **CSS**. Для каждой описаны правила и (по возможности) инструмент автоформатирования.

---

## PHP

**Файлы:** `php/src/main.php`, `php/src/tests.php`

Стиль выровнен под Nix: те же отступы, те же секционные комментарии, snake_case вместо kebab-case (дефис в PHP недопустим в идентификаторах).

### Правила

| Параметр | Значение |
|---|---|
| Отступ | 2 пробела |
| Открывающая скобка `{` | на той же строке |
| Максимальная длина строки | 100 символов |
| Теги | `<?php` / `?>` / `<?=` |
| Пространство имён | нет (скрипт однофайловый) |

**Именование:**
- Переменные и параметры — `snake_case`: `$tables_stmt`, `$cols_stmt`
- Константы PDO — `UPPER_SNAKE_CASE`: `PDO::FETCH_ASSOC`

**Комментарии:**
- Секции — `// -- описание` (аналог `# --` в Nix)
- Однострочные пояснения — `// пояснение`
- Не дублировать имя переменной в комментарии

**SQL внутри PHP:**
- Многострочные запросы — строка с отступом в 2 пробела
- Ключевые слова SQL — UPPERCASE, идентификаторы — lowercase

**Пример корректного стиля:**
```php
// -- query columns
$cols_stmt = $pdo->prepare("
  SELECT
    a.attname AS column_name,
    t.typname AS data_type,
    a.attnotnull AS not_null
  FROM pg_attribute a
  JOIN pg_class c ON c.oid = a.attrelid
  WHERE c.relname = :table
    AND a.attnum > 0
  ORDER BY a.attnum
");
```

**Шаблонный PHP в HTML:**
- Блоки `<?php ... ?>` начинаются на отдельной строке
- Короткое эхо `<?= expr ?>` — только для простых выражений
- Всегда оборачивать вывод в `htmlspecialchars()`

### Инструмент

PHP CS Fixer не используется — его стиль (PSR-12) конфликтует с принятыми соглашениями (4 пробела, camelCase). Форматирование ручное.

---

## SQL

**Файлы:** `schemas/production-db-ddl.sql`, `schemas/test-db-ddl.sql`, SQL внутри `php/*.php` и `python/src/*.py`

### Правила

| Параметр | Значение |
|---|---|
| Отступ | 2 пробела |
| Ключевые слова | UPPERCASE |
| Идентификаторы | snake_case, без кавычек |
| Ограничения | одна строка с определением столбца или отдельная строка |

**Именование таблиц:**
- Сущности — существительное в единственном числе: `client`, `promotion`
- Подтипы — префикс родителя через `_`: `promotion_youtube`, `promotion_billboard`

**Именование столбцов:**
- Первичный ключ — `<table>_id`
- Внешний ключ — `<referenced_table>_id`
- Булево — глагол-признак: `is_legal_entity`, `illuminated`
- Временна́я метка — суффикс `_time` или `_at`: `start_time`, `recorded_at`

**Пример корректного стиля:**
```sql
CREATE TABLE promotion_radio (
  radio_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  promotion_id INT NOT NULL,
  station VARCHAR(45),
  air_time TIMESTAMP,
  spot_length INT,
  FOREIGN KEY (promotion_id) REFERENCES promotion(promotion_id)
);
```

**Порядок определений внутри таблицы:**
1. Первичный ключ
2. Внешние ключи (NOT NULL)
3. Обязательные поля (NOT NULL)
4. Опциональные поля
5. Ограничения `FOREIGN KEY` — последними

### Инструмент

[`pg_format`](https://github.com/darold/pgFormatter) (pgFormatter).

```bash
pg_format --inplace --spaces 2 --keyword-case 2 schemas/*.sql
```

Флаги: `--spaces 2` — отступ 2 пробела, `--keyword-case 2` — UPPERCASE ключевые слова.

---

## Nix

**Файлы:** `flake.nix`, `server-config.nix`, `nix/modules/*.nix`, `nix/my-lib/*.nix`

### Правила

| Параметр | Значение |
|---|---|
| Отступ | 2 пробела |
| Максимальная длина строки | 100 символов |
| Атрибуты | kebab-case |
| Функции | kebab-case |

**Именование атрибутов:**
- Пути и директории — `<service>-dir`, `<service>-log`, `<service>-pid`
- Сокеты — `<service>-socket`
- Команды — `<service>-start`, `<service>-stop`

**Структура модуля:**
```nix
{
  pkgs,
  lib,
  my-lib,
  server-config,
  ...
}:
with server-config;
let
  # локальные привязки
in
  # выражение
```

**Комментарии:**
- Секции — `# -- описание` (двойное тире)
- Однострочные пояснения — `# пояснение`
- Не дублировать имя атрибута в комментарии

**Пример корректного стиля:**
```nix
rec {
  # -- postgres parameters
  pg-dir = "${state-dir}/postgres";
  pg-data-dir = "${pg-dir}/data";
  pg-socket-dir = "${pg-dir}/sockets";
}
```

**`let...in` vs `with`:**
- `with server-config;` — для импорта конфига верхнего уровня в модуле
- `let...in` — для всех промежуточных вычислений внутри модуля

### Инструмент

[`nixfmt`](https://github.com/NixOS/nixfmt) (официальный форматтер RFC-style).

```bash
nixfmt flake.nix server-config.nix nix/**/*.nix
```

Nixfmt уже доступен через nixpkgs: `pkgs.nixfmt-rfc-style`.

---

## Python

**Файлы:** `python/src/main.py`, `python/src/scenarios.py`, `python/pyproject.toml`

Python — единственная кодовая среда, **где не подгоняем стиль под nix**. Сообщество жёстко за PEP-8, ломать привычки только ради консистентности не стоит.

### Правила

| Параметр | Значение |
|---|---|
| Отступ | 4 пробела (PEP-8) |
| Максимальная длина строки | 79 символов (мягко — допустимо до 100 если читаемость лучше) |
| Кавычки | `"..."` для строк, `"""..."""` для docstrings |
| Тип-аннотации | обязательны для аргументов функций и возвращаемых значений |

**Именование:**
- Переменные, функции, модули — `snake_case`
- Классы и dataclasses — `PascalCase`: `CellResult`, `Op`
- Константы модуля — `UPPER_SNAKE_CASE`: `PARAM_NAMES`, `ALL_PAIRS`
- Приватное по соглашению — префикс `_`

**Тип-аннотации:**
- Использовать встроенные generics (Python 3.9+): `list[int]`, `dict[str, list[Op]]`, `tuple[int, str]` — НЕ `List[int]` из `typing`
- `Optional[X]` → `X | None` (Python 3.10+)
- Союзы → `X | Y`

**Async:**
- Все коннекторы — `asyncpg` (асинхронный)
- Все функции, которые делают I/O — `async def`
- Замер latency — через `time.perf_counter()` обёрткой вокруг `await conn.execute(...)`
- Параллельные задачи — `asyncio.gather(*[...])`
- Closing — через `try/finally: await conn.close()` (не использовать `async with` если ресурс не AsyncContextManager)

**Комментарии:**
- Секции — `# -- описание` (двойное тире, как в Nix)
- Docstrings — для нетривиальных функций, формат «однострочка + пустая строка + детали»
- Не дублировать имя переменной/функции в комментарии

**Пример корректного стиля:**
```python
@dataclass
class CellResult:
    worker_count: int
    ops_per_worker: int
    prefill_rows: int
    p50: float
    p95: float


async def worker(
    ops: int,
    scenario: list[Op],
    prod_dsn: str,
) -> list[float]:
    conn = await asyncpg.connect(prod_dsn, command_timeout=5)
    weights = [op.weight for op in scenario]
    latencies: list[float] = []
    try:
        for _ in range(ops):
            op = random.choices(scenario, weights=weights)[0]
            t0 = time.perf_counter()
            try:
                await conn.execute(op.sql)
            except (asyncpg.exceptions.PostgresError, asyncio.TimeoutError):
                pass
            latencies.append((time.perf_counter() - t0) * 1000)
    finally:
        await conn.close()
    return latencies
```

### Инструмент

[`ruff`](https://github.com/astral-sh/ruff) — линтер + форматтер.

```bash
ruff check python/
ruff format python/
```

Можно добавить в `devShells.default.buildInputs`: `pkgs.ruff`.

---

## CSS

**Файлы:** `php/src/style.css`

Один общий файл для всех PHP-страниц. Структурируется через комментарии-секции (`/* -- name */`).

### Правила

| Параметр | Значение |
|---|---|
| Отступ | 2 пробела |
| Кавычки | `'...'` (singletons), `"..."` если внутри есть апостроф |
| Селекторы | один на строку при множественном перечислении (`a, b { ... }`) |
| Однострочные правила | допустимы для коротких блоков |

**Именование классов:**
- `kebab-case`: `.heatmap-card`, `.test-meta`, `.scale-bar`
- Без префиксов BEM (проект мелкий, не нужно)
- Вложенность через простое селектор-цепление, не `>` без причины

**Цветовая схема (тёмная):**
- Фон body — `#0f1115`
- Фон карточки — `#151922`
- Текст — `#d6d6d6`
- Границы — `#333`
- Акцент (заголовки/ссылки) — `#7cc7ff` (голубой)
- Акцент (типы данных) — `#8bdc8b` (зелёный)
- Приглушённый — `#888`

**Структура файла:**
1. Общие селекторы (`body`, `.nav`, `h1/2/3`, `.dim`)
2. Секция `/* -- main.php */` — специфичные правила
3. Секция `/* -- tests.php */` — специфичные правила

### Инструмент

Форматирование ручное. Файл небольшой, автоформаттер не оправдан.

---

## .editorconfig

Единый файл для редакторов (VSCode, JetBrains и др.) — кладётся в корень проекта:

```ini
root = true

[*]
charset = utf-8
end_of_line = lf
insert_final_newline = true
trim_trailing_whitespace = true

[*.php]
indent_style = space
indent_size = 2

[*.sql]
indent_style = space
indent_size = 2

[*.nix]
indent_style = space
indent_size = 2

[*.py]
indent_style = space
indent_size = 4

[*.css]
indent_style = space
indent_size = 2
```

---

## Интеграция с Nix devShell

Форматтеры можно добавить в `devShells.default` в [flake.nix](flake.nix):

```nix
buildInputs = [
  server pkgs.postgresql pgschema db-tester
  pkgs.nixfmt-rfc-style   # nix
  pkgs.pgformatter        # sql
  pkgs.ruff               # python
] ++ modules;
```

После этого `nixfmt`, `pg_format`, `ruff` доступны в `nix develop` без установки.
