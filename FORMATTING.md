# Соглашения по форматированию кода

Проект состоит из трёх языков: PHP, SQL и Nix. Для каждого описаны правила и рекомендуемый инструмент автоформатирования.

---

## PHP

**Файлы:** `php/src/main.php`

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

**Файлы:** `db-ddl.sql`

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
pg_format --inplace --spaces 2 --keyword-case 2 db-ddl.sql
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
```

---

## Интеграция с Nix devShell

Форматтеры можно добавить в `devShells.default` в [flake.nix](flake.nix):

```nix
buildInputs = [
  server pkgs.postgresql pgschema
  pkgs.nixfmt-rfc-style   # nix
  pkgs.pgformatter        # sql
] ++ modules;
```

После этого `nixfmt` и `pg_format` доступны в `nix develop` без установки.
