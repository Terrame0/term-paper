CREATE TABLE test (
  test_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  started_at TIMESTAMP NOT NULL,
  finished_at TIMESTAMP NOT NULL,
  scenario VARCHAR(50) NOT NULL
);

CREATE TABLE test_sweep (
  sweep_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  test_id INT NOT NULL,
  x_axis VARCHAR(20) NOT NULL,
  y_axis VARCHAR(20) NOT NULL,
  x_values INT[] NOT NULL,
  y_values INT[] NOT NULL,
  fixed_param VARCHAR(20) NOT NULL,
  fixed_value INT NOT NULL,
  FOREIGN KEY (test_id) REFERENCES test(test_id)
);

CREATE TABLE test_cell (
  cell_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  sweep_id INT NOT NULL,
  worker_count INT NOT NULL,
  ops_per_worker INT NOT NULL,
  prefill_rows INT NOT NULL,
  latency_p50_ms NUMERIC(10,2),
  latency_p95_ms NUMERIC(10,2),
  FOREIGN KEY (sweep_id) REFERENCES test_sweep(sweep_id)
);
