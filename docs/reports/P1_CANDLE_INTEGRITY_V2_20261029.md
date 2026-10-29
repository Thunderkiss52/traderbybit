# P1 candle integrity blocker remediation V2

**V3 qualification:** The [final release gate](P1_FINAL_RELEASE_GATE_V3_20261029.md)
reproduced the five original fixes but found additional blocking journal recovery,
pending-entry validation and ongoing strategy-input gaps at `43bd3d77c084e23e83b845f7fce476ec0cd60fbd`.
Its **NOT READY** verdict supersedes the gate-readiness statement below. The V2
results remain historical local evidence, not complete journal lifecycle acceptance.

Initial verified HEAD: `40abcdce48bbb5033e7047daab81355083829cc9`, branch `main`,
clean working tree. Implementation commit: `7a26d93a39e09417423f4a2f58d6c68a74b7c884`.
Scope: the five blockers from the [previous gate](P1_PRE_PUSH_RELEASE_GATE_20261029.md).

## Root causes and fixes

| Blocker | Root cause | Implemented behavior |
|---|---|---|
| Failed conflict persistence | Database exceptions were suppressed after candle commit. | An fsync-backed journal records evidence on shared `app_storage` before PostgreSQL persistence. Failed acknowledgement raises `CandleIntegrityUnavailable`; pending evidence prevents certification even after DB recovery. |
| Outer rollback erased evidence | Nested `DB::transaction()` used the caller's connection/savepoint. | Laravel `ConnectionFactory` builds a new PDO from the active, resolved primary configuration, preserving host/socket, port, database, credentials, schema and SSL settings. That session commits independently before ingestion can finish or roll back. |
| Reads failed open | Missing schema and SQL failures became false, zero or empty lists. | Boolean/count/list APIs raise an explicit integrity-unavailable exception. Scoped status is `VALID`, `CONFLICTED` or `UNAVAILABLE`; the candle gate rejects unavailable integrity. |
| Recurrence stayed resolved | Read-then-insert/update raced and omitted reopening fields. | One PostgreSQL UPSERT reopens `UNRESOLVED`, clears `resolved_at`, increments the count atomically, preserves first stored values/first_seen/provenance and prior resolution notes, and retains the latest incoming payload/fingerprint. |
| Scheduled partial ingestion succeeded | Laravel treats callback return arrays as success. | HTF ingestion uses the actual Artisan command, retains limit 10 and quarantine, appends output, and keeps per-scope overlap mutexes. Non-zero exit codes produce real `ScheduledTaskFailed` events. Bybit scheduling is unchanged. |

Independent writes have a 3-second libpq connection timeout, 1-second PostgreSQL
lock timeout, 3-second statement timeout and one transaction attempt. The temporary
connection timeout environment setting is restored. The writer touches only
conflict evidence; it never locks or modifies historical candle rows on its own
connection, avoiding a dependency on the caller's candle locks.

The existing migration/schema is sufficient; no migration or remediation SQL was
changed. Strict ingestion rolls back new rows on conflict/failure. Quarantine
retains unrelated valid rows and immutable originals. If evidence persistence
fails, quarantine throws an explicit unavailable exception **after retaining valid
rows**, carrying an `INTEGRITY_UNAVAILABLE` report with actual insertion counts.
It never returns SUCCESS or a successful quarantine acknowledgement for that case.
The scheduled command prints this report and returns exit 3.

Readiness checks inspect only unresolved conflicts/pending journal evidence in
the actual 220-bar input window. Unrelated symbols/intervals remain independent.
Activation and portability previously omitted the optional integrity dependency;
they now pass their actual exchange/symbol/interval to the same gate. The existing
paper-readiness consumer propagates `CANDLE_HISTORY_INTEGRITY_UNAVAILABLE` as BLOCKED.

Health remains backward compatible for successful checks, adding
`integrity_status`. Unavailable checks report CRITICAL, affected rows UNAVAILABLE,
and null conflict counts/lists rather than fabricated zeroes. Unknown execution
failure counts are null. The dashboard shows an unavailable banner and N/A.

## Journal operation and recovery boundary

Default path: `storage/app/candle-integrity`, on the existing shared `app_storage`
volume used by PHP services. Entries contain scope, timestamp, both observations,
fingerprint and provenance, with restricted file permissions. File and directory
metadata are flushed before acknowledgement. A failed DB write retains its file;
only an acknowledged write deletes its own entry. No GET endpoint writes conflict
records, replays entries, resolves conflicts or corrects candles.

Journal files from failed/interrupted acknowledgements require evidence review
and reconciliation before removal; they are deliberately not cleared by retries
or automatically certified from the existence of a row. If journal storage itself
is unavailable, integrity reads fail closed; the writer still attempts independent
PostgreSQL persistence and returns an unavailable failure. Healthy shared storage
is an operational prerequisite. Multi-host deployments would require shared journal
storage; this change targets the existing single-VPS Compose volume arrangement.

## Actual verification

| Check | Actual result | Exit |
|---|---|---|
| Combined targeted Binance/transaction/health/readiness/scheduler tests | 66 tests, 794 assertions, 0 failures/errors/skips | 0 |
| PostgreSQL conflict/transaction class (included above) | 13 tests, 119 assertions PASS | 0 |
| Real scheduler class (included above) | 5 tests, 87 assertions PASS | 0 |
| Full PHPUnit | 1,009 total: 1,008 passed, 1 intentional skip; 10,267 assertions; 0 failures/errors | 0 |
| Pint | 708 files PASS | 0 |
| PHP syntax | 692 files PASS | 0 |
| Frontend | 64 tests passed, no failures/skips | 0 |
| Frontend typecheck and production build | PASS | 0 |
| Tracker | 25 tests passed; generate and strict validation passed | 0 |
| Go race suite | Not rerun: no Go source changes; both Go worker integration tests ran successfully in full PHPUnit | N/A |

Only `BybitPublicApiIntegrationTest::test_public_linear_market_endpoints_match_the_documented_contract`
was skipped because `RUN_BYBIT_INTEGRATION=1` is opt-in.

Complete raw output and real process exit codes are in the dated
[evidence directory](../progress/evidence/p1-candle-integrity-v2-20261029/).
Logs are losslessly compressed as `.log.gz`; SHA-256 in command records covers
**decompressed** stdout/stderr. `SHA256SUMS` covers stored artifact bytes.
Independent backend PIDs and committed row counts are preserved in
[durability-proof.json](../progress/evidence/p1-candle-integrity-v2-20261029/durability-proof.json).
The first local commit contains the fixes/tests and verification metadata; the
following documentation commit records this report, roadmap and raw logs. The
final response records the exact two-commit chain and final HEAD.

PostgreSQL tests use a task-owned PostgreSQL 17.11 `bybit_test` container, tmpfs
storage, no published ports, and an **internal Docker network**, preventing external
HTTP/database access. PHP is 8.5.12; Node is 22.23.4. Queue-unavailable behavior is
tested without a live Redis service. Exchange HTTP is faked, including in real
scheduled subprocesses; no production exchange or database was contacted.

The transaction tests check distinct primary/observer backend PIDs and query the
committed row using the independent observer while the enclosing transaction is
still open and after rollback. Strict/quarantine × outer/no-outer cases all retain
one conflict. The test log records actual PIDs and row counts. Two four-process,
barrier-synchronized tests verify concurrent initial insertion and reopening;
one identity remains, with exact counts 4 and 5 respectively. Contended writes
fail within the bounded timeout and retain pending evidence.

Other controls cover rejected inserts, missing tables, renamed columns, refused
connections, journal storage failure, scoped pending evidence, immutable original
rows, valid unrelated rows, health/API null semantics, paper readiness, all four
command exit codes, actual scheduler failure events/logging/mutexes, and continued
execution of other HTF intervals after one fails.

Development failures are preserved with final results. The first scheduler
iterations exposed Laravel argument serialization errors and test process/barrier
issues; these were fixed. The first full suite found a legacy test whose newly
durable conflict evidence outlived its rolled-back fixture. All conflict-producing
test classes now explicitly clean isolated evidence after rollback. Another
combined run exposed assertions counting Bybit candles; they now scope Binance.
One prematurely started full run was stopped after reviewing that target failure.
The initial full run also skipped a Go integration build because root-owned test
execution could not read Git VCS metadata safely; the isolated runner now builds
the worker with `GOFLAGS=-buildvcs=false`, with no Go source changes. The final
full run must contain only the existing opt-in public-Bybit skip.

An inherited runner SHA label was corrected to the verified initial HEAD; raw
command output, exit codes and hashes were preserved. Verification provenance is
the initial commit plus the tested candidate's per-file source hashes, then the
local implementation commit with identical runtime/test contents.

## Scope, tracker and operator boundary

The roadmap records local implementation/verification independently of production
acceptance. Tracker generation, 25 tracker tests, strict validation and diff checks
are recorded. Implementation, Verification and Product scores remain provisional
0.0000% before/after (1 failed, 51 stale, 177 unavailable obligations). No aggregate
test counts were admitted as requirement acceptance; criteria and weights are
unchanged. Phase 0 remains BLOCKED and roadmap Phase 1 NOT STARTED.

Four previously reported historical BTCUSDT/ETHUSDT 30m/1h conflicts at
2026-10-27T00:00:00Z remain separately authorized remediation work. Their current
production values/count were not queried. Historical remediation SQL was not run
or wired into runtime, migrations, jobs or deployments. No account, balance,
position, trade, funding or audit history was changed outside isolated fixtures.
No production connection/write, VPS change, Git push, deployment or ECONOM access
occurred. Only the task-owned PostgreSQL container, internal network and derivative
test image were removed after verification; shared resources were preserved. Browser, real-volume permissions, power-loss behavior and production
readiness remain unverified.

Verdict: **READY FOR FINAL PRE-PUSH GATE**. This authorizes neither push nor deployment.
