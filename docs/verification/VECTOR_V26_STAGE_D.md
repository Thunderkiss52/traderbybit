# VECTOR V2.6 — Stage D: Forward-Paper Causality Verification

## Executive Summary

- **Task**: Independently verify and correct Bybit/Binance PAPER pipeline causality.
- **Status**: PASSED for the verified scope; production deployment not performed.
- **Date**: 2026-10-26
- **Branch**: `vector/v26-stage-d`
- **Base commit**: `4e73ffe` (Stage C)
- **Environment**: isolated worktree, isolated PostgreSQL `bybit_test` on `127.0.0.1:25432`.
- **Safety**: PAPER-only. No real orders, no trade-enabled keys, no production database,
  no production deployment, no ECONOM resources touched.

Stage D found and fixed two real causality defects in the Stage C event-driven path.
It also corrected a misleading Stage C benchmark claim and added deterministic
regression coverage for Bybit and Binance forward fills, stale events, and 26/26
cross-exchange reconciliation.

---

## 1. Confirmed Defects and Fixes

### Defect A — event-driven paper processing was treated as historical replay

**Root cause**

`ProcessPaperPipelineJob` passed a non-null `$at` to
`PaperProcessingService::process()`. The service inferred `historical = ($at !== null)`,
so every event-driven paper run bypassed the forward guards:

- no forward signal-ingestion lag check;
- no entry activation timestamp check;
- no `max_forward_fill_lag_seconds` check;
- risk decisions were timestamped with the signal decision time rather than the
  actual decision time.

**Fix**

- `PaperProcessingService::process()` now accepts an explicit `?bool $historical = null`.
- Inference is `$historical ??= $at !== null`, preserving explicit historical replay
  callers.
- `ProcessPaperPipelineJob` now calls:
  `process($account->id, $this->symbol, $at, $allowNewEntries, historical: false)`.

**Files**

- `app/PaperTrading/Application/PaperProcessingService.php`
- `app/Trading/Jobs/ProcessPaperPipelineJob.php`

### Defect B — same-bar look-ahead exit after an ambiguous entry

**Root cause**

When an entry bar was ambiguous, `processState()` opened the position and immediately
called the exit engine on the same bar. That simulates an intrabar exit after an entry
whose fill timing was already ambiguous, using information that was not available at
the entry decision boundary.

**Fix**

The immediate same-bar exit block was removed. Entry ambiguity is still persisted as
`ambiguous_execution`; exits are evaluated only on subsequent closed 5m bars.

**File**

- `app/PaperTrading/Application/PaperProcessingService.php`

---

## 2. Invariant Verification

| Invariant | Evidence |
|:---|:---|
| **T1 — No future information** | Forward mode uses only closed 5m bars and rejects bars whose close precedes activation. `test_forward_paper_does_not_fill_a_bar_that_closed_before_order_activation`. |
| **T2 — Durable activation** | A pending signal state is committed before any fill. The pre-activation test observes `PENDING_ENTRY` with zero positions and zero executions. |
| **T3 — Forward-only fills** | `bar->closeTime <= state->created_at` is rejected. Verified for Bybit and Binance. |
| **T4 — No retroactive execution** | Forward signal ingestion requires `decision_time >= account.created_at` and the activation-time guard. Verified by `test_event_driven_paper_never_ingests_signals_created_before_the_account`. |
| **T5 — Freshness at execution boundary** | `isFresh()` rejects stale jobs and `max_forward_fill_lag_seconds` rejects old pending fills. Verified by `test_forward_paper_rejects_a_pending_fill_older_than_the_freshness_window`. |
| **T6 — Existing positions remain managed** | A stale event returns early, but scheduled `ProcessPaperTradingJob` still processes subsequent closed bars for open positions. Verified by `test_stale_event_does_not_block_scheduled_management_of_existing_position`. |
| **T7 — Monotonic cooldown** | Preserved in `PositionLifecycleService`; covered by the existing paper regression suite (no Stage D change). |
| **T8 — Financial conservation** | Binance Stage D test runs `ExchangePaperReconciliation::inspect()` and asserts `LEDGER_PASS_ONLY` with 26 verified pairs. |

### Important timestamp semantics

- `created_at` on `paper_signal_states` is the durable activation time.
- A fill is allowed only when the 5m bar closes **after** activation and within the
  forward fill lag window.
- The ambiguous entry bar may produce an entry execution, but never a same-bar exit.
- Scheduled fallback processes exits independently of the stale-event entry guard;
  this is intentional so an entry outage does not strand an open position.

---

## 3. Regression Tests Added

`tests/Feature/Trading/TradingPipelineForwardCausalityTest.php` — 6 tests, 26 assertions:

1. No fill on a bar that closed before order activation; later valid bar fills.
2. Ambiguous entry bar does not exit until the next closed bar.
3. Signals created before the account are never ingested.
4. Pending fills older than the freshness window are skipped.
5. A stale event does not block scheduled management of an existing position.
6. Binance event-driven fill is forward-only and reconciles 26/26 accounts.

`tests/Feature/Trading/TradingPipelineConcurrencyAndSafetyTest.php` was updated so
its duplicate-execution fixture activates the pending order at the decision close,
before the 5m execution bar closes. The previous fixture relied on the replay
bypass and was not a valid forward-paper scenario.

`tests/Feature/Trading/EventDrivenTradingPipelineChainTest.php` was strengthened from
`>= 13` observations to exact set equality with all 13 registered strategy IDs, exact
observation counts, and explicit exchange isolation (zero cross-exchange observations)
for both the Bybit and Binance chains.

### D4 duplicate / concurrency coverage

Existing tests substantiate these D4 scenarios:

- duplicate paper jobs produce no duplicate positions or financial writes
  (`test_duplicate_paper_jobs_do_not_produce_duplicate_positions_or_financial_writes`);
- same-event concurrent processing is fenced and idempotent
  (`test_concurrent_workers_processing_same_event_are_fenced_and_idempotent`);
- recovery redispatches only fresh uncompleted events and marks expired events stale
  (`test_recovery_redispatches_fresh_uncompleted_events`,
  `test_recovery_marks_expired_events_as_stale_without_dispatching`);
- scheduled fallback does not duplicate an event-driven decision;
- feature-flag rollback does not create duplicate writers;
- execution ownership preserves ledger integrity and 26/26 reconciliation.

Not covered by automated tests in this environment: a real Redis connection loss,
hard worker termination during a job, and lock-expiry takeover after a worker crash.
Those remain operational risks and must not be reported as verified.

---

## 4. Verification Results

All commands were executed in `/home/thunder/work/traderbybit-stage-d`.

| Gate | Command | Result |
|:---|:---|:---|
| Stage D causality tests | `php artisan test tests/Feature/Trading/TradingPipelineForwardCausalityTest.php --no-ansi` | **6 passed**, 26 assertions |
| Full PHPUnit suite | `php artisan test --no-ansi` | **851 passed, 1 skipped**, 6,694 assertions (852 tests) |
| Pint | `vendor/bin/pint --test` | **passed** |
| PHP syntax lint | `php -l` across all tracked/untracked PHP files | **672 files, 0 errors** |
| Frontend tests | `npm test --prefix frontend` | **55 passed**, 0 failed |
| Frontend build/typecheck | `npm run build --prefix frontend` | **passed** (`vue-tsc` + Vite) |
| Go tests | `go test ./...` in `research-go` | **all packages passed** |
| Go vet | `go vet ./...` in `research-go` | **clean** |
| Go race tests | `go test -count=1 -race ./...` in `research-go` | **all packages passed** |

The single skipped PHPUnit test is pre-existing and not introduced by Stage D.

---

## 5. Stage C Benchmark Correction

Stage C reported a metric named **“Virtual Entry Latency.”** Review showed the
benchmark measured signal eligibility plus paper-processing code only:

- it did not assert that a `VirtualExecutionRecord` was created;
- it did not include the wait for a subsequent 5m execution bar;
- it reused a decision candle already in the past.

The script has been corrected:

- renamed to `eligibility_processing_latency` and `paper_processing_latency`;
- added `metric_scope` stating no virtual fill or market-wait latency is measured;
- added `virtual_fill_measured: false`;
- added a Stage C correction note referencing the Stage D fill tests.

A smoke run (`--runs=5 --warmup=1`) on the isolated test database produced:

| Exchange | P95 eligibility processing | P95 paper processing | Virtual fill measured |
|:---|---:|---:|:---:|
| Bybit | 1,337.488 ms | 1,415.454 ms | false |
| Binance | 1,576.469 ms | 1,641.712 ms | false |

These are processing-latency smoke measurements, **not** fill latency, profit
evidence, or forward-paper performance. Stage C's historical latency table should be
read with the Stage D correction note added there.

---

## 6. Cross-Exchange Reconciliation

The Binance Stage D test provisions the 13 Bybit and 13 Binance primary PAPER accounts
using the existing provisioners and explicit paper capital, performs a forward fill,
and then runs the existing reconciliation service:

- status: `LEDGER_PASS_ONLY`
- verified pairs: **26 / 26**
- no exchange HTTP requests were made by the test path.

The test uses the existing isolation pattern and does not bypass reconciliation or
fabricate account balances. It does not touch production or the primary PAPER accounts.

---

## 7. Data Analytics Status

Actual persisted forward-paper analytics were **not** produced in this worktree.

Reasons:

- The isolated worktree test database contains only synthetic fixtures.
- The local `bybit-signals-test` application container is configured for host
  `postgres`, but no reachable PostgreSQL service is present for it; queries fail with
  name-resolution errors.
- No production database credentials or read-only production access were used.

Consequently, Stage D makes no claims about live forward-paper sample size, win rate,
R-multiple, funding completeness, or missing market intervals. Those require a
read-only analytics run against the actual persisted production database.

---

## 8. Remaining Risks / Limitations

1. **Scheduled fallback timing** — Stale entries are skipped by design; existing
   positions rely on the scheduled `paper:process` fallback. The regression test
   proves this path, but production cron health remains an operational dependency.
2. **Benchmark scope** — The corrected benchmark measures processing latency only.
   It must not be cited as virtual fill latency or as a profitability result.
3. **Live WebSocket behavior** — Tests use deterministic closed-candle messages.
   Live reconnect, heartbeat, and exchange-side gap behavior remain operational risks.
4. **D4 infrastructure failures** — Redis interruption, worker crash/retry, and lock
   expiry are not exercised against real infrastructure in this worktree.
5. **No production deployment** — No deployment, migration, or container action was
   performed in this stage.
6. **No live forward-paper statistics** — See Section 7.

---

## 9. Files Changed

- `app/PaperTrading/Application/PaperProcessingService.php`
- `app/Trading/Jobs/ProcessPaperPipelineJob.php`
- `scripts/benchmark-trading-pipeline.php`
- `tests/Feature/Trading/TradingPipelineConcurrencyAndSafetyTest.php`
- `tests/Feature/Trading/TradingPipelineForwardCausalityTest.php`
- `docs/verification/VECTOR_V26_STAGE_C.md` (correction note)
- `docs/verification/VECTOR_V26_STAGE_D.md` (this document)
