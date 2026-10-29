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
- Row exports are deterministic, snapshot-consistent, and output directly as pure JSON arrays
  via dedicated export scripts (`phase0_export_funding_positions.sql` and `phase0_export_pipeline_anomalies.sql`).
- Summary queries in `phase0_evidence_closure.sql` execute once; no repeated query loops per offset.
- No `INSERT`, `UPDATE`, `DELETE`, DDL, backfill, reconciliation, replay of
  pipeline events, account reset, or `funding_complete` mutation is included.
- No audit-export `POST`, kill-switch change, promotion, or other write API is
  included. `VEC-P0-107-V2` cannot be closed by this read-only handoff.
- Dedicated permission-restricted artifact storage outside immutable release directories
  enforced with `umask 077` and `chmod 700`.
- No API key, password, token, or private key is requested, captured, or emitted.
- ECONOM and unrelated VPS resources are strictly untouched.

Source baseline: local `main` including the five remediation commits (`75e11f1`, `9f4450d`, `3a90ce2`, `375d7ec`, `54cea59`),
the diagnostic closure suite, dedicated export scripts, and migration safety safeguards.
The operator must record the actual deployed release SHA and container image digest.

## 2. What the diagnostic suite provides

1. `scripts/diagnostics/phase0_production_acceptance.sql` (Queries 1–16):
   The baseline hardened diagnostic suite covering account census, cross-exchange isolation,
   unique constraint catalog validation, double-entry accounting PnL identities, kill switches,
   drawdown thresholds, and legacy aggregates.

2. `scripts/diagnostics/phase0_evidence_closure.sql` (Queries 17–28b):
   Supplements baseline diagnostics with row-level and governance closure evidence:

| Query | Evidence | Supported obligations |
| --- | --- | --- |
| 17 | Exact 26-account identity census, missing/duplicate detection | VEC-P0-001-V2, VEC-P0-109-V2 |
| 18 | Per-account bootstrap capital and opening-snapshot provenance (BIGINT insertion sequence causality) | VEC-P0-112-V2, VEC-P0-208-V2 |
| 19 | Funding completeness and market mark provenance coverage | VEC-P0-003-V2, VEC-P0-203-V2, VEC-P0-207-V2 |
| 20 | Bounded preview of unresolved funding positions | VEC-P0-003-V2, VEC-P0-201-V2, VEC-P0-203-V2, VEC-P0-207-V2 |
| 21 | Pipeline anomaly and stuck-event summary | VEC-P0-002-V2, VEC-P0-201-V2, VEC-P0-204-V2, VEC-P0-209-V2 |
| 22 | Bounded preview of STALE/PARTIAL/FAILED pipeline events | VEC-P0-002-V2, VEC-P0-127-V2, VEC-P0-204-V2, VEC-P0-209-V2 |
| 23 | Both autonomy kill-switch authorities and stored capabilities | VEC-P0-119-V2, VEC-P0-120-V2 |
| 23b | Immutable autonomy approval audit coverage | VEC-P0-126-V2 |
| 24 | Candidate-to-backtest ownership linkage | VEC-P0-125-V2 |
| 25 | Read-only replay candidate identifiers | VEC-P0-101-V2 |
| 26 | Additive schema precondition checks (`mark_price_source`, `valued_through`, `valuation_fresh`) | VEC-P0-007-V2 |
| 27 | Migration, market-data, pipeline and snapshot provenance | VEC-P0-007-V2, VEC-P0-009-V2, VEC-P0-112-V2 |
| 28 | Drawdown circuit breaker enforcement and ongoing position servicing liveness | VEC-P0-004-V2, VEC-P0-127-V2 |
| 28b | Drawdown entry-block bypass audit (verifies zero entries opened post-block) | VEC-P0-004-V2, VEC-P0-127-V2 |

3. Dedicated machine-readable extract scripts:
   - `scripts/diagnostics/phase0_export_funding_positions.sql`:
     Extracts all unresolved closed positions as a single pure JSON array string for `classify_phase0_funding.py`.
   - `scripts/diagnostics/phase0_export_pipeline_anomalies.sql`:
     Extracts all historical STALE, PARTIAL, and FAILED pipeline events with cohort accounting as a single pure JSON array string.

SQL output is necessary but not sufficient. Missing identities, null funding marks,
aggregate-only legacy failures, and absent production evidence remain **PENDING**, not PASS.

## 3. Operator procedure on the production VPS

Run only after the owner-approved workflow in `.github/workflows/deploy.yml` has deployed
the release under acceptance. SSH is for read-only diagnostics.

### 3.1 Prepare dedicated evidence storage and capture release identity

Do NOT write evidence inside `/srv/bybit-signals/current`, which is an immutable deployment release directory.
Store all evidence in a dedicated, permission-restricted directory:

```bash
cd /srv/bybit-signals/current

# 1. Enforce strict directory permissions outside the release tree
ARTIFACT_DIR="/srv/bybit-signals/evidence/phase0-run-$(date -u +%Y%m%dT%H%M%SZ)"
(umask 077 && mkdir -p "$ARTIFACT_DIR")
chmod 700 "$ARTIFACT_DIR"

# 2. Capture canonical release identity, image digests, and file checksums
# NOTE: Deployments unpack via git archive, so .git does NOT exist in current.
# The deployed release is identified by the current symlink target and container image metadata.
{
  echo "=== VECTOR PHASE 0 EVIDENCE CLOSURE START ==="
  date -u +"TIMESTAMP_UTC=%Y-%m-%dT%H:%M:%SZ"
  echo "CURRENT_SYMLINK_TARGET=$(readlink -f /srv/bybit-signals/current)"
  if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "GIT_HEAD=$(git rev-parse HEAD)"
  else
    echo "GIT_HEAD=NO_GIT_DIR (git-archive release)"
  fi
  echo "RUNNING_DOCKER_CONTAINERS:"
  docker compose ps
  echo "APP_IMAGE_METADATA:"
  docker inspect --format 'Image={{.Config.Image}} Id={{.Image}} Revision={{index .Config.Labels "org.opencontainers.image.revision"}}' "$(docker compose ps -q app)"
  echo "SCRIPT_CHECKSUMS:"
  sha256sum scripts/diagnostics/phase0_production_acceptance.sql \
            scripts/diagnostics/phase0_evidence_closure.sql \
            scripts/diagnostics/phase0_export_funding_positions.sql \
            scripts/diagnostics/phase0_export_pipeline_anomalies.sql \
            scripts/diagnostics/classify_phase0_funding.py
} 2>&1 | tee "$ARTIFACT_DIR/release_and_health.log"
```

### 3.2 Run baseline diagnostics suite (Queries 1–16)

Execute once inside read-only transaction:

```bash
docker compose exec -T postgres psql -X -v ON_ERROR_STOP=1 \
  -U "${POSTGRES_USER:-bybit_signals}" \
  -d "${POSTGRES_DB:-bybit_signals}" \
  < scripts/diagnostics/phase0_production_acceptance.sql \
  2>&1 | tee "$ARTIFACT_DIR/production_diagnostic.log"
echo "EXIT_CODE=${PIPESTATUS[0]}"
```

*(Direct container alternative: `docker exec -i bybit-signals-postgres-1 psql -X -v ON_ERROR_STOP=1 -U bybit_signals -d bybit_signals < scripts/diagnostics/phase0_production_acceptance.sql`)*

### 3.3 Run closure diagnostics suite (Queries 17–28b)

Execute once to evaluate census, bootstrap causality, kill switches, and circuit breakers:

```bash
docker compose exec -T postgres psql -X -v ON_ERROR_STOP=1 \
  -U "${POSTGRES_USER:-bybit_signals}" \
  -d "${POSTGRES_DB:-bybit_signals}" \
  < scripts/diagnostics/phase0_evidence_closure.sql \
  2>&1 | tee "$ARTIFACT_DIR/evidence_closure.log"
echo "EXIT_CODE=${PIPESTATUS[0]}"
```

### 3.4 Extract machine-readable JSON datasets for offline investigation

Extract complete row-level datasets as clean, valid JSON files without psql table borders or offset drift:

```bash
# 1. Export unresolved closed positions (379 rows) for the offline funding classifier
# NOTE: -q silences command status lines (BEGIN/SET/ROLLBACK) ensuring pure JSON output.
docker compose exec -T postgres psql -X -q -A -t -v ON_ERROR_STOP=1 \
  -U "${POSTGRES_USER:-bybit_signals}" \
  -d "${POSTGRES_DB:-bybit_signals}" \
  < scripts/diagnostics/phase0_export_funding_positions.sql \
  > "$ARTIFACT_DIR/funding_positions.json"

# 2. Export pipeline anomalies (STALE, PARTIAL, FAILED rows)
docker compose exec -T postgres psql -X -q -A -t -v ON_ERROR_STOP=1 \
  -U "${POSTGRES_USER:-bybit_signals}" \
  -d "${POSTGRES_DB:-bybit_signals}" \
  < scripts/diagnostics/phase0_export_pipeline_anomalies.sql \
  > "$ARTIFACT_DIR/pipeline_anomalies.json"

# Verify JSON validity of exported datasets
python3 -c "import json; json.load(open('$ARTIFACT_DIR/funding_positions.json')); json.load(open('$ARTIFACT_DIR/pipeline_anomalies.json')); print('EXPORTS_JSON_VALID=true')"
```

*(Direct container alternatives using `docker exec -i bybit-signals-postgres-1 psql -X -q -A -t -v ON_ERROR_STOP=1 -U bybit_signals -d bybit_signals < ... > ...`)*

### 3.5 Run offline assessment tool across gathered evidence

Execute the deterministic offline evaluation script to assess the 45 production obligations:

```bash
python3 scripts/diagnostics/assess_phase0_evidence.py \
  "$ARTIFACT_DIR" \
  --deployed-sha 9ba6970b4514c32149866e77500757a5784ddcf8 \
  --output "$ARTIFACT_DIR/production_assessment.json"
```

### 3.6 Run offline funding classifier against independently validated evidence

Provide `funding_positions.json` along with independently obtained, validated exchange funding data:

```bash
# If independently validated exchange evidence is available:
python3 scripts/diagnostics/classify_phase0_funding.py \
  "$ARTIFACT_DIR/funding_positions.json" \
  --exchange-evidence /path/to/independently-validated-exchange-evidence.json \
  > "$ARTIFACT_DIR/funding_classification_results.json"

# If independent evidence is not yet available, inspect baseline local status:
python3 scripts/diagnostics/classify_phase0_funding.py \
  "$ARTIFACT_DIR/funding_positions.json" \
  > "$ARTIFACT_DIR/funding_classification_baseline.json"
```

### 3.7 Capture read-only API health responses and validate HTTP status codes

All listed GET endpoints are public research observability endpoints with rate limiting (`throttle:api`);
none require authentication headers or expose secrets. Validate that HTTP status is strictly 200:

```bash
for endpoint in \
  "system/version" \
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
  http_code=$(curl -sS -o "$ARTIFACT_DIR/${safe_name}.json" \
    -w "%{http_code}" \
    "http://127.0.0.1:18080/api/v1/${endpoint}")
  echo "ENDPOINT=${endpoint} HTTP_STATUS=${http_code}" | tee -a "$ARTIFACT_DIR/api_status.log"
  if [ "$http_code" != "200" ]; then
    echo "WARNING: Endpoint ${endpoint} returned non-200 status: ${http_code}" | tee -a "$ARTIFACT_DIR/api_status.log"
  fi
done
```

For read-only replay check (using `latest_signal_id` or `latest_observation_id` from Query 25), `persist=0` is mandatory:
```bash
# Replace <ID> with actual ID from Query 25
REPLAY_ID="<ID_FROM_QUERY_25>"
if [ -n "$REPLAY_ID" ] && [ "$REPLAY_ID" != "<ID_FROM_QUERY_25>" ]; then
  curl -sS -o "$ARTIFACT_DIR/replay_${REPLAY_ID}.json" \
    -w "ENDPOINT=replay HTTP_STATUS=%{http_code}\n" \
    "http://127.0.0.1:18080/api/v1/research/replay/${REPLAY_ID}?mode=AS_OF_DECISION&persist=0" \
    | tee -a "$ARTIFACT_DIR/api_status.log"
fi
```

### 3.8 Capture container logs across all relevant VECTOR processing services

VECTOR background processing is distributed across multiple services. Collect logs across all active services:

```bash
for service in app web postgres redis queue queue-bybit queue-binance scheduler market-ws audit-worker backtest-worker research-worker; do
  docker compose logs --since 7d "$service" \
    > "$ARTIFACT_DIR/logs_${service}.log" 2>&1 || true
done

# Search across all logs for critical conflict patterns and anomalies
grep -E "Binance immutable candle validation failed|Paper pipeline account failed|Closed candle event .* STALE|recovery re-dispatch|IntegrityException|DomainException" \
  "$ARTIFACT_DIR"/logs_*.log > "$ARTIFACT_DIR/filtered_anomaly_telemetry.log" || true
```

> [!WARNING]
> **Container Log Reset Notice:** Redeploying or recreating containers resets local container logs.
> The absence of log entries in recently recreated containers must **NEVER** be interpreted as proof
> of zero historical conflicts. Missing logs constitute unavailable evidence (`PENDING` or `BLOCKED`), not `PASS`.

## 4. Evidence evaluation rules

1. Start from `docs/progress/evidence/phase0-production-acceptance/OPERATOR_CHECKLIST.md`.
2. Evaluate each of the 45 rows against its exact predicate.
3. Record the result in `operator_signoff.json` with:
   - deployed release symlink target, Docker image ID/revision, UTC collection interval, SQL checksums, artifact filenames;
   - per-obligation `PASS`, `FAIL`, `PENDING` or `BLOCKED`;
   - reviewer, review timestamp and a source-qualified evidence reference.
4. A SQL `EXIT_CODE=0` proves only that the read-only script executed.
5. A zero count proves only the bounded predicate, not unspecified history.
6. Legacy rows with missing identities or null mark provenance remain PENDING.
7. `VEC-P0-107-V2` requires separate source review and an approved write test;
   it cannot pass from read-only evidence.
8. Do not change historical financial records to make an audit reconcile.

## 5. Migration safety and locking behavior

Migration: `2026_10_29_000001_add_funding_mark_source.php`.

- **Locking & Timeouts:** Executes `SET lock_timeout = '5s'` prior to schema alteration. If active background
  workers hold conflicting locks, PostgreSQL cancels the lock request after 5 seconds rather than stalling
  production ingestion.
- **Idempotency:** Checks `Schema::hasColumn(...)` before attempting column creation; repeated executions succeed cleanly.
- **Additive Nature:** Adds one nullable column, `market_funding_events.mark_price_source VARCHAR(64) NULL`.
  In PostgreSQL 17, adding a nullable column without defaults is an instant catalog metadata operation with zero row rewrites.
- **Fail-Safe Migration Outcome:** If the migration fails (e.g. lock timeout), the deployment stops before new app containers start.
  The operator investigates active locks (`pg_stat_activity`) and re-triggers deployment.
- **Audit Data Protection:** The `down()` method throws `LogicException('Funding mark provenance cannot be discarded by down migration.')`.
  Rolling back production migrations that contain financial provenance is strictly prohibited. Never run `migrate:rollback` in production.

## 6. Post-deployment verification checklist for the five remediation commits

After the operator deploys the five remediation commits (`75e11f1`, `9f4450d`, `3a90ce2`, `375d7ec`, `54cea59`), verify each commit's specific behavioral invariant against authentic evidence:

1. **Commit `75e11f1` (fix(funding): require verified settlement marks and retry legacy positions):**
   - **Precondition:** Query 26 must return `funding_mark_source_column_present = true`.
   - **Mark Provenance:** Query 19b must confirm that newly recorded funding events have `mark_price_source = 'BYBIT_MARK_KLINE_OPEN'` (or `'BINANCE_FUNDING_HISTORY'`), while historical pre-remediation rows legitimately retain `NULL`.
   - **Completeness Transition:** Newly closed positions with full settlement coverage must transition to `funding_complete = true` via Query 10 / Query 19.
   - **Idempotency:** Re-running funding ingestion must not duplicate entries in `position_funding_events` or alter `net_pnl`.

2. **Commit `9f4450d` (fix(paper): exclude unfunded bootstrap snapshots from performance analytics):**
   - **Provenance Chain:** Query 14 and Query 18 must show that bootstrap zero snapshots have `source_event_key` matching `account:initial*` and strictly precede the linked `owner-paper-capital:*` event by insertion sequence (`id` BIGINT).
   - **Causality Classification:** Query 18 must return `BOOTSTRAP_SEQUENCE_PROVEN` or `BYBIT_CANONICAL_BOOTSTRAP` for all 26 accounts, never `INSUFFICIENT_BOOTSTRAP_EVIDENCE`.
   - **Drawdown Distortion Prevention:** Query 12 / API `/api/v1/paper/daily-performance` and `/api/v1/research/profitability/summary` must show that funded Binance accounts do not report spurious 100% drawdown caused by pre-capital zero observations.
   - **Audit Immutability:** Historical zero snapshot rows remain intact in `equity_snapshots` and are included in raw audit exports (`EquitySnapshotRecord::performance` scope filters only analytics consumers).

3. **Commit `3a90ce2` (fix(pipeline): preserve terminal evidence and retry only failed accounts):**
   - **Cohort Preservation:** Query 22 and `pipeline_anomalies.json` must show that `STAGE_PAPER_PROCESSED` events record `eligible_account_ids`, `processed_account_ids`, `failures_count`, and structured `failures` in their JSON `payload`.
   - **Targeted Retry:** Retries on PARTIAL pipeline events must retry only failed accounts without resetting cohort counters or re-executing already-successful accounts.
   - **Expiry Disposition:** Expired pipeline deliveries must acknowledge upstream stages as STALE without mutating downstream state.

4. **Commit `375d7ec` (docs(phase0): record financial integrity remediation and diagnostic evidence):**
   - **Diagnostics Integrity:** `phase0_production_acceptance.sql` (Queries 1–16) and `phase0_evidence_closure.sql` (Queries 17–28b) execute inside `BEGIN TRANSACTION READ ONLY` ... `ROLLBACK` with zero database mutations.
   - **Offline Classification:** `classify_phase0_funding.py` accepts JSON input from `funding_positions.json` and outputs reproducible classification hashes across the 5 authoritative categories without making network requests or database writes.

5. **Commit `54cea59` (docs(progress): append phase0 integrity remediation snapshot):**
   - **Traceability Baseline:** All 45 `VEC-P0-*-V2` obligations remain registered in `docs/progress/vector-requirements.json` and initialized to `PENDING` awaiting authentic VPS evidence.

## 7. Local verification performed

Executed in the isolated local PostgreSQL test environment
(`127.0.0.1:25432`, database `bybit_test`), not production:

- `php artisan test tests/Feature/PaperTrading/Phase0DiagnosticPackageTest.php`
  passed: 4 tests, 114 assertions.
- `php artisan test tests/Feature/PaperTrading` passed: 102 tests, 2,688 assertions.
- Full PHPUnit test suite passed: 974 tests, 973 passed, 1 skipped (opt-in Bybit live network test).
- `./vendor/bin/pint --test` passed.
- Python tests: `python3 scripts/tests/test_phase0_funding_classifier.py` passed (7 tests),
  `python3 scripts/tests/test_vector_progress.py` passed (25 tests),
  `python3 scripts/tests/test_assess_phase0_evidence.py` passed (1 test).
- All diagnostic and export queries execute read-only without DML keywords, wrapped in transactions that roll back cleanly.

Local diagnostic artifact checksums:

```text
b9a5fff38fbcb71de79be4e04aadf6cd6fd3c9c3c976648e0acaca3ea7f14b2b  scripts/diagnostics/phase0_production_acceptance.sql
2ee305288f7a2569d5ac65175f6e318e5c86bc6c1bcefb376e98b72006761a8f  scripts/diagnostics/phase0_evidence_closure.sql
fb45a311742c70e0ab88e446c4dbf99d876af73ef753c952c3fd65220b27ff56  scripts/diagnostics/phase0_export_funding_positions.sql
5df65ff3a404793e5332728e837c6613d8ff2363522735934b48deb1868cf1e7  scripts/diagnostics/phase0_export_pipeline_anomalies.sql
a77e848fe68c10504e40e793fafe40f2207f379121a744efd26ef62d155b4925  scripts/diagnostics/assess_phase0_evidence.py
38b68c9b7ebfdf32103e51a01ba42c199e3e079f1a9e5392833bc6700dc980db  scripts/diagnostics/classify_phase0_funding.py
```

Not performed and not claimed:

- No production SQL or API call was executed.
- No production funding schedule, mark price, deployment log or browser state was collected.
- No obligation was promoted; all 45 V2 obligations remain PENDING.
- No deployment, push or GitHub Actions run was performed.
