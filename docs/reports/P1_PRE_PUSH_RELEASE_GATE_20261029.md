# P1 Binance Candle Integrity — final local pre-push gate

Date: 2026-10-29. Scope: verification of `24e5096588c1aca66a19761ca7ed207e73514066`;
documentation changes only. Historical verdict: **NOT READY FOR OPERATOR PUSH**.

The five local blockers were subsequently fixed and regression-tested in the
[V2 remediation report](P1_CANDLE_INTEGRITY_V2_20261029.md). This original gate and
its raw evidence remain a record of the earlier implementation, not current V2 behavior.

## Repository and scope

- Initial branch: `main`; initial working tree: clean.
- Verified local ancestry: `671f72f11ba76a978dc1a6f29ae0ceeeb63e9322` →
  `24e5096588c1aca66a19761ca7ed207e73514066`; exactly one implementation commit.
- Local `origin/main` points to `671f72f11ba76a978dc1a6f29ae0ceeeb63e9322`;
  origin is `git@github.com:Thunderkiss52/traderbybit.git`. No fetch or remote
  production inspection was performed. Last-deployed identity is operator-provided.
- Implementation diff: **18 files, 1,451 insertions, 117 deletions**. All are P1
  ingestion, conflict storage/readiness, monitoring, migration, tests, reports,
  prepared SQL or ignore rules. The two `.gitignore` additions are exactly
  `.opencode/` and `!/docs/reports/`.
- No secret/environment files or database dumps are in that diff. Historical
  values in the forensic report are documented prior claims, not newly acquired
  production evidence. No generated binary changes are in the implementation diff.
- This review changes documentation/evidence only. A local documentation commit
  follows the implementation commit; its exact SHA is reported in the final reply.

## Implementation and migration checks

`SystemFreshnessService` uses `CarbonImmutable::now('UTC')`, derives the 24-hour
window without mutating `$now`, and bounds event timestamps on both sides.
The targeted frozen-clock, exchange-isolation and API checks passed.

Actual migration order and semantics:

1. `2026_10_29_000001_add_funding_mark_source.php` also precedes the listed migrations.
2. `2026_10_29_180000_create_research_workers_table.php`: worker registry and algorithm metadata.
3. `2026_10_29_180100_make_concentration_score_nullable_in_portfolio_correlation_snapshots.php`: nullable concentration score.
4. `2026_10_29_180200_add_generation_token_to_research_workers_table.php`: generation token, default 1.
5. `2026_10_29_220000_create_market_candle_conflicts_table.php`: additive P1 table, last by timestamp.

The migrations ran successfully on isolated PostgreSQL 17.11. The actual P1 table
has 20 columns: `id`, `exchange`, `category`, `symbol`, `interval`, `open_time`,
`stored_values` (JSONB), `incoming_values` (JSONB), `conflict_column`,
`error_message`, `source_provenance`, `payload_fingerprint`, `first_seen_at`,
`last_seen_at`, `occurrence_count`, `status`, `resolved_at`, `resolution_notes`,
`created_at`, `updated_at`. Timestamp columns use timezone-aware types. The unique
identity covers exchange/category/symbol/interval/open_time. Model casts and
service reads/writes match this schema; no invented `conflict_open_time`, scalar
`stored_*` columns or `resolution_history` requirement was imposed.

Nominal strict ingestion rolls back new candles on conflict and records evidence
after that top-level rollback. Nominal quarantine preserves the stored candle,
inserts the two valid candles and reports `PARTIAL_INGESTION_WITH_CONFLICTS`.
An independent PDO connection confirmed that nominal evidence was committed.
The 220-bar readiness query includes only conflicts whose open_time overlaps its
actual input window. The Binance HTF schedule passes limit 10 and quarantine=true;
the existing Bybit schedule is unchanged.

## Release blockers reproduced locally

These are diagnostic observations, not additional passing PHPUnit tests.
See [probe output](p1-pre-push-20261029/probe-observations.json) and the preserved
[reproduction script](p1-pre-push-20261029/gate-probes.php.txt).

| Finding | Observed result | Source |
|---|---|---|
| Conflict persistence failure is suppressed | A synthetic CHECK constraint rejects the conflict insert. Two valid candles commit, durable conflict count is 0, ingestion reports partial/quarantined, and the overlapping window returns `READY`. | `MarketCandleConflictService.php:66-124`; `BinanceCandleIngestion.php:131-176` |
| Integrity reads fail open | Temporarily missing conflict table or invalid status-column query gives `hasActiveConflict=false` and window `READY`; count also becomes 0 on query error. Schema changes were confined to the task-owned test database and restored. | `MarketCandleConflictService.php:137-157`, count/list catch paths |
| A recurring conflict remains resolved | After setting a synthetic row to `RESOLVED`, another contradictory observation updates its payload/count without reopening it; window returns `READY`. | `MarketCandleConflictService.php:80-91` |
| Scheduled partial ingestion is reported as success | Real Laravel scheduled callback returns `PARTIAL_INGESTION_WITH_CONFLICTS`, but scheduler exit code is 0. The explicit HTF command handles partial results with code 2; the schedule calls the service directly. | `routes/console.php:255`; Laravel `CallbackEvent::execute()` |

An additional transaction-boundary probe found that conflict persistence uses the
same connection: inside an enclosing transaction, the independent observer sees
zero rows and the outer rollback removes the record. The unconditional claim of
an independent durable transaction is therefore false outside the verified
top-level calling context. This probe does not establish an enclosing transaction
at every production caller.

## Deployment path inspection

Inspected `.github/workflows/deploy.yml`, `docker/scripts/production-deploy-wrapper.sh`,
`update.sh`, `common.sh`, validation/recovery paths, `compose.yaml`,
`docker/php/Dockerfile`, PHP entrypoint and `docker/go/Dockerfile`.

Normal source path: workflow_dispatch → CI checks → publish app/web SHA images →
verified release archive → installed wrapper entrypoint → selected release's
`update.sh`. Common configuration explicitly selects project `bybit-signals`,
`compose.yaml`, `.env.production` and optional proxy overlay. The update script
backs up, pulls/builds images, readies PostgreSQL/Redis, then runs
`compose run --rm --no-deps app php artisan migrate --force --no-interaction`.
Only after migration succeeds does it initialize the gated Binance PAPER cohort,
replace application services with `compose up -d --remove-orphans`, and restart web.
The migration one-shot runs Artisan, not PHP-FPM or scheduler. No updated
long-running application is started before migration on this normal path.

Pre-deploy recovery may realign the current release before the new migration;
the error trap restores the previous application image and never rolls back DB
migrations. These are existing branches, not evidence of a successful live rollout.
Installed wrapper identity, VPS environment, image cache, backup and runtime
readiness were not revalidated in this gate.

The Go executable belongs to a **separate research-worker image**:
`docker/go/Dockerfile` builds it from source and copies it to
`/usr/local/bin/research-worker`. Compose references that Dockerfile. The workflow
publishes app/web; update.sh builds the SHA-tagged worker image if absent. The PHP
application image does not embed this Go worker. Source inspection confirms the
normal automated path requires no additional manual deployment step; no production
image build or deployment rehearsal was performed.

No reference automatically executing the prepared remediation SQL was found in
application code, routes, migrations, Docker scripts or workflows. No such SQL
was executed. Its terminal `COMMIT` does not provide an interactive review pause;
the proposal still needs a separately authorized execution review.

## Executed verification

Fresh results are recorded with complete stdout/stderr, process exit codes,
timestamps and SHA-256 hashes in the adjacent evidence directory. Raw logs are
stored as deterministic `.log.gz` files; each command hash covers the decompressed
stdout/stderr bytes. Use `gzip -dc <file>.log.gz` to inspect them. No PASS result
was inferred from a tail pipeline. The runner waits on each actual process.

| Check | Actual result | Exit |
|---|---|---|
| Targeted eight-file P1 suite | 47 tests, 593 assertions; 0 failures/skips | 0 |
| Full PHPUnit, final complete environment | 992 total: 991 passed, 1 intentional skip; 10,078 assertions; 0 failures/errors | 0 |
| Pint | PASS, 702 files | 0 |
| PHP syntax | PASS, 686 PHP files | 0 |
| Frontend tests | 64 passed; 0 failures/skips | 0 |
| Frontend build | `vue-tsc --noEmit` and Vite build succeeded | 0 |
| Go | `go test -race -count=1 ./...`: all 11 tested packages passed; 6 packages have no tests; no race detected | 0 |
| Tracker tests | 25 passed | 0 |
| Complete-snapshot tracker generation / strict validation | Both passed; provisional scores unchanged | 0 |
| Deployment script syntax | `bash -n` passed for the five inspected scripts | 0 |

The only PHPUnit skip is
`BybitPublicApiIntegrationTest::test_public_linear_market_endpoints_match_the_documented_contract`,
which requires `RUN_BYBIT_INTEGRATION=1`. No additional skips occurred. Counts from
an earlier run (43/517 or 989) were not copied into this gate.

The original implementation diff check reported 11 trailing-whitespace lines in
its two Markdown reports. This documentation changeset removes them; the combined
release diff now passes `git diff --check`.

The first full PHPUnit attempt had one environment failure:
`Phase0DiagnosticPackageTest::test_export_queries_execute_read_only_and_return_valid_json`
could not run the missing `psql` executable (992 tests, 10,063 assertions, one
failure, one intentional skip, exit 1). An ephemeral derivative test image added
PostgreSQL 17 client; the focused recovery test passed (1 test, 24 assertions).
The final full run below uses that complete environment. No source fix was made.

An initial worker build was invoked outside its Go module and exited 1 after
successful test migrations. Repeating from `research-go` built successfully.
The snapshot was created from Git archive; two export-ignored tracked files were
then restored from the same commit before tracker verification. Initial tracker
attempts missing `.github/workflows/deploy.yml` exited 2; complete-snapshot
generation and strict validation passed. These recovered setup attempts remain
in the command record and compressed logs.

## Evidence boundary, roadmap and remaining operator work

Tests used a task-owned PostgreSQL 17.11 container (`bybit_test`, no published
ports, tmpfs storage), PHP 8.5.12, Node 22.23.4, and Go 1.27.3. A fresh Go worker
was built inside the test snapshot; the checkout's tracked binary was untouched.
Runtime/test source matched the reviewed commit; snapshot differences consisted
of that deliberately rebuilt worker and generated local tracker projections.
No live Redis service was provided; health tests exercised unavailable-queue
handling. No live exchange, browser, production PnL or deployment acceptance is
claimed. Task-owned PostgreSQL, network and derivative test image were removed
after verification; shared images and all unrelated resources were preserved.

The authoritative Go-first roadmap now records **IMPLEMENTED**, nominal
**LOCAL VERIFIED**, failed local integrity checks, and **PRODUCTION PENDING**
separately. Historical forensic conclusions were qualified and Markdown trailing
whitespace was removed from the two existing P1 reports. No runtime code, SQL,
migration, registry criteria or weights were changed.

The offline tracker was generated and strictly checked in the isolated snapshot;
25 tracker tests passed. Before/after assessed scores remain provisional:
Implementation **0.0000%**, Verification **0.0000%**, Product **0.0000%**, with
1 failed, 51 stale and 177 unavailable obligations. No requirement evidence was
admitted from aggregate suite counts; `VEC-P0-009` covers the performance baseline
and `VEC-P0-205` covers Binance recovery, but neither is accepted by this review.
Canonical `--strict --base-ref 24e5096` also passed in the actual checkout.
The canonical registry and its dated projections remain unchanged. Phase 0
remains **BLOCKED** and roadmap Phase 1 **NOT STARTED**.

Four previously reported historical conflicts (BTCUSDT/ETHUSDT, 30m/1h,
2026-10-27T00:00:00Z) remain **PENDING separately authorized remediation**.
Their current production row values/count were not queried. Push and production
deployment remain pending and prohibited. All production account/history state
was preserved by making no production connections or mutations; the live census
of 26 accounts was not re-inspected. ECONOM was neither inspected nor changed.

Next action: fix and regression-test the reproduced integrity/partial-result
defects in a separately scoped implementation task, then repeat this gate.
Passing nominal suites does not authorize a push or deployment.
