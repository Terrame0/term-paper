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
