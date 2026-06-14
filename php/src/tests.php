<?php

// -- db connection
$host = getenv('PGHOST');
$user = getenv('PGUSER');
$db   = getenv('TEST_DB');

$dsn = "pgsql:host=$host;dbname=$db";

try {
  $pdo = new PDO($dsn, $user, '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
  ]);
} catch (PDOException $e) {
  die("DB connection failed: " . $e->getMessage());
}

// -- selected run (from ?run_id=N)
$selected_run_id = isset($_GET['run_id']) ? (int) $_GET['run_id'] : null;

// -- last 20 runs
$runs_stmt = $pdo->query("
  SELECT run_id, started_at, finished_at, worker_count,
         ops_per_worker, total_duration_ms, errors_count
  FROM test_run
  ORDER BY run_id DESC
  LIMIT 20
");
$runs = $runs_stmt->fetchAll(PDO::FETCH_ASSOC);

// -- worker results for selected run
$workers = [];
if ($selected_run_id !== null) {
  $w_stmt = $pdo->prepare("
    SELECT worker_id, ops_completed,
           latency_p50_ms, latency_p95_ms, latency_p99_ms, errors
    FROM test_worker_result
    WHERE run_id = :run_id
    ORDER BY worker_id
  ");
  $w_stmt->execute(['run_id' => $selected_run_id]);
  $workers = $w_stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>db-tester runs</title>

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

h1, h2 {
  color: #7cc7ff;
}

table {
  border-collapse: collapse;
  margin-bottom: 24px;
}

th, td {
  border: 1px solid #333;
  padding: 6px 12px;
  text-align: left;
}

th {
  background: #151922;
  color: #8bdc8b;
}

tr.selected {
  background: #1f2630;
}

a {
  color: #7cc7ff;
}

.dim {
  color: #888;
}
</style>
</head>

<body>

<div class="nav">
  <a href="/src/main.php">schema</a>
  <a href="/src/tests.php">tests</a>
</div>

<h1>db-tester: last 20 runs</h1>

<?php if (empty($runs)): ?>
  <p class="dim">no runs yet — run <code>db-tester --workers N --ops-per-worker M --scenario basic</code></p>
<?php else: ?>
<table>
  <tr>
    <th>run</th>
    <th>started</th>
    <th>workers</th>
    <th>ops/worker</th>
    <th>total ops</th>
    <th>duration ms</th>
    <th>errors</th>
  </tr>
<?php foreach ($runs as $r): ?>
  <tr class="<?= $selected_run_id === (int) $r['run_id'] ? 'selected' : '' ?>">
    <td><a href="?run_id=<?= (int) $r['run_id'] ?>">#<?= (int) $r['run_id'] ?></a></td>
    <td><?= htmlspecialchars($r['started_at']) ?></td>
    <td><?= (int) $r['worker_count'] ?></td>
    <td><?= (int) $r['ops_per_worker'] ?></td>
    <td><?= (int) $r['worker_count'] * (int) $r['ops_per_worker'] ?></td>
    <td><?= (int) $r['total_duration_ms'] ?></td>
    <td><?= (int) $r['errors_count'] ?></td>
  </tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<?php if ($selected_run_id !== null): ?>
  <h2>run #<?= (int) $selected_run_id ?>: per-worker</h2>
  <?php if (empty($workers)): ?>
    <p class="dim">no worker results for this run</p>
  <?php else: ?>
  <table>
    <tr>
      <th>worker</th>
      <th>ops</th>
      <th>p50 ms</th>
      <th>p95 ms</th>
      <th>p99 ms</th>
      <th>errors</th>
    </tr>
  <?php foreach ($workers as $w): ?>
    <tr>
      <td><?= (int) $w['worker_id'] ?></td>
      <td><?= (int) $w['ops_completed'] ?></td>
      <td><?= htmlspecialchars($w['latency_p50_ms']) ?></td>
      <td><?= htmlspecialchars($w['latency_p95_ms']) ?></td>
      <td><?= htmlspecialchars($w['latency_p99_ms']) ?></td>
      <td><?= (int) $w['errors'] ?></td>
    </tr>
  <?php endforeach; ?>
  </table>
  <?php endif; ?>
<?php endif; ?>

</body>
</html>
