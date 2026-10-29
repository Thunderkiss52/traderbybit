# Phase 0 Production Evidence Closure Package (2026-10-29)

Status: **PREPARED, NOT EXECUTED IN PRODUCTION**

This package closes the evidence-collection gap only. It does not promote any
Phase 0 obligation, does not alter production data, and does not authorize a
deployment. Every one of the 45 `VEC-P0-*-V2` obligations remains **PENDING**
until the operator reviews authentic evidence for the release under acceptance.

## 1. Scope and safety invariants

- Read-only: every database query is a `SELECT` inside
  `BEGIN TRANSACTION READ ONLY` and ends with `ROLLBACK`.
- Bounded: `statement_timeout=30s`, `lock_timeout=5s`,
  `idle_in_transaction_session_timeout=60s`.
- Row exports are deterministic and paginated with `LIMIT 100 OFFSET`.
- No `INSERT`, `UPDATE`, `DELETE`, DDL, backfill, reconciliation, replay of
  pipeline events, account reset, or `funding_complete` mutation is included.
- No audit-export `POST`, kill-switch change, promotion, or other write API is
  included. `VEC-P0-107-V2` cannot be closed by this read-only handoff.
- No API key, password, token, or private key is requested or emitted.
- ECONOM and unrelated VPS resources are untouched.

Source baseline: local `main` at `54cea59` plus the evidence-closure changes in
`scripts/diagnostics/phase0_evidence_closure.sql` and its regression test.
This is not the deployed SHA. The operator must record the actual deployed SHA.

## 2. What the new SQL adds

`scripts/diagnostics/phase0_evidence_closure.sql` supplements, but does not
replace, `phase0_production_acceptance.sql`:

| Query | Evidence | Supported obligations |
| --- | --- | --- |
| 17 | Exact 26-account identity census, missing/duplicate detection | VEC-P0-001-V2, VEC-P0-109-V2 |
| 18 | Per-account bootstrap capital and opening-snapshot provenance | VEC-P0-112-V2, VEC-P0-208-V2 |
| 19 | Funding completeness and market mark provenance coverage | VEC-P0-003-V2, VEC-P0-203-V2, VEC-P0-207-V2 |
| 20 | Paginated per-position funding evidence for offline classification | VEC-P0-003-V2, VEC-P0-201-V2, VEC-P0-203-V2, VEC-P0-207-V2 |
| 21 | Pipeline anomaly and stuck-event summary | VEC-P0-002-V2, VEC-P0-201-V2, VEC-P0-204-V2, VEC-P0-209-V2 |
| 22 | Paginated row-level STALE/PARTIAL/FAILED lineage evidence | VEC-P0-002-V2, VEC-P0-127-V2, VEC-P0-204-V2, VEC-P0-209-V2 |
| 23 | Both autonomy kill-switch authorities and stored capabilities | VEC-P0-119-V2, VEC-P0-120-V2 |
| 23b | Immutable autonomy approval audit coverage | VEC-P0-126-V2 |
| 24 | Candidate-to-backtest ownership linkage | VEC-P0-125-V2 |
| 25 | Read-only replay candidate identifiers | VEC-P0-101-V2 |
| 26 | Additive schema precondition checks | VEC-P0-007-V2 |
| 27 | Migration, market-data, pipeline and snapshot provenance | VEC-P0-007-V2, VEC-P0-009-V2, VEC-P0-112-V2 |
| 28 | Drawdown circuit breaker enforcement and ongoing position servicing liveness | VEC-P0-004-V2, VEC-P0-127-V2 |
| 28b | Drawdown entry-block bypass audit (verifies zero entries opened post-block) | VEC-P0-004-V2, VEC-P0-127-V2 |

SQL output is necessary but not sufficient. Missing identities, null funding
marks, aggregate-only legacy failures and absent production evidence remain
**PENDING**, not PASS.

## 3. Operator procedure on the production VPS

Run only after the owner-approved workflow in `.github/workflows/deploy.yml`
has deployed the release under acceptance. SSH is for read-only diagnostics.

### 3.1 Capture release identity and artifact hashes

```bash
cd /srv/bybit-signals/current

ARTIFACT_DIR="docs/progress/evidence/phase0-production-acceptance/run-$(date -u +%Y%m%dT%H%M%SZ)-$(git rev-parse --short HEAD)"
mkdir -p "$ARTIFACT_DIR"

{
  echo "=== VECTOR PHASE 0 EVIDENCE CLOSURE START ==="
  date -u +"TIMESTAMP_UTC=%Y-%m-%dT%H:%M:%SZ"
  echo "DEPLOYED_SHA=$(git rev-parse HEAD)"
  sha256sum scripts/diagnostics/phase0_production_acceptance.sql \
            scripts/diagnostics/phase0_evidence_closure.sql
  docker compose ps
} 2>&1 | tee "$ARTIFACT_DIR/release_and_health.log"
```

### 3.2 Run the original hardened suite

Execute via Docker Compose from `/srv/bybit-signals/current`:
```bash
docker compose exec -T postgres psql -X -v ON_ERROR_STOP=1 \
  -U "${POSTGRES_USER:-bybit_signals}" \
  -d "${POSTGRES_DB:-bybit_signals}" \
  < scripts/diagnostics/phase0_production_acceptance.sql \
  2>&1 | tee "$ARTIFACT_DIR/production_diagnostic.log"
echo "EXIT_CODE=${PIPESTATUS[0]}"
```

Or execute directly targeting the container `bybit-signals-postgres-1`:
```bash
docker exec -i bybit-signals-postgres-1 psql -X -v ON_ERROR_STOP=1 \
  -U bybit_signals -d bybit_signals \
  < scripts/diagnostics/phase0_production_acceptance.sql \
  2>&1 | tee "$ARTIFACT_DIR/production_diagnostic.log"
```

### 3.3 Run the closure supplement with pagination

Each invocation includes every summary query. Rerun the page loop while the
funding or pipeline summary reports unresolved rows beyond the last offset.

Via Docker Compose:
```bash
for offset in 0 100 200 300; do
  docker compose exec -T postgres psql -X -v ON_ERROR_STOP=1 \
    -v funding_offset="$offset" -v pipeline_offset="$offset" \
    -U "${POSTGRES_USER:-bybit_signals}" \
    -d "${POSTGRES_DB:-bybit_signals}" \
    < scripts/diagnostics/phase0_evidence_closure.sql \
    > "$ARTIFACT_DIR/closure_offset_${offset}.log" 2>&1
  echo "OFFSET=${offset} EXIT_CODE=$?"
done
```

Or via direct container execution (`bybit-signals-postgres-1`):
```bash
for offset in 0 100 200 300; do
  docker exec -i bybit-signals-postgres-1 psql -X -v ON_ERROR_STOP=1 \
    -v funding_offset="$offset" -v pipeline_offset="$offset" \
    -U bybit_signals -d bybit_signals \
    < scripts/diagnostics/phase0_evidence_closure.sql \
    > "$ARTIFACT_DIR/closure_offset_${offset}.log" 2>&1
  echo "OFFSET=${offset} EXIT_CODE=$?"
done
```

If unresolved rows exceed 400, continue with offsets `400 500 ...` until the
row pages are empty. Do not edit previous logs. Re-run only into new files.

### 3.4 Capture read-only API and log evidence

```bash
for endpoint in \
  "research/health/data-freshness" \
  "research/health/reconciliation-runs" \
  "research/health/slot-integrity" \
  "research/health/strategy-funnel" \
  "research/profitability/summary" \
  "research/profitability/strategies" \
  "research/profitability/concentration-dependence" \
  "research/learning/autonomy-settings" \
  "audit/strategy-funnel" \
  "quote-integrity/status" \
  "signals" \
  "paper/daily-performance" \
  "paper/daily-performance?page=2"
do
  safe_name="$(printf '%s' "$endpoint" | tr '/?=&' '____')"
  curl -sS -o "$ARTIFACT_DIR/${safe_name}.json" \
    -w "HTTP_STATUS=%{http_code} ENDPOINT=${endpoint}\n" \
    "http://127.0.0.1:18080/api/v1/${endpoint}" \
    2>&1 | tee -a "$ARTIFACT_DIR/api_status.log"
done

docker compose logs --since 7d app \
  2>&1 | grep -E "Binance immutable candle validation failed|Paper pipeline account failed|Closed candle event .* STALE|recovery re-dispatch" \
  > "$ARTIFACT_DIR/application_evidence.log" || true
```

Use the `latest_signal_id` or `latest_observation_id` from Query 25 for a
read-only replay check. `persist` must remain false:

```bash
curl -sS -o "$ARTIFACT_DIR/replay.json" \
  -w "HTTP_STATUS=%{http_code}\n" \
  "http://127.0.0.1:18080/api/v1/research/replay/<ID>?mode=AS_OF_DECISION&persist=0" \
  2>&1 | tee -a "$ARTIFACT_DIR/api_status.log"
```

Do **not** call `POST /api/v1/audit/exports/quick` under this handoff.

## 4. Evidence evaluation rules

1. Start from `docs/progress/evidence/phase0-production-acceptance/OPERATOR_CHECKLIST.md`.
2. Evaluate each of the 45 rows against its exact predicate.
3. Record the result in `operator_signoff.json` with:
   - deployed Git SHA, UTC collection interval, SQL hashes, artifact filenames;
   - per-obligation `PASS`, `FAIL`, `PENDING` or `BLOCKED`;
   - reviewer, review timestamp and a source-qualified evidence reference.
4. A SQL `EXIT_CODE=0` proves only that the read-only script executed.
5. A zero count proves only the bounded predicate, not unspecified history.
6. Legacy rows with missing identities or null mark provenance remain PENDING.
7. `VEC-P0-107-V2` requires separate source review and an approved write test;
   it cannot pass from read-only evidence.
8. Do not change historical financial records to make an audit reconcile.

For the funding gaps, feed Query 16 JSON (or an equivalent Query 20 JSON array)
plus independently obtained exchange funding schedules, rates and boundary
marks to:

```bash
python3 scripts/diagnostics/classify_phase0_funding.py --help
```

The classifier is advisory evidence preparation, not an authorized repair.

## 5. Migration and deployment ordering assessment

Deployment runs
`php artisan migrate --force` through `compose run --rm --no-deps app` before
`compose up -d --remove-orphans`, and the application services start only after
the migration step succeeds.

- `2026_10_29_000001_add_funding_mark_source.php` adds one nullable column,
  `market_funding_events.mark_price_source`, without a default or backfill.
  Legacy NULL rows remain NULL and mean unverified legacy provenance.
- The migration is additive and has no data rewrite. PostgreSQL 17 applies the
  metadata change without populating existing rows.
- Old application containers remain healthy during the short migration window;
  they ignore the new column.
- If migration fails, the workflow stops before new application containers are
  started. Do not manually bypass this ordering.
- The `down()` method drops the column. It must never be run against production
  because it destroys recorded provenance.
- Query 26 verifies the column exists after deployment. Query 16 and Query 20
  use `to_jsonb(...)->>'mark_price_source'` so pre-migration reads remain safe.

Residual risk: the migration runner does not set an explicit lock timeout. The
operation is metadata-only, but the operator must confirm the deployment
pipeline completed and Query 26 reports `t` before evaluating funding evidence.

## 6. Post-deployment verification checklist for the five remediation commits

After the operator deploys the five remediation commits (`75e11f1`, `9f4450d`, `3a90ce2`, `375d7ec`, `54cea59`), verify each commit's specific behavioral invariant against authentic evidence:

1. **Commit `75e11f1` (fix(funding): require verified settlement marks and retry legacy positions):**
   - **Precondition:** Query 26 must return `funding_mark_source_column_present = true`.
   - **Mark Provenance:** Query 19b must confirm that newly recorded funding events have `mark_price_source = 'BYBIT_MARK_KLINE_OPEN'` (or `'BINANCE_FUNDING_HISTORY'`), while historical pre-remediation rows legitimately retain `NULL`.
   - **Completeness Transition:** Newly closed positions with full settlement coverage must transition to `funding_complete = true` via Query 10 / Query 19.
   - **Idempotency:** Re-running funding ingestion must not duplicate entries in `position_funding_events` or alter `net_pnl`.

2. **Commit `9f4450d` (fix(paper): exclude unfunded bootstrap snapshots from performance analytics):**
   - **Provenance Chain:** Query 14 and Query 18 must show that bootstrap zero snapshots have `source_event_key` matching `account:initial*` and strictly precede the linked `owner-paper-capital:*` event by insertion sequence (`id`).
   - **Drawdown Distortion Prevention:** Query 12 / API `/api/v1/paper/daily-performance` and `/api/v1/research/profitability/summary` must show that funded Binance accounts do not report spurious 100% drawdown caused by pre-capital zero observations.
   - **Audit Immutability:** Historical zero snapshot rows remain intact in `equity_snapshots` and are included in raw audit exports (`EquitySnapshotRecord::performance` scope filters only analytics consumers).

3. **Commit `3a90ce2` (fix(pipeline): preserve terminal evidence and retry only failed accounts):**
   - **Cohort Preservation:** Query 22 must show that `STAGE_PAPER_PROCESSED` events record `eligible_account_ids`, `processed_account_ids`, `failures_count`, and structured `failures` in their JSON `payload`.
   - **Targeted Retry:** Retries on PARTIAL pipeline events must retry only failed accounts without resetting cohort counters or re-executing already-successful accounts.
   - **Expiry Disposition:** Expired pipeline deliveries must acknowledge upstream stages as STALE without mutating downstream state.

4. **Commit `375d7ec` (docs(phase0): record financial integrity remediation and diagnostic evidence):**
   - **Diagnostics Integrity:** `phase0_production_acceptance.sql` (Queries 1–16) and `phase0_evidence_closure.sql` (Queries 17–28b) execute inside `BEGIN TRANSACTION READ ONLY` ... `ROLLBACK` with zero database mutations.
   - **Offline Classification:** `classify_phase0_funding.py` accepts JSON input from Query 16 / Query 20 and outputs reproducible classification hashes across the 5 authoritative categories without making network requests or database writes.

5. **Commit `54cea59` (docs(progress): append phase0 integrity remediation snapshot):**
   - **Traceability Baseline:** All 45 `VEC-P0-*-V2` obligations remain registered in `docs/progress/vector-requirements.json` and initialized to `PENDING` awaiting authentic VPS evidence.

## 7. Local verification performed

Executed in the isolated local PostgreSQL test environment
(`127.0.0.1:25432`, database `bybit_test`), not production:

- `php artisan test tests/Feature/PaperTrading/Phase0DiagnosticPackageTest.php`
  passed: 3 tests, 90 assertions.
- `php artisan test tests/Feature/PaperTrading` passed: 101 tests, 2,664
  assertions.
- The full PHPUnit suite passed: 974 tests, 973 passed, 1 skipped opt-in live
  Bybit test, 9,748 assertions.
- `vendor/bin/pint --test` passed for the changed test file.
- Python diagnostic/tracker tests passed: 32 tests (`python3 scripts/tests/test_phase0_funding_classifier.py` and `test_vector_progress.py`).
- Frontend tests passed: 64 tests; `npm run build` (including `vue-tsc`) passed.
- The test executes every closure query locally, rejects DML keywords, verifies
  `BEGIN TRANSACTION READ ONLY`/`ROLLBACK`, verifies both bounded offsets, and
  confirms 26 canonical account identities.
- A direct `psql -X -v ON_ERROR_STOP=1` run of the closure SQL exited 0 locally.

Local SQL artifact hashes:

```text
b9a5fff38fbcb71de79be4e04aadf6cd6fd3c9c3c976648e0acaca3ea7f14b2b  scripts/diagnostics/phase0_production_acceptance.sql
e75054e2753baae6b26d72513b371408749b056238ba070444419e1db7a53c27  scripts/diagnostics/phase0_evidence_closure.sql
```

Not performed and not claimed:

- No production SQL or API call was executed.
- No production funding schedule, mark price, deployment log or browser state
  was collected.
- No obligation was promoted; all 45 V2 obligations remain PENDING.
- No deployment, push or GitHub Actions run was performed.
