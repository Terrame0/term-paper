from dataclasses import dataclass


@dataclass(frozen=True)
class Op:
    name: str
    weight: int
    sql: str


SCENARIOS: dict[str, list[Op]] = {
    "basic": [
        Op(
            "insert_client",
            1,
            """
            INSERT INTO client (name, registration_time, is_legal_entity)
            VALUES ('test-' || gen_random_uuid()::text, NOW(), false)
            """,
        ),
        Op(
            "select_random_client",
            3,
            "SELECT * FROM client ORDER BY RANDOM() LIMIT 1",
        ),
        Op(
            "join_client_promotion",
            2,
            """
            SELECT c.client_id, c.name, p.name, p.cost
            FROM client c
            LEFT JOIN promotion p ON p.client_id = c.client_id
            ORDER BY RANDOM() LIMIT 10
            """,
        ),
    ],
}
