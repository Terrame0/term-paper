<?php

// -- db connection
$host = getenv('PGHOST');
$user = getenv('PGUSER');
$db   = getenv('PRODUCTION_DB');

$dsn = "pgsql:host=$host;dbname=$db";

try {
  $pdo = new PDO($dsn, $user, '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
  ]);
} catch (PDOException $e) {
  die("DB connection failed: " . $e->getMessage());
}

// -- query tables
$tables_stmt = $pdo->query("
  SELECT c.relname AS tablename
  FROM pg_class c
  JOIN pg_namespace n ON n.oid = c.relnamespace
  WHERE n.nspname = 'public'
    AND c.relkind = 'r'
  ORDER BY c.relname
");

$tables = $tables_stmt->fetchAll(PDO::FETCH_COLUMN);

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>PG catalog schema viewer</title>

<style>
body {
  font-family: monospace;
  background: #0f1115;
  color: #d6d6d6;
  padding: 20px;
}

.nav {
  margin-bottom: 20px;
}

.nav a {
  color: #7cc7ff;
  margin-right: 16px;
}

.table {
  border: 1px solid #333;
  margin-bottom: 20px;
  padding: 10px;
  border-radius: 6px;
  background: #151922;
}

.table h2 {
  margin: 0 0 10px 0;
  color: #7cc7ff;
}

.col {
  padding: 2px 0;
}

.type {
  color: #8bdc8b;
}

.null {
  color: #888;
}
</style>
</head>

<body>

<div class="nav">
  <a href="/src/main.php">schema</a>
  <a href="/src/tests.php">tests</a>
</div>

<h1>PostgreSQL schema: <?= htmlspecialchars($db) ?></h1>

<?php foreach ($tables as $table): ?>

<div class="table">
  <h2><?= htmlspecialchars($table) ?></h2>

<?php

$cols_stmt = $pdo->prepare("
  SELECT
    a.attname AS column_name,
    t.typname AS data_type,
    a.attnotnull AS not_null
  FROM pg_attribute a
  JOIN pg_class c ON c.oid = a.attrelid
  JOIN pg_type t ON t.oid = a.atttypid
  JOIN pg_namespace n ON n.oid = c.relnamespace
  WHERE c.relname = :table
    AND n.nspname = 'public'
    AND a.attnum > 0
    AND NOT a.attisdropped
  ORDER BY a.attnum
");

$cols_stmt->execute(['table' => $table]);
$cols = $cols_stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<?php foreach ($cols as $col): ?>

  <div class="col">
    <?= htmlspecialchars($col['column_name']) ?>
    :
    <span class="type"><?= htmlspecialchars($col['data_type']) ?></span>
    <span class="null">
      (<?= $col['not_null'] ? 'NOT NULL' : 'NULL' ?>)
    </span>
  </div>

<?php endforeach; ?>

</div>

<?php endforeach; ?>

</body>
</html>
