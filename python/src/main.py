import argparse
import asyncio
import os
import random
import time
from dataclasses import dataclass
from datetime import datetime

import asyncpg

from scenarios import SCENARIOS, Op


def percentile(values: list[float], p: float) -> float:
    if not values:
        return 0.0
    s = sorted(values)
    k = (len(s) - 1) * p
    f = int(k)
    c = min(f + 1, len(s) - 1)
    return s[f] + (s[c] - s[f]) * (k - f)


@dataclass
class WorkerResult:
    worker_id: int
    ops_completed: int
    latencies_ms: list[float]
    errors: int


def dsn(db: str) -> str:
    host = os.environ["PGHOST"]
    user = os.environ["PGUSER"]
    return f"postgresql://{user}@/{db}?host={host}"


async def cleanup_production(prod_dsn: str) -> None:
    conn = await asyncpg.connect(prod_dsn)
    try:
        await conn.execute("TRUNCATE client CASCADE")
    finally:
        await conn.close()


async def worker(
    worker_id: int,
    ops: int,
    scenario: list[Op],
    prod_dsn: str,
) -> WorkerResult:
    conn = await asyncpg.connect(prod_dsn, command_timeout=5)
    weights = [op.weight for op in scenario]
    latencies: list[float] = []
    errors = 0
    try:
        for _ in range(ops):
            op = random.choices(scenario, weights=weights)[0]
            t0 = time.perf_counter()
            try:
                await conn.execute(op.sql)
            except asyncpg.exceptions.PostgresError:
                errors += 1
            except asyncio.TimeoutError:
                errors += 1
            latencies.append((time.perf_counter() - t0) * 1000)
    finally:
        await conn.close()
    return WorkerResult(worker_id, len(latencies), latencies, errors)


async def write_results(
    test_dsn: str,
    started: datetime,
    finished: datetime,
    worker_count: int,
    ops_per_worker: int,
    duration_ms: int,
    results: list[WorkerResult],
) -> int:
    conn = await asyncpg.connect(test_dsn)
    try:
        errors_total = sum(r.errors for r in results)
        run_id = await conn.fetchval(
            """
            INSERT INTO test_run
                (started_at, finished_at, worker_count, ops_per_worker,
                 total_duration_ms, errors_count)
            VALUES ($1, $2, $3, $4, $5, $6)
            RETURNING run_id
            """,
            started, finished, worker_count, ops_per_worker,
            duration_ms, errors_total,
        )
        for r in results:
            await conn.execute(
                """
                INSERT INTO test_worker_result
                    (run_id, worker_id, ops_completed,
                     latency_p50_ms, latency_p95_ms, latency_p99_ms, errors)
                VALUES ($1, $2, $3, $4, $5, $6, $7)
                """,
                run_id, r.worker_id, r.ops_completed,
                percentile(r.latencies_ms, 0.50),
                percentile(r.latencies_ms, 0.95),
                percentile(r.latencies_ms, 0.99),
                r.errors,
            )
        return run_id
    finally:
        await conn.close()


async def run(workers: int, ops: int, scenario_name: str) -> None:
    if scenario_name not in SCENARIOS:
        raise SystemExit(
            f"unknown scenario '{scenario_name}', "
            f"available: {list(SCENARIOS)}"
        )
    scenario = SCENARIOS[scenario_name]
    prod_dsn = dsn(os.environ["PRODUCTION_DB"])
    test_dsn = dsn(os.environ["TEST_DB"])

    await cleanup_production(prod_dsn)

    started = datetime.now()
    t0 = time.perf_counter()
    results = await asyncio.gather(
        *[worker(i, ops, scenario, prod_dsn) for i in range(workers)]
    )
    duration_ms = int((time.perf_counter() - t0) * 1000)
    finished = datetime.now()

    run_id = await write_results(
        test_dsn, started, finished, workers, ops, duration_ms, results,
    )
    total_ops = sum(r.ops_completed for r in results)
    errors_total = sum(r.errors for r in results)
    print(
        f"run #{run_id}: {workers} workers, {total_ops} ops, "
        f"{duration_ms}ms, {errors_total} errors"
    )


def main() -> None:
    p = argparse.ArgumentParser(prog="db-tester")
    p.add_argument("--workers", type=int, required=True)
    p.add_argument("--ops-per-worker", type=int, required=True)
    p.add_argument("--scenario", type=str, required=True)
    args = p.parse_args()
    asyncio.run(run(args.workers, args.ops_per_worker, args.scenario))


if __name__ == "__main__":
    main()
