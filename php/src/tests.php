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

// -- parse postgres array literal '{10,30,90}' -> [10, 30, 90]
function pg_array_to_ints(string $s): array {
  $inner = trim($s, '{}');
  if ($inner === '') return [];
  return array_map('intval', explode(',', $inner));
}

// -- CLI param name -> test_cell column name
$axis_to_col = [
  'workers'        => 'worker_count',
  'ops_per_worker' => 'ops_per_worker',
  'prefill'        => 'prefill_rows',
];

// -- list of tests
$tests_stmt = $pdo->query("
  SELECT test_id, started_at, finished_at, scenario
  FROM test
  ORDER BY test_id DESC
  LIMIT 50
");
$tests = $tests_stmt->fetchAll(PDO::FETCH_ASSOC);

$selected_id = isset($_GET['test_id']) ? (int) $_GET['test_id'] : null;
if ($selected_id === null && !empty($tests)) {
  $selected_id = (int) $tests[0]['test_id'];
}

$selected_test = null;
$sweeps = [];
if ($selected_id !== null) {
  foreach ($tests as $t) {
    if ((int) $t['test_id'] === $selected_id) {
      $selected_test = $t;
      break;
    }
  }
  $s_stmt = $pdo->prepare("
    SELECT sweep_id, x_axis, y_axis, x_values, y_values,
           fixed_param, fixed_value
    FROM test_sweep
    WHERE test_id = :tid
    ORDER BY sweep_id
  ");
  $s_stmt->execute(['tid' => $selected_id]);
  $sweeps = $s_stmt->fetchAll(PDO::FETCH_ASSOC);

  $c_stmt = $pdo->prepare("
    SELECT worker_count, ops_per_worker, prefill_rows,
           latency_p50_ms::float AS p50,
           latency_p95_ms::float AS p95
    FROM test_cell
    WHERE sweep_id = :sid
  ");
  foreach ($sweeps as &$s) {
    $s['x_values'] = pg_array_to_ints($s['x_values']);
    $s['y_values'] = pg_array_to_ints($s['y_values']);
    $c_stmt->execute(['sid' => $s['sweep_id']]);
    $s['cells'] = $c_stmt->fetchAll(PDO::FETCH_ASSOC);

    // -- build (x,y) -> cell map for this sweep
    $x_col = $axis_to_col[$s['x_axis']] ?? $s['x_axis'];
    $y_col = $axis_to_col[$s['y_axis']] ?? $s['y_axis'];
    $cmap = [];
    foreach ($s['cells'] as $c) {
      $cmap[(int) $c[$x_col] . ',' . (int) $c[$y_col]] = $c;
    }
    $s['cell_map'] = $cmap;
  }
  unset($s);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>db-tester</title>
<link rel="stylesheet" href="/src/style.css">
</head>

<body>

<div class="nav">
  <a href="/src/main.php">schema</a>
  <a href="/src/tests.php">tests</a>
</div>

<h1>db-tester</h1>

<?php if (empty($tests)): ?>
  <p class="dim">no tests yet — run
    <code>db-tester --scenario basic --workers 5-2-4 --ops-per-worker 50-2-4 --prefill 100-10-3</code>
  </p>
<?php else: ?>

<form class="controls" method="get">
  <label for="test_id">test:</label>
  <select name="test_id" id="test_id" onchange="this.form.submit()">
    <?php foreach ($tests as $t): ?>
      <option value="<?= (int) $t['test_id'] ?>" <?= (int) $t['test_id'] === $selected_id ? 'selected' : '' ?>>
        #<?= (int) $t['test_id'] ?>
        — <?= htmlspecialchars($t['scenario']) ?>
        — <?= htmlspecialchars($t['started_at']) ?>
      </option>
    <?php endforeach; ?>
  </select>
</form>

<?php if ($selected_test !== null): ?>

<div class="test-meta">
  <div><span class="k">scenario:</span> <?= htmlspecialchars($selected_test['scenario']) ?></div>
  <div><span class="k">started:</span> <?= htmlspecialchars($selected_test['started_at']) ?></div>
  <div><span class="k">finished:</span> <?= htmlspecialchars($selected_test['finished_at']) ?></div>
  <div><span class="k">sweeps:</span> <?= count($sweeps) ?></div>
</div>

<?php

// -- global p95 min/max across all sweeps (shared color scale)
$all_p95 = [];
foreach ($sweeps as $sw) {
  foreach ($sw['cells'] as $c) {
    if ($c['p95'] !== null) $all_p95[] = (float) $c['p95'];
  }
}
$g_min = !empty($all_p95) ? max(min($all_p95), 0.01) : 0.01;
$g_max = !empty($all_p95) ? max(max($all_p95), $g_min * 1.01) : 1.0;
$log_min = log($g_min);
$log_span = log($g_max) - $log_min;

function color_for(float $v, float $log_min, float $log_span): string {
  $t = $log_span > 0 ? (log(max($v, 0.01)) - $log_min) / $log_span : 0.0;
  $t = max(0.0, min(1.0, $t));
  $hue = (1.0 - $t) * 120.0;
  return "hsl($hue, 70%, 28%)";
}

function render_heatmap(array $sweep, float $log_min, float $log_span): void {
  echo '<div class="heatmap-card">';
  echo '<table class="heatmap combo">';

  echo '<tr>';
  echo '<th class="axis-label">' . htmlspecialchars($sweep['y_axis'])
       . ' \ ' . htmlspecialchars($sweep['x_axis']) . '</th>';
  foreach ($sweep['x_values'] as $xv) {
    echo '<th>' . (int) $xv . '</th>';
  }
  echo '</tr>';

  foreach ($sweep['y_values'] as $yv) {
    echo '<tr>';
    echo '<td class="label">' . (int) $yv . '</td>';
    foreach ($sweep['x_values'] as $xv) {
      $key = "$xv,$yv";
      if (!isset($sweep['cell_map'][$key])) {
        echo '<td class="missing">·</td>';
        continue;
      }
      $cell = $sweep['cell_map'][$key];
      $p50 = $cell['p50'] !== null ? (float) $cell['p50'] : null;
      $p95 = $cell['p95'] !== null ? (float) $cell['p95'] : null;
      if ($p95 === null) {
        echo '<td class="missing">·</td>';
        continue;
      }
      $bg = color_for($p95, $log_min, $log_span);
      echo '<td style="background:' . $bg . ';color:#fff">';
      echo '<div class="p95">' . number_format($p95, 1) . '</div>';
      if ($p50 !== null) {
        echo '<div class="p50">' . number_format($p50, 1) . '</div>';
      }
      echo '</td>';
    }
    echo '</tr>';
  }

  echo '</table>';
  echo '</div>';
}

?>

<?php if (empty($sweeps)): ?>
  <p class="dim">test has no sweeps</p>
<?php else: ?>
  <div class="legend">
    <span>p95 colour scale (log):</span>
    <span class="scale-bar">
      <span style="background: <?= color_for($g_min, $log_min, $log_span) ?>;"></span>
      <span style="background: <?= color_for(sqrt($g_min * $g_max), $log_min, $log_span) ?>;"></span>
      <span style="background: <?= color_for($g_max, $log_min, $log_span) ?>;"></span>
    </span>
    <span><?= number_format($g_min, 2) ?> ms → <?= number_format($g_max, 2) ?> ms</span>
    <span class="dim">·  each cell: <b>p95</b> on top, p50 below</span>
  </div>
  <?php foreach ($sweeps as $sweep): ?>
    <div class="sweep-block">
      <h2>
        <?= htmlspecialchars($sweep['x_axis']) ?> ×
        <?= htmlspecialchars($sweep['y_axis']) ?>
        <span class="dim">
          (<?= htmlspecialchars($sweep['fixed_param']) ?>
          = <?= (int) $sweep['fixed_value'] ?>)
        </span>
      </h2>
      <?php render_heatmap($sweep, $log_min, $log_span); ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php endif; ?>
<?php endif; ?>

</body>
</html>
