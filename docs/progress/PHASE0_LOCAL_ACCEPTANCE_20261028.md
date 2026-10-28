# Phase 0 local acceptance continuation

Starting HEAD: `a30291e3681963f8a3da4a5d284b0679746e8b48`. Starting provisional scores: implementation 42.2882%, verification 4.1270%, product 0%. Scope remains `VECTOR-SCOPE-20261027-v1` (97 requirements, 229 obligations). No production operations are authorized or performed.

## Stage A — financial and risk assertions

Requirements: VEC-P0-003/004/104/106/111/112/203/207/208. Added precise RiskEngine positive/negative boundary cases and direct partial-exit statistics assertions. Added out-of-order funding settlement coverage with exact historical quantities, immutable prior settlements/executions, duplicate application and balance identity checks. Runtime financial/risk code and 5%/10% thresholds are unchanged.

The first focused PostgreSQL run passed 54 tests/277 assertions. The extended lifecycle run passed five tests/78 assertions. Full-suite admission and its exact case mapping are recorded in `evidence/phase0-local-20261028/stage-a-*.json` and JUnit artifacts. All runs use synthetic fixtures on task-owned PostgreSQL/Redis, PHP 8.5.12, UTC, no published ports and no external network. Commands, UTC timestamps, exit codes and SHA-256 digests are retained in `commands.jsonl` in that directory. The first Go build command used an older host Go that lacks `-C` and failed; the corrected command uses the previously documented Go 1.24.6 and passed. Both attempts are preserved.

VEC-P0-004-V1 and VEC-P0-111-V1 are the newly eligible local obligations. Previously admitted obligations must be rerun and appended because the frozen broad watch sets include changed tests. Prior evidence records and historical snapshots are retained unchanged. Narrow source implementation atoms are reaffirmed only after checking their referenced source bytes remain unchanged; this adds no implementation credit.

VEC-P0-003-V1 remains pending: its frozen atomic criterion requires reconciliation of the actual current account population, including historical late-funding cases. Synthetic identities cannot satisfy that clause. Production V2 and browser V3 evidence and product acceptance are not awarded by these tests.

The first full run had 917 passes, one failure and one opt-in public API skip (919 tests, 8,174 assertions). `ResearchBenchmarkCommandTest` failed because the isolated workspace lacked `research-go/bin/research-worker`: the worker had been built only at the integration test's `bin/research-worker` path. Copying the same freshly built binary to the command's expected path fixed the setup; the targeted command test then passed (one test, four assertions). The failed full JUnit remains `stage-a-full-failed.xml`; it is not used for PASS admission. No runtime workaround or test suppression was applied.
