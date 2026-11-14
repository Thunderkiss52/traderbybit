# Signal intelligence: implementation and evidence contract

This increment extends the existing research worker and profitability API. Laravel
remains the sole financial writer. The new Go computations and database records
are **HYPOTHETICAL research**, with `entry_authority=false`; they cannot activate
an account, promote a candidate, place an order, or change capital or risk limits.
It is not proof of net profitability, PAPER forward validation, or release readiness.

## Computation and causality

`research-go/internal/signalintel` evaluates versioned fixed-horizon markouts.
Source candle intervals and elapsed evaluation horizons are separate dimensions;
both accept 1, 3, 5, 15, 30 and 60 minutes. The default scheduler evaluates only
15- and 60-minute horizons to bound work. This does not add six independent signal
generation engines: existing strategy generation intervals remain authoritative.

The Laravel packet factory pins the recorded signal, its version, public market
data, instrument constraints, exchange-specific cost configuration and build SHA.
Signal availability is the later of decision time and recorded creation time.
Features must precede the decision. Instrument context must be fresh and recorded
without later creation/revision at that decision. Only closed, contiguous, scoped
one-minute candles available at the evaluation cutoff can produce a markout.
Conflicted candle windows fail closed through the existing durable integrity gate.
Late-imported labels are excluded from cohorts dated before their import.

The declared `NEXT_MINUTE_OPEN` anchor rounds availability forward to the next
executable minute; horizons run from that entry. It never backdates a fractional
minute signal. The engine's stricter `AVAILABILITY` anchor requires exact minute
resolution. Source timeframe, signal availability and execution anchor remain
distinct in persistence and reports. Malformed/overflowing times are rejected.
The source interval is parsed from explicit supported numeric minute strings
(`1`, `3`, `5`, `15`, `30`, `60`), their `m` suffix forms, or `1h`;
`1h` is 60 minutes. Historical outcomes whose
stored source interval conflicts with their originating signal, or whose signal
was changed after job submission, are excluded from new cohorts and horizon API
results without modifying the stored evidence. This conservative comparison does
not prove that signal metadata was immutable before submission. The horizon API
reports their count as `invalid_source_timeframe_count`; filters, including the
source interval, apply to the recorded fields. The count and page are separate
read statements and may observe concurrent imports at different instants.

This is not TP/SL counterfactual replay. The legacy P-3 replay prohibition remains
in force. Rejected directional signals may be observed without simulated PAPER
orders, financial journal writes, or fictitious realized returns.

## Economic outcomes

The research reference notional is 5 USDT of exposure, independent of margin and
leverage. It does not change any owner's account sizing choice. Quantity rounds
down; tick/quantity/minimum/maximum constraints can produce `UNFILLABLE` rather
than increasing exposure. Entry and exit slippage are embedded in executable
prices. Net outcome is `gross_pnl - fees + funding`; slippage is not subtracted
again. Both entry and exit fees use their respective executed notionals.

Funding uses an explicit configured settlement schedule, signed rates, mark prices
and complete expected settlements in `(entry, exit]`. Missing schedules, funding,
costs, gaps or future data cannot produce a validated return. The current packet
factory supports linear instruments with validated local data and explicit
zero-offset schedules; it does not infer missing venue capability from registration.

`MATURED` records contain exact decimal outcomes. `PENDING`, `INVALID_DATA` and
`UNFILLABLE` records have null monetary outcomes. Hypothetical results never enter
realized PAPER PnL or balances. A flat price can correctly lose after costs.

## Quality classification

`SIGNAL_QUALITY_HOEFFDING_V1` returns the seven specified classifications. It uses
bounded net returns, chronological overlap purging, configurable embargo, minimum
sample/day counts and a declared test-family adjustment. A single favorable or
unfavorable label is not confirmation. Cohorts preserve exchange, category,
strategy/version, symbol, direction, regime, source timeframe, horizon,
configuration/code versions, execution assumptions and label policy.

Intervals are explicitly **conditional** on independent observations, preregistered
return bounds and test family, fixed sample size without optional stopping, and a
representative population. Purging does not establish independence. The result
records those assumptions as unverified and grants no trading authority.

Default bounds `[-1, +1]`, alpha `0.05`, 100 tests and the 4,096-observation cap
are deliberately conservative. Even at the cap, the Hoeffding radius is about
0.06364 return; small economic edges cannot be confirmed at those settings. The
latest 4,096 raw observations can also span too few days before purging. Neither
limitation is evidence of profitability or permission to relax safety rules.
Policy/baseline hashes prevent comparing different confidence settings as if they
were the same experiment. Automatic prior-window baseline selection is not yet
implemented in the Laravel scheduler; the Go drift comparison requires an explicit,
nonoverlapping compatible baseline.

### Descriptive metrics and unavailable denominators

`descriptive_metrics` uses only validated, matured observations retained after
chronological overlap purging and embargo. It reports exact 12-decimal mean
winner/loser, net expectancy and return per signal, together with quantiles and
fixed return bins. These are fractions of executed notional, not USDT amounts.
Both cohort `profit_factor` fields remain null: observations do not supply the
validated PnL amounts required to sum winners and losers, and normalized return
ratios can misrepresent the result when executed notionals differ. The descriptive
field provides the explicit reason `OBSERVATION_PNL_AMOUNTS_NOT_PROVIDED` for an
observed population. Small samples retain descriptive values while their
statistical classification remains `INSUFFICIENT_DATA`.

The revised classifier uses policy version `SIGNAL_QUALITY_HOEFFDING_V2`, which
changes the cohort job identity and leaves historical profiles untouched. V1
packets are not accepted by the V2 classifier. The configured data code revision
does not independently attest the classifier binary; binary provenance remains
unverified. Legacy V1 profile responses withhold the misleading profit-factor
ratio and report an invalid effective classification with the historical
classification separately identified, because their source-timeframe attribution
is unverified. Their stored research evidence is not rewritten.

Directional accuracy and mean favorable/adverse excursions use stored outcome
fields, with separate observed counts for each metric. Missing values never
become false predictions or zero excursions. Precision, false-positive rate and
probability calibration remain null with explicit reasons when the corresponding
binary prediction population or decision-time probabilities for this target are
absent. A four-hour proxy model probability is not a fixed-horizon net-profit
probability, and one minus win rate is not a false-positive rate.

## Persistence and recovery

Migration `2026_11_11_000002_create_signal_intelligence_evidence.php` adds only
`signal_outcome_evaluations`, `signal_quality_profiles`, their lookup constraints,
immutable mutation triggers, and a partial research-job import index. It does not
modify historical financial rows. Database constraints prevent PAPER source labels,
unavailable monetary results and inconsistent net identities. Updates/deletes are
rejected in both Eloquent and PostgreSQL. Existing account history is authoritative.

The existing leased/fenced `research_jobs` worker owns `SIGNAL_MARKOUT` execution.
Each packet and result has immutable dataset/configuration/code identity; actual
cohort contents are bound to their dataset digest. Import checks the worker
manifest, raw report checksum, request/assumption/policy hashes, scope and cutoff.
Retries reuse evidence under transactional advisory locks. The import receipt lives
in research-job runtime metadata, separate from immutable outcomes. Recovery can
redispatch a missed cohort after an interrupted import without duplicate records.

Run migrations only on isolated test infrastructure during development. Production
migrations and deployment remain subject to the existing operator-approved workflow;
no automatic migration reversal or financial repair is introduced.

## Configuration and operational limits

`config/research.php` contains `signal_intelligence` settings. Observational
scheduling defaults on; missing immutable build metadata blocks submission.
An explicit `RESEARCH_SIGNAL_INTELLIGENCE_CODE_REVISION` may supply a pinned SHA
where `build-metadata.json` is unavailable. Never use a moving branch name.

The five-minute scheduler uses the existing research queue, overlap prevention,
bounded scans (8 signals by default, capped at 16), idempotent jobs and
non-authoritative scan cursors. Go packets are limited to 4 MiB, 512 candles or
4,096 cohort observations, 30 seconds and the existing worker resource contract.
It does not guarantee exhaustive evaluation when incoming signal volume exceeds
that budget. Missing-data diagnostics remain distinct from negative profitability.

## API and CI

Existing profitability endpoints retain their recorded PAPER source semantics.
Signal summaries now use one bounded SQL statement snapshot rather than loading
all signals and positions. Invalid cross-account/exchange links are excluded and
counted explicitly; observation-only decisions do not become trade rejections.
CSV drill-down adds data verification and invalid-link counts.

The additive read-only endpoints under the existing API boundary are:

- `GET /api/v1/research/profitability/signal-horizons`: paginated immutable outcomes,
  with exchange/strategy/version/symbol/direction/source-timeframe/horizon/signal/date
  filters. Default page size is 10; maximum is 100.
- `GET /api/v1/research/profitability/signal-quality-profiles`: paginated research
  classifications with exchange/strategy/symbol/timeframe/horizon filters.

Responses explicitly identify `HYPOTHETICAL` and deny entry authority. GET calls
do not schedule jobs or mutate trading state. Full UI integration and export of
these new records belong to the later dashboard stage.

CI builds the new `signal-intelligence` CLI alongside existing shared Go binaries
for PHP integration tests. Go CI supplies an explicit isolated PostgreSQL DSN for
new worker lifecycle tests. Existing mandatory suites and manual deployment
approval remain in place. See the implementation checkpoint for current executed
tests and unresolved full-suite findings.
