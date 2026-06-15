import argparse
import asyncio
import os
import random
import time
from dataclasses import dataclass
from datetime import datetime
from itertools import combinations

import asyncpg

from scenarios import SCENARIOS, Op


PARAM_NAMES = ("workers", "ops_per_worker", "prefill")


def percentile(values: list[float], p: float) -> float:
    if not values:
        return 0.0
    s = sorted(values)
    k = (len(s) - 1) * p
    f = int(k)
    c = min(f + 1, len(s) - 1)
    return s[f] + (s[c] - s[f]) * (k - f)


def parse_range(spec: str) -> list[int]:
    """`N` -> [N]; `base-mult-count` -> geometric progression."""
    parts = spec.split("-")
    if len(parts) == 1:
        return [int(parts[0])]
    if len(parts) == 3:
        base, mult, count = int(parts[0]), int(parts[1]), int(parts[2])
        if count <= 0:
            raise ValueError(f"count must be positive in '{spec}'")
        out: list[int] = []
        v = base
        for _ in range(count):
            out.append(v)
            v *= mult
        return out
    raise ValueError(
        f"bad range '{spec}': expected 'N' or 'base-mult-count'"
    )


@dataclass
class CellResult:
    worker_count: int
    ops_per_worker: int
    prefill_rows: int
    p50: float
    p95: float


def dsn(db: str) -> str:
    host = os.environ["PGHOST"]
    user = os.environ["PGUSER"]
    return f"postgresql://{user}@/{db}?host={host}"


async def reset_and_prefill(prod_dsn: str, rows: int) -> None:
    conn = await asyncpg.connect(prod_dsn)
    try:
        await conn.execute("TRUNCATE client CASCADE")
        if rows > 0:
            await conn.execute(
                """
                INSERT INTO client (name, registration_time, is_legal_entity)
                SELECT 'pre-' || gs::text, NOW(), false
                FROM generate_series(1, $1) gs
                """,
                rows,
            )
            await conn.execute(
                """
                INSERT INTO promotion
                    (client_id, name, cost, start_time, duration)
                SELECT
                    client_id,
                    'promo-' || client_id,
                    (random() * 1000)::int,
                    NOW(),
                    30
                FROM client
                """
            )
    finally:
        await conn.close()


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


async def run_cell(
    prod_dsn: str,
    scenario: list[Op],
    workers: int,
    ops: int,
    prefill: int,
) -> CellResult:
    await reset_and_prefill(prod_dsn, prefill)
    results = await asyncio.gather(
        *[worker(ops, scenario, prod_dsn) for _ in range(workers)]
    )
    all_latencies: list[float] = []
    for lats in results:
        all_latencies.extend(lats)
    return CellResult(
        worker_count=workers,
        ops_per_worker=ops,
        prefill_rows=prefill,
        p50=percentile(all_latencies, 0.50),
        p95=percentile(all_latencies, 0.95),
    )


async def insert_test(
    test_dsn: str, started: datetime, scenario_name: str,
) -> int:
    conn = await asyncpg.connect(test_dsn)
    try:
        return await conn.fetchval(
            """
            INSERT INTO test (started_at, finished_at, scenario)
            VALUES ($1, $1, $2)
            RETURNING test_id
            """,
            started, scenario_name,
        )
    finally:
        await conn.close()


async def finalize_test(test_dsn: str, test_id: int) -> None:
    conn = await asyncpg.connect(test_dsn)
    try:
        await conn.execute(
            "UPDATE test SET finished_at = $1 WHERE test_id = $2",
            datetime.now(), test_id,
        )
    finally:
        await conn.close()


async def insert_sweep(
    test_dsn: str,
    test_id: int,
    x_axis: str,
    y_axis: str,
    x_values: list[int],
    y_values: list[int],
    fixed_param: str,
    fixed_value: int,
) -> int:
    conn = await asyncpg.connect(test_dsn)
    try:
        return await conn.fetchval(
            """
            INSERT INTO test_sweep
                (test_id, x_axis, y_axis, x_values, y_values,
                 fixed_param, fixed_value)
            VALUES ($1, $2, $3, $4, $5, $6, $7)
            RETURNING sweep_id
            """,
            test_id, x_axis, y_axis, x_values, y_values,
            fixed_param, fixed_value,
        )
    finally:
        await conn.close()


async def insert_cell(
    test_dsn: str, sweep_id: int, r: CellResult,
) -> None:
    conn = await asyncpg.connect(test_dsn)
    try:
        await conn.execute(
            """
            INSERT INTO test_cell
                (sweep_id, worker_count, ops_per_worker, prefill_rows,
                 latency_p50_ms, latency_p95_ms)
            VALUES ($1, $2, $3, $4, $5, $6)
            """,
            sweep_id, r.worker_count, r.ops_per_worker, r.prefill_rows,
            r.p50, r.p95,
        )
    finally:
        await conn.close()


async def run_sweep(
    test_dsn: str,
    prod_dsn: str,
    scenario: list[Op],
    test_id: int,
    x_axis: str,
    y_axis: str,
    ranges: dict[str, list[int]],
) -> None:
    fixed_param = next(n for n in PARAM_NAMES if n != x_axis and n != y_axis)
    fixed_value = max(ranges[fixed_param])
    x_values = ranges[x_axis]
    y_values = ranges[y_axis]

    sweep_id = await insert_sweep(
        test_dsn, test_id, x_axis, y_axis, x_values, y_values,
        fixed_param, fixed_value,
    )
    print(
        f"  sweep #{sweep_id}: {x_axis}={x_values} x {y_axis}={y_values}, "
        f"{fixed_param}={fixed_value}"
    )

    for xv in x_values:
        for yv in y_values:
            params = {fixed_param: fixed_value, x_axis: xv, y_axis: yv}
            cell = await run_cell(
                prod_dsn, scenario,
                workers=params["workers"],
                ops=params["ops_per_worker"],
                prefill=params["prefill"],
            )
            await insert_cell(test_dsn, sweep_id, cell)
            print(
                f"    cell w={cell.worker_count} "
                f"ops={cell.ops_per_worker} prefill={cell.prefill_rows}: "
                f"p50={cell.p50:.1f}ms p95={cell.p95:.1f}ms"
            )


async def run(
    workers_spec: str,
    ops_spec: str,
    prefill_spec: str,
    scenario_name: str,
) -> None:
    if scenario_name not in SCENARIOS:
        raise SystemExit(
            f"unknown scenario '{scenario_name}', "
            f"available: {list(SCENARIOS)}"
        )
    scenario = SCENARIOS[scenario_name]

    ranges = {
        "workers": parse_range(workers_spec),
        "ops_per_worker": parse_range(ops_spec),
        "prefill": parse_range(prefill_spec),
    }
    varied = [name for name in PARAM_NAMES if len(ranges[name]) > 1]
    if len(varied) < 2:
        raise SystemExit(
            f"need at least 2 varied params (ranges), got {len(varied)}: "
            f"{varied}. Provide ≥2 as 'base-mult-count'."
        )

    if len(varied) == 2:
        pairs = [tuple(varied)]
    else:
        pairs = list(combinations(varied, 2))

    prod_dsn = dsn(os.environ["PRODUCTION_DB"])
    test_dsn = dsn(os.environ["TEST_DB"])

    started = datetime.now()
    test_id = await insert_test(test_dsn, started, scenario_name)
    print(f"test #{test_id}: scenario={scenario_name}, sweeps={len(pairs)}")

    for x_axis, y_axis in pairs:
        await run_sweep(
            test_dsn, prod_dsn, scenario, test_id, x_axis, y_axis, ranges,
        )

    await finalize_test(test_dsn, test_id)
    print(f"test #{test_id} done")


def main() -> None:
    p = argparse.ArgumentParser(
        prog="db-tester",
        description="concurrent postgres load tester (sweeps)",
    )
    p.add_argument("--workers", type=str, required=True)
    p.add_argument("--ops-per-worker", type=str, required=True)
    p.add_argument("--prefill", type=str, required=True)
    p.add_argument("--scenario", type=str, required=True)
    args = p.parse_args()
    asyncio.run(
        run(args.workers, args.ops_per_worker, args.prefill, args.scenario)
    )


if __name__ == "__main__":
    main()
