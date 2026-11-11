# Stage 0 executed evidence

Status: **NOT_RELEASE_READY**. Source base:
`9615a620d693bf46d5465619e576db2e1e5b4016` plus the inherited changes captured in
`imported-source.patch` and `source-sha256.json`. No application source was
modified by Stage 0. Tests use synthetic disposable data, not production or
forward-paper records.

## Results

| Check | Environment | Result | Evidence |
|---|---|---|---|
| V8 source correspondence | Five imported paths | 5/5 hashes match | v8-hash-comparison.json |
| Full PHPUnit final baseline | PHP 8.5.12; canonical bybit_test on task-owned PostgreSQL 17.11 and Redis 7.4.11; loopback; prebuilt Go binaries | Exit 2: 1,462 pass, 80 failures, 40 errors, 1 optional skip; 19,218 assertions. See FINAL_BASELINE.md | phpunit-final.json, phpunit-final.xml, phpunit-final.log |
| Prior full PHPUnit attempt | Bridge network; noncanonical test DB; missing prebuilt DSL | Exit 2: 1,427 pass, 96 failures, 51 errors, 9 skips; 18,912 assertions | phpunit-full.json, phpunit-full.xml, phpunit-full.log |
| Focused signal analytics / V8 backend | PHP 8.5.12; PostgreSQL | Exit 2: 19 pass, one fixture error, 177 assertions, zero skips | phpunit-v8-focused.* |
| Frontend unit tests | Node 22.23.4 | Exit 0: 99/99 pass, zero skips | frontend-tests-corrected.* |
| Frontend vue-tsc + Vite build | Node 22.23.4 | Exit 0; 18.489 seconds total | frontend-build.* |
| Full Go race test | CI-matching Go 1.27.3; task-owned PostgreSQL schema | Exit 0: 131 PASS test/subtest events; zero test skips/failures; 19.065 seconds including compilation | go-target-race.* |
| Go vet / build | Go 1.27.3 | Both exit 0 | go-target-vet.*, go-target-build.* |
| Go formatting | Go 1.27.3 gofmt -l | Exit 0; no listed paths | go-target-format.* |
| PHP runtime/extensions | Container PHP 8.5.12 | Exit 0; required extensions present | php-runtime.* |
| PHP syntax (app/bootstrap/config/database/routes/tests) | Container PHP 8.5.12 | Exit 0: 945 files, zero failures | php-lint.* |
| PHP scripts and entrypoints syntax | Container PHP 8.5.12 | Exit 0: 36 additional files, zero failures | php-lint-scripts.* |
| Laravel Pint --test | Container PHP 8.5.12 | Exit 1: 28 style issues across 981 inspected files | pint.* |
| V7 fail-closed probes | Host PHP 8.4.24; dependency-free | Exit 0: 26/26 | standalone.* |
| V8 empty diagnostics probes | Host PHP 8.4.24; dependency-free | Exit 0: 2/2 | empty-diagnostics.* |
| Native exchange probes | Host PHP 8.4.24; dependency-free | Exit 0: 28/28 | exchange-native.* |
| Topology smoke | Host PHP 8.4.24; dependency-free | Exit 0: 11/11 | topology-smoke.* |
| Historical cost smoke | Host PHP 8.4.24; dependency-free | Exit 0: 11/11 | cost-smoke.* |
| Tracker unit tests after metadata update | Python 3 | Exit 0: 25/25 | tracker-tests-final.* |
| Tracker strict and scope preservation | Starting HEAD as base | Exit 0 | tracker-strict.* |
| Imported patch applicability | Actual HEAD; current inherited working tree | Forward check on materialized HEAD and reverse check both exit 0 | patch-forward-check.*, patch-reverse-check.* |
| Full browser / HTTP sweep / hosted CI / ten-venue operation | Not run | NOT_VERIFIED | No inferred PASS |
| Production / actual forward-paper performance / net improvement | No access or mutation | NEEDS_PRODUCTION_EVIDENCE / NEEDS_PAPER_FORWARD_EVIDENCE | No profitability claim |

## Interpreting failures

The focused export test attempts `$decision->update(...)` after a risk decision
has been persisted. `RiskDecisionRecord` correctly rejects that operation as
immutable. Seed the dangerous CSV text when creating the fixture; do not disable
model guards or rewrite a historical decision to make the test pass.

Full baseline failures must be triaged using the final JUnit and failure CSV.
Earlier results include setup prerequisites and are not a count of independent
application defects. New fail-closed edge/cost/model/manifest requirements may
also require valid, scoped fixture evidence. Do not restore permissive behavior
or remove assertions simply to make old tests green.

The first interrupted full PHP attempt used an internal network that blocked DNS
performed by SSRF validation, even behind mocked HTTP. It is retained as
`phpunit-corrected.*`, not a completed regression. The next full attempt used
noncanonical DB names and a bridge host, while some test fixtures require
literal `bybit_test` and loopback. The final setup corrects those prerequisites,
adds prebuilt Go binaries and makes Docker Compose CLI available **without a
Docker socket**. No tests were removed or weakened.

Early frontend execution used the wrong working directory/dependency lookup;
`frontend-tests.*` records that failed setup. `frontend-tests-corrected.*` is the
completed 99-test run. Early Go attempts used the old host compiler, unavailable
schema, or cached skipped tests. They receive no acceptance credit.
`go-race-postgres-fresh.*` is supplemental Go 1.18.1 evidence;
`go-target-race.*` reruns every package with `-race -count=1` on Go 1.27.3 and the
verified isolated schema. Packages without tests are not counted as skipped
tests. All failed/interrupted/superseded attempts are retained.

The original raw patch/logs and copied specification preserve intentional whitespace.
Full staged `git diff --check` reports those artifact bytes; source and authored
audit-document checks pass separately. This is disclosed, not a clean full-tree
whitespace claim.

## Reproduction and provenance

Each `<check>.json` records argv, UTC start, elapsed seconds and exit code.
`environment.json` records exact runtime and image identities. `run_php.py`,
`run_php_final.py` and `run_check.py` preserve the local invocation harness.
Paths and ephemeral ports identify this run and must be replaced with equivalent
task-owned resources when reproducing it. Do not reuse arbitrary existing test
or production services.

Start from the stated Git base in a separate checkout, apply
`imported-source.patch`, verify `source-sha256.json`, install locked dependencies,
and copy `.env.example` to the isolated copy's `.env`. Build the four Go commands
before PHPUnit; the exact Go binary-build commands are recorded separately.
Use canonical `bybit_test`, explicit `DB_URL=`, `TEST_DB_*`, task-owned loopback
PostgreSQL/Redis ports and a unique Redis prefix. No original `.env`, Docker
socket, production secret or production dataset is included.

`imported-source.patch` includes the inherited staged `.gitignore` delta and new
PHP source/tests so the runtime snapshot is reconstructible. Keeping this patch
as audit evidence does not accept or commit those files as verified application
changes. The original staged ignore change and all imported runtime changes
remain intact in the main working tree.

The older completion registry remains provisional: implementation 0%,
verification 0%, product acceptance 0% before and after this stage due to stale
or unavailable requirement-specific evidence. Notes and snapshots are updated;
no scope, weight, criterion, historical evidence or product acceptance is changed.

Check durations above are observed local test/build times, not realistic market
throughput, VPS latency or profitability benchmarks. Those remain Stage 6 work.
