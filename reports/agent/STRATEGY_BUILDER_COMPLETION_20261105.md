# VECTOR strategy builder: integrated implementation and evidence

Date: 2026-11-05. Continuation baseline: `115edd935f8e66439c30ce3584863e9cb1ad28d3`; original mission baseline: `37777f2c03fddcbcbb4cb4688e222a7267ef62d8`. Local checkout only. This supersedes the incomplete-feature descriptions in `STRATEGY_BUILDER.md`; that report remains historical evidence.

## Implemented paths

Owner UI: **`/strategies`**. Existing `/research`, all 13 compiled strategies, exchange onboarding, migration registry, immutable dataset authorities and Laravel PAPER accounting remain available.

- Durable drafts survive reloads, use optimistic revision checks, and publish only the exact confirmed revision/checksum. Published versions cannot be edited. Clones increment versions; a rule comparison shows changed paths.
- Visual composition supports named indicators, indicator timeframes, BUY/SELL, recursive AND/OR/NOT, comparisons, regime filters, ATR stops/targets, timeout, cooldown, one partial target, funding calendars and bounded optimization ranges. Advanced JSON uses the same validators. Invalid edits never become executable rules.
- Go executes research and supported forward PAPER signal decisions. PHP validates, authorizes, pins inputs/executables, dispatches research, and retains ledger/risk authority.
- Historical results show metrics, equity curve, trade/execution evidence, funding and costs. An existing dataset pin can be selected; a scope/warmup mismatch rejects the run. No incomplete-coverage override exists.
- Optimization uses the existing chronological splitter, research queue, autonomy capabilities and kill switch. Experimental versions never replace the approved parent. Shadow observations are separately recorded and do not create accounts.
- Technical PAPER evidence, owner PRIMARY approval, version activation, initial virtual funding and initial account authorization are separate operations. Every new account starts zero-funded and BLOCKED.

## DSL contract and deterministic semantics

Required fields: `schema_version`, `id`, `version`, unique `symbols`/`exchanges`, primary `timeframe`, named `indicators`, `entry`, `stop_atr`, `take_atr`, `risk_percent`, `timeout_bars`, `cooldown_bars`. IDs match `user_[a-z0-9_]{1,48}`. JSON integers, numbers, strings and booleans are checked explicitly; executable code and unknown fields are rejected in both PHP and Go. Schema discovery: `GET /api/v1/strategies/schema`.

Schema **v2** uses an explicit bounded indicator window, identical at each historical and moving forward decision. The window is at least 220 bars and includes declared maximum optimization periods and MACD warmup. Complete warmup is required independently on all four existing dataset frames (5/15/30/60m). Primary bars must close at the decision; higher frames are selected by close time, never open time. Only closed, contiguous, correctly identified exchange/category/instrument bars enter the evaluator. Missing values propagate readiness failure through NOT/AND/OR rather than manufacturing signals. Simultaneous BUY and SELL matches produce no entry.

Schema v1 remains research-readable with legacy prefix indicator semantics and is ineligible for PAPER. New authoring emits v2. Each v2 publication validates against and archives the trusted server Go executable. Versions, candidates and PAPER accounts pin that SHA-256; later configuration/binary replacement does not silently switch their evaluator. Missing or altered archives block evaluation. The archive lives under persistent application storage and must be retained with backups; it contains server-built binaries, never user uploads.

Indicator types: SMA, EMA, RSI, ATR, ADX, MACD line/signal/histogram, Bollinger upper/lower, Donchian high/low, volume SMA, PRICE (close), OPEN/HIGH/LOW/VOLUME/TURNOVER/BODY/RANGE. Indicators declare integer period and timeframe; MACD optionally declares slow/signal periods, Bollinger optionally declares deviation. Existing indicator implementations are reused; extended calculations remain deterministic.

Condition forms: `{"and":[...]}`, `{"or":[...]}`, `{"not":...}`, or `{"op":"lt","left":"rsi","right":65}`. Operators: gt/lt/gte/lte/crosses_above/crosses_below/within/change_pct. Right operands are indicator names or numeric constants; within uses an ordered two-number inclusive range. Percentage change compares consecutive available closed indicator values. Nesting is limited to 8, groups to 16 children, indicators to 32.

Optional fields: `exit` BUY/SELL conditions; `regime` condition; `partial_exit:{atr,fraction}`; `funding:{enabled,interval_hours}` (1/4/8h); `parameters:{path:{default,min,max,step}}`. Defaults must match the definition; bounds are recursively validated. Integer periods/timeouts require integer steps; numeric ATR/risk values accept fractional steps even after JSON persistence. Maximum risk per trade is 0.5%.

Research entries use the next bar open with configured adverse slippage and commissions. Rule exits use the following open; the replay policy extends the existing Go engine, including conservative stop-first ordering, partial legs, timeouts and forced end-of-data close. Funding requires every settlement rate and positive mark strictly inside the declared window; signed funding cash flows are attributed only to positions held across settlement. Equity and per-trade cash identities include fees and funding. Reported risk-adjusted return is an observed-period Sharpe, not an annualized estimate. Monte Carlo uses the existing simulator, fixed seed and bounded iterations when at least 10 trades exist; otherwise it explicitly reports insufficient trades. Research results are not forward PAPER performance or profitability evidence.

## English and Russian authoring

The deterministic grammar accepts explicit long/short EMA crossover and RSI threshold rules, ATR stop/target, risk and primary timeframe. Optional higher-timeframe confirmation must specify both EMA periods and comparison, e.g. `Confirm EMA 50 above EMA 200 on 1h.` Russian equivalents, decimal commas and common `is below`/`Stop Loss is` expressions are normalized. Vague trend descriptions, missing stop/risk/timeframe and unsupported indicators fail explicitly. No unmentioned trend rule is invented.

Base example:

> Create a strategy for BTCUSDT. Enter long when EMA 20 crosses above EMA 50 and RSI below 65. Stop Loss 1.5 ATR. Take Profit 3 ATR. Risk 0.5%. Timeframe 15m.

The preview explicitly lists defaults: RSI/ATR 14, Bybit linear, 48-bar timeout, no cooldown, and complete 8h funding. The owner confirms these assumptions. Add the explicit confirmation clause before Stop Loss for an EMA 50/200 1h filter. Larger periods require more warmup; coverage is never shortened to accommodate them.

Hosted authoring is optional and disabled by default. Configure `STRATEGY_AUTHORING_HOSTED_ENABLED`, `_DRIVER`, `_URL`, `_MODEL`, `_TOKEN` through the existing environment/secret mechanism. Driver `gateway` posts `{model,description,schema_version,instructions,schema}` to an operator-configured HTTPS gateway returning `{definition,ambiguities}`. Driver `chat_completions` posts system schema/example plus the owner description to a configured HTTPS Chat Completions endpoint, requests JSON, disables response storage and tools, and rejects refusal/truncated/malformed responses. The wire contract was checked against [official OpenAI API documentation](https://developers.openai.com/api/reference/resources/chat/subresources/completions/methods/create). Choose a configured model supporting JSON mode; no model is downloaded locally.

Both drivers require explicit per-request consent. Only the authoring description and public schema/example are sent; no database account state, credentials, private history or runtime signals are assembled for the provider. Provider credentials stay server-side. The owner must not paste secrets into a description. Returned JSON passes the same strict validator, creates no saved version or account, and requires confirmation/publication. Unavailable providers leave deterministic authoring and visual editing usable. No external provider credentials were used in this session: transport/validation paths were tested with HTTP fakes, not a billed remote inference.

## Optimization and shadow lifecycle

The owner declares parameter ranges in an immutable definition, chooses a complete bounded dataset scope, and requests optimization. An immutable baseline run and executable identity are pinned first. At most 32 grid candidates and 3 chronological windows are accepted; at most 2 active custom optimization experiments are admitted under an advisory lock. The default budget is smaller (8 candidates, 2 windows).

Train/validation/test windows include a holding-horizon embargo. Candidates train and validate independently; validation average R minus 0.5 times drawdown ranks candidates deterministically. Only the selected candidate is evaluated on that window's held-out test segment. Test windows cannot overlap. Every candidate has immutable parameters, scorecards, statuses, pinned run IDs, execution evidence and Monte Carlo results where the sample permits. Insufficient samples remain rejected; no profitable candidate is assumed. Cancellation and the kill switch are checked between phases.

Jobs use the existing research queue, overlap protection, a 30-minute worker timeout, a durable lease, bounded evaluation attempts and terminal failure audit. Queue exhaustion clears active status; it cannot leave a successful-looking result or change PAPER rules. The worker's queue visibility timeout should exceed the job timeout in an operator-managed deployment; duplicate delivery is fenced. Process-kill recovery at maximum resource limits was not exhaustively stress-tested for this new job.

A selected candidate can request forward shadow only when `launch_shadow` is permitted and its profile is supported. Shadow subscriptions honor the existing configured experiment limit and start at server time, never replay history. Evaluations are idempotent by version/exchange/instrument/decision time; missing data produces UNKNOWN, not invented performance. Shadow currently records forward decisions, not an independent second virtual ledger.

## PAPER governance and capital preservation

PAPER-supported custom profile: v2, 15m primary, all selected indicators/boolean/regime rules, ATR stop/final target, position timeout, complete 8h funding, zero signal cooldown, no custom rule exit or partial target. Other research capabilities explicitly block PAPER because Laravel position-lifecycle parity has not been established. New research exchanges without reviewed PAPER execution adapters also remain ineligible.

For every declared exchange/instrument, run a complete funded backtest and certify real historical/forward Go decision samples with matching side/stop/target. Pin and executable integrity are rechecked. This is technical evidence, never owner consent or statistical proof of profitability. Binance's existing provisioning/execution approvals remain independently enforced.

Owner PRIMARY approval requires the exact immutable checksum, an audit reference and explicit confirmation. Under transaction/advisory/global-entry locks, topology must already be complete. Provisioning is additive and rolls back if any old PRIMARY row changes. The tested two-exchange example produces 14 Bybit + 14 Binance = 28 accounts; the original 26 account rows/statuses stay byte-for-byte unchanged. New accounts are zero-funded/BLOCKED with pinned version/parameters/executable.

Authorizing the strategy version does not fund or unblock any account. Initial funding is a separate audited, idempotent virtual capital event and equity snapshot for a fresh zero-capital custom account. Initial account authorization is another explicit action through existing risk/exchange checks. Original accounts and accounts with trading/drawdown history cannot be resumed or reset here. Existing global entry/emergency ceilings remain 5%/10%; custom funding cannot increase the original compiled cohort's risk denominator or dilute its protected loss ceiling. Custom losses and reserved risk still count against that budget.

Existing versions remain account-pinned. A newly approved version requires a separate explicit handover and flat accounts without pending intents. Handover changes only the custom account's current version/configuration, preserving history, balances and BLOCKED status. Exchange topology changes require a separate strategy; version handover cannot silently add/drop exchange scopes. Archival/rejection disables decisions/shadow while retaining registrations/accounts and servicing existing positions.

No path in the authoring/research/Go/PAPER adapter places real exchange orders. The real forward execution test asserts no outbound HTTP calls. No operational LIVE toggle, trade credential or exchange order API was added.

## Complete owner workflow

1. Open `/strategies`, enter the configured research owner token and load strategies. The token remains in component memory. Existing authentication uses one configured owner token; per-person identities/roles are not introduced.
2. Build the EMA 20/50 + RSI <65 rule visually or interpret the base description above. Give it a unique `user_` ID. Optionally add an explicit higher-frame EMA rule, SELL side or declared optimization ranges. Review readable conditions and funding assumptions.
3. Save a durable draft. Reload/resume if needed. Confirm interpretation, validate, then publish the exact saved revision; alternatively save a confirmed immutable version directly.
4. Select the saved version. Choose exchange/instrument and an existing pin, or enter a complete UTC range and create a strict pin. Enter virtual capital and run the Go backtest. Browser verification used synthetic `2026-01-15T00:00:00Z` through `2026-01-16T00:00:00Z`; these dates are not a claim about production data availability.
5. Inspect equity, trades, costs, funding and run metadata. Repeat the same request to verify its run identity. Missing warmup, candle gaps, costs or funding reject execution. Use Historical results to inspect earlier runs.
6. For optimization, publish bounded ranges first, set chronological windows/candidate budget and request optimization. Inspect selected/rejected candidates and held-out results; the original version remains unchanged. Shadow needs independent autonomy permission.
7. For PAPER submission, select each declared exchange/instrument run and verify Go/PAPER parity. Refresh eligibility. Supply an owner audit reference, check PRIMARY confirmation and submit. The new accounts remain zero-funded/BLOCKED.
8. Separately authorize the approved version for forward decisions. If desired, separately fund a fresh custom account with virtual capital and authorize it under current risk gates. Do not use this flow to resume any existing risk-blocked account.

## Authenticated API surface

All paths below are under `/api/v1/strategies` and require `research.token`; request bodies are strictly validated. Standard error responses include the existing request ID and validation semantics.

| Method | Path | Purpose |
|---|---|---|
| GET | `/`, `/schema`, `/datasets` | Paginated versions/built-ins, schema, immutable dataset choices |
| POST | `/parse`, `/validate`, `/` | Authoring, DSL validation, confirmed immutable save |
| GET/POST | `/drafts` | Paginated durable drafts / revision-checked save |
| GET | `/drafts/{uuid}` | Recover draft |
| POST | `/drafts/{uuid}/publish` | Exact revision/checksum confirmation |
| GET | `/{id}` | Immutable version, accounts and audit |
| POST/GET | `/{id}/backtests` | Real Go replay / paginated runs |
| POST/GET | `/{id}/optimizations` | Queue bounded experiment / paginated experiments |
| GET | `/optimizations/{hash}` | Status, scorecards and candidates |
| POST | `/optimizations/{hash}/cancel` | Audited cancellation |
| POST/GET | `/{id}/shadow` | Start/stop subscription / forward observations |
| GET | `/{id}/eligibility` | Technical evidence versus owner authorization |
| POST | `/{id}/evaluate-paper`, `/{id}/approve-paper`, `/{id}/activate-paper` | Certify, approve PRIMARY, separately authorize version |
| POST | `/{id}/accounts/{uuid}/fund`, `/{id}/accounts/{uuid}/authorize` | Separate initial allocation/authorization |
| POST | `/{id}/lifecycle` | Audited research/rejection/archive transition |

Critical API paths are exercised by PHPUnit and real Chromium. The browser script forwards to a real isolated Laravel API and invokes the actual queued worker; API responses are not mocked. The hosted provider boundary alone uses HTTP fakes. Lifecycle, cross-account funding rejection and version handover have API regression coverage.

## Verification and remaining limits

Final command results, environment, hashes and logs are recorded in `strategy-builder-20261105-evidence/manifest.json` and companion artifacts. This report must be read with those executed results; synthetic fixture trades are not profitability or production acceptance.

No production database, VPS, exchange account or forward-performance history was accessed or mutated. Production account census, operational funding completeness, real-market returns and operational SKIP sample sizes are unverified. No push or deployment occurred. ECONOM resources and the two pre-existing untracked audit artifacts were left untouched.

Remaining explicit capability limits: research window <=7 days per run; bounded candidate/window budgets; ATR-based stop/target and one partial research target; constrained deterministic grammar; no unrestricted local LLM; hosted remote inference unverified without owner configuration; shadow decision observations do not yet claim realized PnL; PAPER excludes custom exits/partial targets/cooldown and non-15m/non-8h profiles; two reviewed PAPER exchanges only. Legacy v1 runs predating executable archival cannot retroactively acquire archived binaries.

The wider VECTOR frozen scorecard remains provisional with its existing Phase 0 evidence blocker. No frozen criteria/weights or production/product completion claims were changed for this local implementation.

## Local commits

The evidence manifest records all strategy-builder commits, including this continuation's authoring, Go replay, research/governance, UI integration and verification changes. No commits were pushed.

### Recorded verification results

| Check | Executed result |
|---|---|
| Full Laravel/PostgreSQL/Redis regression | Exit 0; 1,271 tests, 11,981 assertions, no failures/errors; 8 skipped, 6 notices |
| Final strategy-builder tests | Exit 0; 21 tests, 146 assertions, no skips |
| Go-worker integration rerun | Exit 0; 4 tests, 59 assertions (all 3 compiler-skipped cases recovered) |
| Compose image-resolution rerun | Exit 0; 6 tests, 23 assertions (all 4 CLI-skipped cases recovered; no Docker socket) |
| Frontend unit tests | Exit 0; 72 tests |
| TypeScript + production frontend build | Exit 0 |
| Chromium E2E | Exit 0; durable draft reload, visual editing, confirmed publication, complete funded Go replay/repeat, parity, owner PRIMARY approval with BLOCKED zero-capital account, queued optimization; zero page errors |
| Go race suite / vet / formatting | Exit 0 / exit 0 / no gofmt differences |
| Static Go Docker build stage | Exit 0 |
| PHP lint / Laravel Pint | Exit 0 / exit 0 (813 files) |
| Scorecard strict / tracker unit tests | Exit 0 / 25 passed; provisional scores unchanged |

The final strategy-only run covers the three new test methods added after the full-suite discovery snapshot, plus the latest handover/archive assertions. Full and supplemental counts are separate rather than combined into an invented single run. The public Bybit HTTP integration test remains opt-in and unrun. Hosted external inference, full production image deployment and production financial acceptance remain unverified.

Initial full-regression failures (view-cache directory, compiled-account configuration semantics, and implicit-time idempotency) are preserved in `initial_regression_failures.log`; the corrected run and supplemental checks passed. The six PHPUnit notices are pre-existing test-runner notices, not ignored failures.

Code commit list:

- `a07946d9a2c52cb10d5bcff0d753a8bc8023d22f` docs(strategy): map builder compatibility and safety boundaries
- `2d15997683daa595dba6fe8e54efc519241be27c` feat(strategy): validate and persist immutable DSL versions
- `d6edcfd1bdd505277ac525ca62330ebcb4b72703` feat(strategy): execute causal DSL research through Go
- `ad269ce4ce383c7c6cf2dc23c1a586912a300124` feat(strategy): add bilingual authoring and visual EMA RSI builder
- `010eca054f0e5f454789f2a1f8ec92ef5fe9753a` feat(strategy): integrate authorized management and pinned Go backtests
- `99eaa696f58ceff7fc0aabe7a1b5bdfc678d130b` fix(strategy): fence research snapshots and preserve PAPER governance
- `01eb837aad98663a507faa900caa19c150358dea` test(strategy): isolate onboarding journals from research regression
- `6b33a0398d2e1c1109bc64c2bb7f5b53e4cf4ddd` build(strategy): package Go interpreter and verify browser research flow
- `7e5683187f69ab6781759f9289fb15dcc568573e` test(market-data): freeze recovery window across immutable retries
- `70a989493ac20dc192fa194662d0ae8973b2a217` fix(strategy): reject associative instrument and exchange lists
- `115edd935f8e66439c30ce3584863e9cb1ad28d3` docs(strategy): record builder evidence and remaining mission gaps
- `45f4d1b62ef619ccdfa53786770f2f2b6fecc9f6` feat(strategy): persist authoring drafts and gate hosted interpretation
- `905ae2381116335c4543e16550e227c90f77eae6` feat(strategy): execute advanced rules partial exits and pinned funding in Go
- `1fefe73b9983b34ea35b5040b5aeea5efe3eaa36` feat(strategy): integrate bounded research and owner-approved Go PAPER execution
- `471d99e4371e60f6e8c443d0000d043cfa28fa63` fix(research): preserve implicit as-of across idempotent decision retries
- `a3c0176f70b1098a960b89d71f167dbf96ca409e` fix(strategy): harden authoring research retries and account version governance
- `6637bbd77ca0d8f75294ae9e0a78800c1d94fb70` feat(strategy): expose complete authoring research and PAPER approval workflows
