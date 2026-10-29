# P1 final release gate V3 — NOT READY

Date: 2026-10-29. Inspected implementation and initial HEAD:
`43bd3d77c084e23e83b845f7fce476ec0cd60fbd`, branch `main`, initially clean.
This gate makes documentation/evidence changes only. No runtime fix is claimed.

## Release contents and provenance

The local remote-tracking baseline is
`671f72f11ba76a978dc1a6f29ae0ceeeb63e9322` (`origin/main`). No fetch was performed;
this is not a claim about the current GitHub ref. The exact outgoing chain before
this evidence-only closure commit, oldest first, is:

1. `24e5096588c1aca66a19761ca7ed207e73514066` — P1 implementation.
2. `40abcdce48bbb5033e7047daab81355083829cc9` — original gate evidence/roadmap.
3. `7a26d93a39e09417423f4a2f58d6c68a74b7c884` — V2 integrity fixes/tests.
4. `43bd3d77c084e23e83b845f7fce476ec0cd60fbd` — V2 evidence/roadmap.

Baseline through inspected HEAD: **127 files, 5,807 additions, 113 deletions**,
including 98 documentation/evidence files. The final local closure adds only
documentation/evidence; its exact SHA and final clean-tree status are supplied in
the completion response. Runtime and test file hashes remain identical to the
inspected source. File lists, hashes, full command records, JUnit and losslessly
compressed logs are in the [V3 evidence directory](../progress/evidence/p1-final-release-gate-v3-20261029/).
Command hashes cover decompressed output; `SHA256SUMS` covers stored bytes.

## Five original blockers independently reproduced

The preserved original probe was adapted to the explicit exception contract and
the actual scheduled Artisan subprocess. Its source and output are retained as
`original-probes.php.txt`, `original-probes.log.gz` and `probe-observations.json`.
Its fixture cleanup archives pending evidence before resetting isolated data;
**fixture cleanup is not application recovery**.

| Original blocker | Actual final-source observation |
|---|---|
| Failed conflict insert claimed success/READY | Injected rejection returns `INTEGRITY_UNAVAILABLE`; quarantine retains two valid unrelated candles, no conflict row is falsely claimed, pending evidence rejects the window. |
| Rollback erased conflict evidence | Independent PostgreSQL observer sees one committed conflict before and after enclosing rollback. Strict rollback preserves the original immutable candle. Strict/quarantine × outer/no-outer tests verify distinct backend PIDs. |
| Reads failed open | Missing table and invalid-column failures return/raise UNAVAILABLE; window/readiness reject input; health reports CRITICAL with unavailable/null integrity counts. |
| Recurrence stayed RESOLVED | New observation reopens UNRESOLVED, clears `resolved_at`; real four-process UPSERT tests retain one identity and exact counts 4 (new) and 5 (reopened), preserving first evidence and prior resolution notes. |
| Scheduled partial ingestion succeeded | Real Laravel `schedule:run` executes the dedicated command with HTTP faked in its subprocess. The scheduled event's command exit code is **2**, with one `ScheduledTaskFailed`. Command failure/unavailable cases are non-zero; success remains zero. Logging, limit 10, quarantine and overlap mutexes are asserted. This is not a claim that Laravel's outer `schedule:run` exit code is 2. |

The targeted suite includes PostgreSQL durability (13 tests / 119 assertions) and
real scheduler execution (5 tests / 87 assertions). The independent backend PIDs
and committed counts are extracted into `durability-proof.json`.

## Additional V3 release blockers

### R1 — No safe journal recovery protocol

`CandleConflictJournal` exposes only `begin`, `complete` and `assertClear`.
There is no application recovery/replay command, transactional event receipt,
stable event identity or commit-before-ack checkpoint. `recordConflict` creates a
new random file for every attempt, increments the scope row, and deletes only its
own new file after commit. It cannot acknowledge a previously pending entry.

The current journal is flat JSON containing scope/open_time, both observations,
fingerprint, provenance and observation metadata. Default location is
`storage/app/candle-integrity` on the existing shared Compose `app_storage` volume.
Files use mode 0600; writes, files and directory metadata are flushed. PostgreSQL
persistence uses a genuinely separate PDO built from the active primary Laravel
configuration, one attempt, connect timeout 3s, lock timeout 1s, statement timeout
3s. Failures retain evidence and explicitly reject certification in guarded readers.
There is no pending-entry retry/recovery policy beyond future attempts recording
another observation. Entry count is not a replay deduplication key.

`journal-probes.php.txt` deliberately tests **operator-style replay through the
existing record primitive**, not an imaginary recovery API:

| Scenario | Actual result |
|---|---|
| Recovery persistence rejected | Explicit unavailable failure; zero DB occurrences, two pending files, scoped window UNAVAILABLE. |
| Same saved entry replayed twice | Occurrence count **1 → 2**; original file still pending; no idempotent recovery. |
| Process interrupted after commit | Count **3** committed, original pending remains; a newly constructed service still reports UNAVAILABLE. No protocol can distinguish this committed event on replay. |
| Four synchronized replays of the same entry | Count **3 → 7**, one DB identity, two pending files; no safe concurrent replay acknowledgement. Normal concurrent distinct observations are separately verified by PHPUnit. |

The interruption control stops the replay worker after `recordConflict` returns;
it does not inject a crash precisely between that primitive's commit and unlink.
There is no native recovery implementation whose crash protocol can be accepted.

The probe exits **1** with `V3_JOURNAL_RELEASE_GATE=NOT_READY`.
Atomic UPSERT prevents duplicate scope rows, but does not make recovery idempotent.

### R2 — Invalid pending scope can certify READY

`assertClear` verifies presence of scope keys but not their types or the journal's
complete schema. A syntactically valid pending JSON entry with an **array-valued
symbol** passes parsing, fails strict scope equality, and is skipped. With no DB
conflict row, actual `integrityStatus` returns **VALID** and
`BinanceCandleWindow` returns **READY** for the affected input. Malformed evidence
must produce UNAVAILABLE, not be treated as another scope. The same journal probe
preserves this negative control and its full output.

### R3 — Ongoing strategy inputs bypass pending integrity

Source path: Binance scheduled signals → `BinanceResearchCycle` →
`StrategyObservationService` → `StrategyInputFactory`. The factory checks
closedness/continuity/freshness/warm-up, but does not consult conflict/journal
integrity. Activation/readiness checks do not cover this ongoing input path.
`BinancePaperSafety` and the execution eligibility guard also do not provide that
ongoing candle-scope check.

`runtime-probe.php.txt` seeds only isolated synthetic candles for `V3PIPEUSDT` and
one pending entry overlapping the 30m input. The direct candle-window gate returns
`CANDLE_HISTORY_INTEGRITY_UNAVAILABLE`, but the real factory returns **no validation
errors and all four warm-up flags true**. A separate fresh PHP process reproduces
the same result with the original pending file present. Probe exit: **1**.
This proves input admission bypass after process startup, not an actual BUY,
signal publication or PAPER fill. The probe created no accounts, signals or trades
and did not call paper execution. Scheduled/worker end-to-end rejection therefore
is not accepted by this gate.

## Pending entries and exact operator boundary

The pre-probe inventory inspected only this checkout's default journal and the two
known prior task-owned evidence workspaces: **zero pending files, zero inspection
errors**. No production filesystem, HTTP endpoint or database was inspected.
V3 deliberately generated pending fixtures. `journal-inventory-after.json` retains
their exact bytes, SHA-256, fixture locations and copy relationships. Archived
copies are not additional observations. These are test artifacts, not production
recovery work; they are retained as evidence: seven files/copies, five distinct
raw payloads. The isolated default runtime journal is empty after the suite.

**There is currently no safe released recovery command to prescribe.** The exact
safe handling is to preserve pending files and checksums, keep affected scopes
UNAVAILABLE, and not unlink/move/rename evidence, call `complete(path)` manually,
or infer recovery solely from a matching DB row. Replaying `recordConflict` is
demonstrably not safe idempotent recovery. Before any operator recovery action,
implement and verify stable event identity, transactional deduplication/receipts,
acknowledgement after commit, bounded retries and crash/concurrency recovery.
The missing protocol blocks release; this report does not authorize journal
removal or production repair.

Compose shares journal storage across PHP services. Entrypoint/startup and update
scripts do not delete these files, but also do not recover them. Valid pending
entries survive process restart in guarded consumers. R2/R3 prevent the stronger
claim that all startup/scheduled/worker paths fail closed. Actual production volume
permissions, disk/power-loss durability and restart acceptance remain unverified.

## Regression results

All commands capture full output and real process exit codes; PHP lint pipelines
use `pipefail`. Test runs use the exact inspected runtime/test source at `43bd3d7`.

| Check | Result | Exit |
|---|---|---|
| Targeted integrity/ingestion/readiness/health/scheduler PHPUnit | 66 tests, 794 assertions; zero failures/errors/skips | 0 |
| Preserved original probes | All five original blocker scenarios now satisfy their controls | 0 |
| Journal lifecycle/corruption probes | R1/R2 reproduced | 1 |
| Ongoing input/fresh-process probe | R3 reproduced | 1 |
| Full PHPUnit | 1,009 total: 1,008 passed, 1 intentional public-Bybit skip; 10,267 assertions; zero failures/errors | 0 |
| Pint | 708 files, no violations | 0 |
| PHP syntax | 692 files, no syntax errors | 0 |
| Frontend tests | 64 pass; no failures/skips | 0 |
| Frontend typecheck/build | Passed | 0 |
| Tracker tests | 25 pass | 0 |
| Tracker generate/strict validation | Passed in isolated snapshot; canonical strict validation also passed | 0 |

The first full-test wrapper was interrupted after a complete nominal test log,
before retaining its exit code. That log is retained but is not accepted as exit
evidence. The complete suite was rerun to capture its actual result. One tracker
generation attempt failed (exit 2) because the archive lacked host-side Git metadata;
the corrected offline invocation uses the original Git directory with the isolated
work tree and passes. Neither failure is hidden or relabeled as a test pass.

Go race evidence is reused from source
`24e5096588c1aca66a19761ca7ed207e73514066`: the recorded
`go test -race -count=1 ./...` exit is 0; decompressed log SHA-256 is
`ef8bc37ca50eb455e9a714568742c8ce10892ae6bba4135047b8d13063a6b5f2`.
`go-reuse.json` records an empty diff through inspected HEAD for `research-go`,
Go Dockerfile, Compose, workflow, research jobs/infrastructure and research
configuration. No relevant Go source/concurrency behavior changed. Both Go worker
integration paths remain included in the full PHP suite.

## Schema, deployment and complete diff review

Only new migration: `2026_10_29_220000_create_market_candle_conflicts_table.php`.
Actual PostgreSQL schema/model/writer compatibility is checked, including JSONB
`stored_values`/`incoming_values`, `open_time`, `payload_fingerprint`, occurrence
count, `resolved_at` and `resolution_notes`, and unique scope constraint.
Earlier migrations are accurately identified: 180000 worker registry, 180100
nullable concentration score, 180200 generation token. The 220000 migration sorts
last. It adds evidence storage and never changes historical candles.

Workflow, Compose, PHP/Go Dockerfiles and deployment scripts have **no diff** from
the production baseline. The actual workflow publishes app/web from
`docker/php/Dockerfile`, then invokes the installed deployment wrapper. Its update
path builds the separate research-worker image from `docker/go/Dockerfile` when
the selected tag is absent; the PHP runtime image does not contain that binary.
In `docker/scripts/update.sh`, new-release migration (line 76) completes before
application replacement/restart (lines 78–79). Its earlier recovery branch restores
the existing release, not a new-schema application. Go research uses its separate
built image. This is source inspection, not deployment execution. Existing
`binance:initialize-paper --force` remains an independent deployment initializer;
it is not historical-candle remediation or pending-journal recovery.

The historical SQL checksum remains
`5a0c59a1c6f7f9c91e21ab9fe6b38bc3637e728d71fe79d1198f7b91b43e242b`.
No deployment, migration, schedule or application reference automatically executes
it. It contains mutations and a COMMIT and was **never run**. Four previously
reported BTCUSDT/ETHUSDT 30m/1h conflicts on October 27 remain **PENDING SEPARATE
OWNER AUTHORIZATION**; their current production state was not queried.

Full outgoing source/docs/decompressed evidence review found no unexpected scope
paths or credential/dump artifacts. Pattern checks are documented as limited
checks, not proof against every possible secret format. Included test credentials
are explicit synthetic fixtures. `.gitignore` adds only the reports exception and
`.opencode/` exclusion. Bybit schedule is unchanged. API unavailable/null semantics
and the health dashboard are covered by fresh tests/build.

The canonical roadmap now records **LOCAL VERIFIED** original fixes and **NOT READY**
V3 release blockers separately from **PRODUCTION PENDING** acceptance. No tracker
weights, criteria or requirement acceptance were changed. Offline scores remain
provisional 0% / 0% / 0%; Phase 0 remains BLOCKED and roadmap Phase 1 NOT STARTED.

## Safety and verdict

PostgreSQL 17 `bybit_test` is task-owned, tmpfs-backed, with no published ports and
an internal Docker network. PHP tests/subprocesses share only this isolated network;
exchange HTTP is faked. PHP 8.5.12 and Node 22.23.4 are recorded. The three labeled
V3 test resources (container, internal network, derivative image) were removed;
the shared base image and unrelated resources were preserved. No production connection/write, VPS
change, historical correction, real order, push, workflow dispatch or deployment
occurred. Existing account balances, positions, trades, funding and audit history
were untouched. The live census of 26 accounts was not re-inspected. ECONOM and
its resources were not accessed or modified.

**NOT READY.** The five original fixes pass, but R1, R2 and R3 remain release
blockers. Repair those focused paths and rerun their negative controls and the
final gate before requesting separate operator push authorization.
