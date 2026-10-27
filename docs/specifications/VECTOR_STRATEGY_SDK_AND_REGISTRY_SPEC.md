# Strategy SDK and registry specification

Status: proposed after Phase 0; no SDK implementation in this cycle. See [architecture](../architecture/VECTOR_STRATEGY_PLATFORM_ARCHITECTURE.md) for source baseline.

## Existing contract and compatible extension

`app/Strategy/Contracts/StrategyInterface.php` already defines `id`, `version`, `description`, `parameters`, and `evaluate(StrategyInput): SignalDecision`. Keep this callable interface. `StrategyRegistry::make()` currently dispatches family constructors explicitly, including dependencies for regime switching and support/resistance. Do not replace those dependencies with reflection or instantiate arbitrary classes from JSON.

Proposed `app/Strategy/Domain/StrategyDescriptor.php` is immutable metadata; `app/Strategy/Contracts/StrategyFactory.php` constructs only source-controlled allowlisted implementations. Proposed `app/Strategy/Application/StrategyManifestService.php` validates descriptors and creates version manifests. Existing registry delegates to a generated build-time map, rejecting duplicate IDs/version conflicts at build and startup. Reading a registry must not provision accounts.

Lifecycle: authored → contract-validated → immutable registered version → research enabled → governance admitted → PAPER active → retired. Retirement disables new admissions/entries, retains lookup, open-position servicing and historical views. Invalid versions never become active by fallback to another version.

## Descriptor and schema contract

Required fields: `schema_version`, `strategy_id` (stable slug), `display_name`, `description`, `semantic_version`, `implementation_ref` (allowlisted build artifact reference), `source_manifest_sha256`, `parameter_schema_version`, `defaults`, `parameters`, `required_series`, `required_context`, `supported_instruments`, `supported_exchanges`, `execution_model_version`, `indicator_versions`, `dependencies`, `research_capabilities`, `risk_policy_ref`, `documentation_ref`.

Each parameter declares name, type (`integer`, decimal-string, boolean, enum), unit, default, min/max or enum values, step, nullable policy, research-mutable flag and cross-field constraints. Reject unknown keys, numeric overflow, NaN, off-grid decimals, inconsistent EMA periods and invalid stop/target order. Defaults must pass the same validator. Risk ceilings, exchange identity, source code and admission flags are not mutation parameters. Existing ranges are copied from reviewed `config/research.php`, never guessed for the five families without evolution spaces.

Illustrative schema fragment, not a change to current defaults:

```json
{"name":"stop_atr","type":"decimal-string","unit":"ATR_MULTIPLE","default":"1.5","min":"1.0","max":"2.5","step":"0.25","research_mutable":true}
```

Requirements specify interval, minimum bars computed from indicator warm-up, closed/contiguous policy, maximum as-of age and context capability. Declare cross-symbol dependencies for `btc_eth_lead_lag`, historical funding/OI availability for squeeze, and child-version dependencies for regime switching. Exchange support is conditional on verified capabilities and instrument metadata, not just a string in a list.

## Immutable identity

Canonical encoding sorts object keys recursively, preserves list order and serializes decimals as normalized strings with a declared scale rule; include a canonicalization version. Hash the source dependency manifest (strategy, indicator implementations, composition children, execution semantics and relevant lockfile/build revision), schema, resolved parameters and versions with SHA-256. A Git SHA alone is not an executable identity if the tree is dirty; registration refuses dirty or unresolvable dependency artifacts.

Version labels are human-facing; full hashes are authoritative. Same label/different content is a conflict. Store existing `configuration_hash` as legacy identity alongside the new manifest hash; no retroactive replacement. Proposed `strategy_versions` holds unique `(strategy_id, semantic_version)` and unique manifest hash, canonical JSON, artifact digest and registration evidence; `strategy_version_events` appends retirement/admission references. Database constraints and model guards prohibit updates to immutable content. Historical unknowns remain unknown.

## Input, output and exit semantics

Reuse `StrategyInput`, `SignalDecision`, `PricePlan` and indicator DTOs. Input adds immutable dataset/context references, decision/as-of times and quality state without changing historical meaning. Evaluation cannot access network, database, mutable clock, account balance or global RNG. All source times must be available by decision time; pivot confirmation and warm-up must be causal. Missing requirements return SKIP with structured codes.

Decision contract retains BUY/SELL/SKIP, ID, schema/version, exchange/category/symbol, timeframe, decision time, validity window, price zone/stop/targets, risk model hint, reasons/risks and indicator snapshot. Add manifest hash, parameter hash and input package hash as versioned optional fields before making them mandatory for new versions. Decimal financial fields remain strings. Deterministic ID includes full immutable version identity and qualified instrument/time; historical IDs remain unchanged.

Exit policy is a versioned declaration consumed by existing `PositionLifecycleService`: stop, TP fractions, timeout and conservative same-bar precedence. Strategy logic cannot directly close positions or mutate risk. If an optional pure exit recommendation is later required, use an approved `ExitDecision` with `HOLD/REDUCE/CLOSE`, position reference, as-of time, reason and policy version; lifecycle verifies eligibility/quantity and owns execution. Initially use existing exit semantics only.

## Developer workflow and generator

1. Run proposed `php artisan strategy:make <slug> --template=research` locally; reject duplicate/invalid slug and path traversal, never overwrite files.
2. Implement approved strategy logic; declare parameter schema/defaults, requirements and dependencies.
3. Generate/review the registry map at build time; no unrelated controller/page/account edits.
4. Run common strategy contract suite and family-specific fixtures.
5. Run frozen-dataset backtests and PHP/Go parity where Go is supported.
6. Register immutable version through a future authenticated CLI with artifact checks.
7. Enable bounded research in Candidate Lab; availability does not imply PAPER eligibility.
8. Submit evidence to existing governance; explicit PAPER admission is a separate operation.

Generator outputs proposed `app/Strategy/Research/<Name>Strategy.php`, `resources/strategies/<slug>.json`, `tests/Unit/Strategy/<Name>ContractTest.php`, `docs/strategies/<slug>.md`. Skeleton must be clearly non-admitted and fail its unfinished behavior tests; it must not produce fabricated trades or auto-register as PRIMARY. It runs no network calls, migrations or shell content supplied by the user. Schema-derived docs describe parameters and requirements, not profitability. `--dry-run` lists intended files. Generator is useful because the current registry has multiple family-specific constructors; generation must preserve explicit factories rather than hide dependency wiring.

## Integration and acceptance

Reuse backtest `StrategySignalEvaluator`, research `StrategyEvolutionService`, PAPER registry consumers, observation/audit exports and existing strategy metadata API. Proposed read endpoint `GET /api/v1/strategies/{id}/versions` exposes schema and evidence state; registration remains authenticated write/CLI only. Existing pages show new registered descriptors automatically but keep research, PRIMARY and retired filters distinct.

All 13 legacy IDs require golden fixtures: baseline, trend_pullback, bollinger_mean_reversion, donchian_atr_breakout, regime_switching, momentum, support_resistance_bounce, support_resistance_breakout, funding_open_interest_squeeze, liquidity_sweep_reversal, volatility_compression_breakout, btc_eth_lead_lag, multi_timeframe_trend_following. Assert byte-identical legacy decisions/IDs/parameters before and after adapter introduction; no account recreation or balance changes. New hashes may be additional metadata only.

Contract tests cover BUY/SELL/SKIP, invalid parameter bounds, missing/stale/cross-exchange inputs, future candles/context, incomplete warm-up, deterministic repeated evaluation, child-version changes, no I/O, duplicate registration races and immutable rows. Frontend tests cover unsupported research capability and retired version drill-down. Generator tests cover traversal, collisions, dry-run and no side effects. Admission failure remains visible with reason and no fallback. Registry cache rebuild is deterministic; malformed manifests block only new registration, never rewrite deployed historical identities.

Performance gate: compare identical 13-strategy fixtures to Phase 0; no more than 5% P95 evaluation regression, no new per-candle database queries. Roll back descriptor dispatch to the preserved registry path for future decisions only; retain registered versions and all history. Visual builder is deferred: only bounded, typed, versioned approved operator/indicator ASTs, never executable expressions or uploaded code.
