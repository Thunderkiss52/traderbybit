#!/usr/bin/env python3
"""
Captures raw PostgreSQL queries and their actual executed results against the test database.
Implements strict fail-closed validation:
- Fails closed if query exit code != 0 or stderr contains error
- Strips trailing semicolons to prevent JSON subquery syntax errors
- Validates actual JSON aggregate decoding
- Validates query result cardinality (min/max expected rows)
- Validates non-null values for mandatory columns
- Records raw stdout, raw stderr, exit code, execution duration, and row count
- Redacts database credentials and sensitive secrets
Saves raw evidence to reports/evidence/postgres_queries_and_results.json.
"""

import json
import os
import subprocess
import time

PROJECT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

QUERIES = [
    {
        "query_id": "Q01_PAPER_ACCOUNTS_INSPECTION",
        "description": "Inspect all primary paper accounts, verifying 8 isolated accounts and their balances",
        "sql": """
            SELECT id, name, strategy_id, strategy_version, starting_balance, current_equity, peak_equity, realized_pnl, status
            FROM paper_accounts
            ORDER BY name;
        """,
        "min_rows": 8,
        "max_rows": 10,
        "required_non_null_fields": ["id", "name", "strategy_id", "starting_balance", "current_equity", "status"],
    },
    {
        "query_id": "Q02_RECONCILIATION_RUNS_LATEST",
        "description": "Verify latest ledger reconciliation runs and discrepancy status across accounts",
        "sql": """
            SELECT r.id, r.paper_account_id, a.name AS account_name, r.status, r.recalculated_balance, r.stored_balance,
                   r.recalculated_equity, r.stored_equity, r.discrepancy_count, r.completed_at
            FROM reconciliation_runs r
            JOIN paper_accounts a ON r.paper_account_id = a.id
            ORDER BY r.completed_at DESC
            LIMIT 8;
        """,
        "min_rows": 8,
        "max_rows": 8,
        "required_non_null_fields": ["id", "paper_account_id", "account_name", "status", "recalculated_balance", "recalculated_equity", "discrepancy_count"],
    },
    {
        "query_id": "Q03_COUNTERFACTUAL_SLOT_INTEGRITY",
        "description": "Inspect canonical vs superseded counterfactual evaluations count and slot uniqueness",
        "sql": """
            SELECT 
                COUNT(*) AS total_rows,
                COUNT(CASE WHEN superseded_by IS NULL THEN 1 END) AS canonical_rows,
                COUNT(CASE WHEN superseded_by IS NOT NULL THEN 1 END) AS superseded_rows,
                COUNT(DISTINCT (strategy_id || '|' || strategy_version || '|' || symbol || '|' || timeframe || '|' || decision_time::text)) AS distinct_slots
            FROM counterfactual_evaluations;
        """,
        "min_rows": 1,
        "max_rows": 1,
        "required_non_null_fields": ["total_rows", "canonical_rows", "superseded_rows", "distinct_slots"],
    },
    {
        "query_id": "Q04_STRATEGY_OBSERVATIONS_SUMMARY",
        "description": "Strategy observations distribution by strategy_id, side, and quality class",
        "sql": """
            SELECT strategy_id, side, quality_class, COUNT(*) AS count
            FROM strategy_observations
            GROUP BY strategy_id, side, quality_class
            ORDER BY strategy_id, side;
        """,
        "min_rows": 5,
        "required_non_null_fields": ["strategy_id", "side", "count"],
    },
    {
        "query_id": "Q05_RISK_DECISIONS_DISTRIBUTION",
        "description": "Risk decisions distribution by paper_account_id, allowed flag, and risk_version",
        "sql": """
            SELECT paper_account_id, allowed, risk_version, COUNT(*) AS count
            FROM risk_decisions
            GROUP BY paper_account_id, allowed, risk_version
            ORDER BY paper_account_id, allowed;
        """,
        "min_rows": 1,
        "required_non_null_fields": ["paper_account_id", "allowed", "risk_version", "count"],
    },
    {
        "query_id": "Q06_ML_MODELS_GATE6_STATUS",
        "description": "Inspect registered ML model artifacts, statuses, and Gate 6 validation flags",
        "sql": """
            SELECT id, name, model_type, version, status,
                   parameters->>'gate6_validation' AS gate6_validation_raw,
                   parameters->>'approved_for_advisory' AS approved_for_advisory,
                   created_at
            FROM learning_model_artifacts
            ORDER BY created_at DESC;
        """,
        "min_rows": 1,
        "required_non_null_fields": ["id", "name", "model_type", "version", "status", "created_at"],
    },
    {
        "query_id": "Q07_SCHEMA_CONSTRAINTS_AND_INDEXES",
        "description": "Verify presence of partial unique indexes enforcing slot idempotency and account uniqueness",
        "sql": """
            SELECT schemaname, tablename, indexname, indexdef
            FROM pg_indexes
            WHERE tablename IN ('counterfactual_evaluations', 'strategy_observations', 'risk_decisions')
              AND indexname LIKE '%unique%'
            ORDER BY tablename, indexname;
        """,
        "min_rows": 4,
        "required_non_null_fields": ["schemaname", "tablename", "indexname", "indexdef"],
    },
]

def run_psql_json(q):
    clean_sql = q["sql"].strip().rstrip(";")
    wrapped_sql = f"SELECT json_agg(t) FROM ({clean_sql}) t;"
    cmd = [
        "docker", "exec", "-i", "bybit-test-pg",
        "psql", "-U", "postgres", "-d", "bybit_test",
        "-t", "-A", "-c", wrapped_sql
    ]
    
    exec_start = time.time()
    p = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    duration_ms = round((time.time() - exec_start) * 1000, 2)
    
    # 1. Fail-closed on process exit code
    if p.returncode != 0:
        raise RuntimeError(
            f"FAIL-CLOSED: Query {q['query_id']} failed with exit code {p.returncode}: {p.stderr.strip()}"
        )
    
    raw_stdout = p.stdout.strip()
    raw_stderr = p.stderr.strip()
    
    # 2. Fail-closed on error keywords in stderr
    if "ERROR" in raw_stderr.upper() or "FATAL" in raw_stderr.upper():
        raise RuntimeError(
            f"FAIL-CLOSED: Query {q['query_id']} encountered error in stderr: {raw_stderr}"
        )
    
    # 3. Parse JSON aggregate
    if not raw_stdout or raw_stdout == "" or raw_stdout == "null":
        rows = []
    else:
        try:
            rows = json.loads(raw_stdout)
        except Exception as e:
            raise RuntimeError(
                f"FAIL-CLOSED: Query {q['query_id']} returned invalid JSON: {e}. Output: {raw_stdout}"
            )
    
    # 4. Validate cardinality constraints
    min_rows = q.get("min_rows", 1)
    max_rows = q.get("max_rows", None)
    if len(rows) < min_rows:
        raise RuntimeError(
            f"FAIL-CLOSED: Query {q['query_id']} returned {len(rows)} rows, minimum required is {min_rows}"
        )
    if max_rows is not None and len(rows) > max_rows:
        raise RuntimeError(
            f"FAIL-CLOSED: Query {q['query_id']} returned {len(rows)} rows, maximum allowed is {max_rows}"
        )
    
    # 5. Validate mandatory non-null fields
    for field in q.get("required_non_null_fields", []):
        for idx, row in enumerate(rows):
            if row.get(field) is None:
                raise RuntimeError(
                    f"FAIL-CLOSED: Query {q['query_id']} row {idx} has NULL for required field '{field}'"
                )
    
    return {
        "exit_code": p.returncode,
        "raw_stdout": raw_stdout,
        "raw_stderr": raw_stderr if raw_stderr else None,
        "rows": rows,
        "row_count": len(rows),
        "duration_ms": duration_ms,
    }

def main():
    print("=== Capturing Raw PostgreSQL Queries and Results (Strict Fail-Closed) ===")
    results = []

    for q in QUERIES:
        print(f"Executing [{q['query_id']}] {q['description']}...")
        res = run_psql_json(q)
        print(f"  -> SUCCESS: {res['row_count']} rows in {res['duration_ms']} ms")

        # Clean sanitized SQL representation
        clean_sql = " ".join(q["sql"].split())

        results.append({
            "query_id": q["query_id"],
            "description": q["description"],
            "sql": clean_sql,
            "exit_code": res["exit_code"],
            "duration_ms": res["duration_ms"],
            "row_count": res["row_count"],
            "rows": res["rows"],
            "raw_stdout": res["raw_stdout"],
            "raw_stderr": res["raw_stderr"],
            "validation": {
                "cardinality_min": q.get("min_rows"),
                "cardinality_max": q.get("max_rows"),
                "required_non_null_fields": q.get("required_non_null_fields", []),
                "status": "PASSED_STRICT_VALIDATION"
            }
        })

    output_path = os.path.join(PROJECT_ROOT, "reports/evidence/postgres_queries_and_results.json")
    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    with open(output_path, "w", encoding="utf-8") as f:
        json.dump({
            "captured_at_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "database": "bybit_test",
            "host": "127.0.0.1",
            "port": 25432,
            "credentials": "[REDACTED]",
            "validation_policy": "STRICT_FAIL_CLOSED_VALIDATION",
            "queries": results,
        }, f, indent=2)

    print(f"\n[OK] Raw query evidence saved to: {output_path}")

if __name__ == "__main__":
    main()
