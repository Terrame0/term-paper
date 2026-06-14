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

// -- scaling data: aggregated per worker_count across all runs
$scaling_stmt = $pdo->query("
  SELECT
    r.worker_count,
    AVG(w.latency_p50_ms)::float AS p50,
    AVG(w.latency_p95_ms)::float AS p95,
    AVG(w.latency_p99_ms)::float AS p99,
    AVG((r.worker_count * r.ops_per_worker * 1000.0)
        / NULLIF(r.total_duration_ms, 0))::float AS ops_per_sec
  FROM test_run r
  JOIN test_worker_result w ON w.run_id = r.run_id
  GROUP BY r.worker_count
  ORDER BY r.worker_count
");
$scaling = $scaling_stmt->fetchAll(PDO::FETCH_ASSOC);

// -- worker results for selected run
$workers = [];
if ($selected_run_id !== null) {
  $w_stmt = $pdo->prepare("
    SELECT worker_id, ops_completed,
           latency_p50_ms::float AS p50,
           latency_p95_ms::float AS p95,
           latency_p99_ms::float AS p99,
           errors
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
<script src="/src/assets/chart.js"></script>

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

.charts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
  gap: 24px;
  margin-bottom: 32px;
}

.chart-card {
  background: #151922;
  border: 1px solid #333;
  border-radius: 6px;
  padding: 12px;
}

.chart-card h2 {
  margin: 0 0 8px 0;
  font-size: 14px;
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

<h1>db-tester</h1>

<?php if (count($scaling) < 2): ?>
  <p class="dim">scaling-графики появятся после ≥2 прогонов с <strong>разным</strong> <code>--workers</code>. Сейчас прогонов с уникальным worker_count: <?= count($scaling) ?>.</p>
<?php else: ?>
<div class="charts">
  <div class="chart-card">
    <h2>latency vs concurrency (avg per worker_count)</h2>
    <canvas id="latency-chart"></canvas>
  </div>
  <div class="chart-card">
    <h2>throughput vs concurrency (ops/sec)</h2>
    <canvas id="throughput-chart"></canvas>
  </div>
</div>
<?php endif; ?>

<h2>last 20 runs</h2>

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
  <div class="charts">
    <div class="chart-card">
      <h2>latency per worker</h2>
      <canvas id="worker-chart"></canvas>
    </div>
  </div>
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
      <td><?= number_format($w['p50'], 2) ?></td>
      <td><?= number_format($w['p95'], 2) ?></td>
      <td><?= number_format($w['p99'], 2) ?></td>
      <td><?= (int) $w['errors'] ?></td>
    </tr>
  <?php endforeach; ?>
  </table>
  <?php endif; ?>
<?php endif; ?>

<script>
  // -- dark theme defaults
  Chart.defaults.color = '#d6d6d6';
  Chart.defaults.borderColor = '#333';

  const scaling = <?= json_encode($scaling) ?>;
  const workers = <?= json_encode($workers) ?>;

  if (scaling.length >= 2) {
    new Chart(document.getElementById('latency-chart'), {
      type: 'line',
      data: {
        labels: scaling.map(s => s.worker_count),
        datasets: [
          { label: 'p50', data: scaling.map(s => s.p50), borderColor: '#8bdc8b' },
          { label: 'p95', data: scaling.map(s => s.p95), borderColor: '#dcc78b' },
          { label: 'p99', data: scaling.map(s => s.p99), borderColor: '#dc8b8b' },
        ],
      },
      options: {
        scales: {
          x: { title: { display: true, text: 'workers' } },
          y: { title: { display: true, text: 'latency, ms' }, beginAtZero: true },
        },
      },
    });

    new Chart(document.getElementById('throughput-chart'), {
      type: 'line',
      data: {
        labels: scaling.map(s => s.worker_count),
        datasets: [
          { label: 'ops/sec', data: scaling.map(s => s.ops_per_sec), borderColor: '#7cc7ff' },
        ],
      },
      options: {
        scales: {
          x: { title: { display: true, text: 'workers' } },
          y: { title: { display: true, text: 'ops/sec' }, beginAtZero: true },
        },
      },
    });
  }

  if (workers.length > 0) {
    new Chart(document.getElementById('worker-chart'), {
      type: 'bar',
      data: {
        labels: workers.map(w => 'w' + w.worker_id),
        datasets: [
          { label: 'p50', data: workers.map(w => w.p50), backgroundColor: '#8bdc8b' },
          { label: 'p95', data: workers.map(w => w.p95), backgroundColor: '#dcc78b' },
          { label: 'p99', data: workers.map(w => w.p99), backgroundColor: '#dc8b8b' },
        ],
      },
      options: {
        scales: {
          x: { title: { display: true, text: 'worker' } },
          y: { title: { display: true, text: 'latency, ms' }, beginAtZero: true },
        },
      },
    });
  }
</script>

</body>
</html>
