# Final Stage 0 baseline

**NOT_RELEASE_READY**. This is baseline verification of the inherited source, not accepted implementation.

Full PHPUnit: 1583 cases; 1462 pass; 80 failures; 40 errors; 1 skip. 19,218 assertions. Exit 2.
Elapsed: 707.952 seconds. PHP 8.5.12, PostgreSQL 17.11, Redis 7.4.11, canonical `bybit_test`, task-owned loopback endpoints, fresh source copy, prebuilt Go 1.27.3 binaries and Docker Compose CLI without a Docker socket.

The single skipped test is the opt-in real Bybit public API check (`RUN_BYBIT_INTEGRATION` was unset). All PostgreSQL/concurrency and Compose cases executed. See `phpunit-final-failures.csv`, raw log, JUnit and exact argv JSON.

## Leading error clusters

- 18: Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\PaperTrading\Infrastructure\Persistence\VirtualPositionRecord].
- 10: DomainException: Entry ineligible: missing_or_stale_validated_edge_or_cost_evidence
- 3: InvalidArgumentException: Exchange manifest validation failed: price_precision must be explicitly specified and non-negative.; qty_precision must be explicitly specified and non-negative.; min_qty must be explicitly specified as a positive numeric value.; min_notional must be explicitly specified as a positive numeric value.
- 2: ErrorException: Undefined array key "recommendation"
- 2: RuntimeException: Historical execution cost unavailable for binance: maker_rate.
- 1: Illuminate\Contracts\Container\BindingResolutionException: Target class [App\Strategy\Application\PaperExecutionEligibilityService] does not exist.
- 1: Illuminate\Database\Eloquent\ModelNotFoundException: No query results for model [App\PaperTrading\Infrastructure\Persistence\VirtualExecutionRecord].
- 1: ErrorException: file_get_contents(/app/docs/progress/vector-requirements.json): Failed to open stream: No such file or directory

These are failure clusters, not counts of independently proven application defects. Many older execution/model fixtures do not satisfy the imported fail-closed requirements; diagnose each before changing source or fixtures. Do not relax owner/risk/causality/immutability protections.

The completeness service dependency error and immutable risk-decision CSV fixture error are reproducible concrete fixes for Stage 1. The earlier strategy-input integrity and scheduler failures caused by the noncanonical database setup are not carried forward as confirmed runtime defects.

The fully prepared run improved the earlier result by 27 unsuccessful cases and eight infrastructure skips. The remaining 120 failing/error cases block release.

## Other gates

- Frontend: 99/99 pass; typecheck/build pass.
- Go 1.27.3: full race run, 131 pass test/subtest events, zero skipped tests; vet/build/format pass.
- PHP syntax: 945 application/test/config/migration files and 36 additional scripts/entrypoints pass.
- Pint: 28 style issues remain; exit 1.
- Tracker: 25 tests and strict validation pass; provisional I/V/P scores remain 0% / 0% / 0%. No scope/acceptance credit invented.
- Runtime-source diff and authored audit-document whitespace checks pass. Full staged whitespace check reports original raw patch contexts/log formatting and copied input Markdown hard breaks; bytes are retained as evidence, not rewritten. See `diff-check-with-raw-evidence.*`.
- Browser acceptance, full HTTP route sweep, hosted CI, production account/ledger reconciliation, ten-venue operation, realistic performance benchmarks and forward profitability remain unverified.

Stage 0 changes documentation, evidence and progress metadata only. All inherited runtime files and the original staged ignore patch are unchanged. No push, deployment, production mutation, exchange orders or unrelated Docker/project operations occurred.
