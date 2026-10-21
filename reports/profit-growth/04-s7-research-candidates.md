# §7 — Five research candidate cohorts (Go research implementation)

**Status:** `IMPLEMENTED` + `VERIFIED` (engine, golden parity, look-ahead controls, real-data evaluation on the local archive)
**Evidence:** `reports/profit-growth/S7_evidence.json` (SHA-256 `316f05df4f869e9ae141afe8ea8d958793721080152b8013595a1d00b0e00efe`)
**Paper accounts touched:** none. **Exchange order path:** none. **Trade credentials:** none.

## 1. Scope and boundary

The five §7 hypothesis families are implemented as **research cohorts** in
`research-go/internal/candidates/`. They are *not* new primary paper accounts: no account, ledger,
strategy or deployment unit was created, read or modified. The eight original primary paper
accounts and their strategies are untouched.

| Cohort | Family | Executable | Registry status |
|---|---|---|---|
| `funding_open_interest_squeeze` | funding + open interest crowding, reversal (default) and continuation (challenger) | yes | `RESEARCH_COHORT` |
| `liquidity_sweep_reversal` | prior-known S/R sweep and recapture | yes | `RESEARCH_COHORT` |
| `volatility_compression_breakout` | precommitted volatility compression + confirmed close breakout | yes | `RESEARCH_COHORT` |
| `btc_eth_lead_lag` | cross-symbol lead/lag (research signal only) | **no** | `RESEARCH_COHORT` |
| `multi_timeframe_trend_following` | higher-timeframe trend + lower-timeframe confirmation | yes | `RESEARCH_COHORT` |

## 2. Implementation

| File | Content |
|---|---|
| `research-go/internal/candidates/types.go` | `Bar` (open/close/**known_at**), `FundingEvent` (Bybit sign convention: positive rate ⇒ longs pay), `OpenInterestEvent`, `Dataset`, `Signal`, `Candidate`, causal lookups (`lastKnownBarIndex`, `countKnownBars`, `lastKnownFunding`, `openInterestAt`), `meanTrueRange`, `stopAndTarget` |
| `research-go/internal/candidates/funding_open_interest_squeeze.go` | crowding reversal (default) and continuation mode; funding/OI mandatory |
| `research-go/internal/candidates/liquidity_sweep_reversal.go` | rolling support/resistance of a window that **ends before** the decision bar (no hindsight pivots) |
| `research-go/internal/candidates/volatility_compression_breakout.go` | mean-true-range compression (recent window vs. baseline window) + close-confirmed breakout, intrabar ambiguity handled by the shared engine |
| `research-go/internal/candidates/btc_eth_lead_lag.go` | peer alignment on close **and** known time, staleness limit, research signal only |
| `research-go/internal/candidates/multi_timeframe_trend_following.go` | HTF EMA trend from fully closed HTF candles + LTF EMA cross / breakout confirmation |
| `research-go/internal/candidates/lookahead.go` | §7.7 mechanical controls: HTF closure, causal mutation invariance, negative control, previous-bar cutoff |
| `research-go/internal/candidates/registry.go` | versioned registry of the five cohorts, hypothesis text, executability |
| `research-go/internal/candidates/pipeline.go` | common versioned pipeline: deterministic train/validation/test split, shared cost model, honest status values |
| `research-go/internal/backtest/signal_runner.go` | `Engine.RunSignals` — replays candidate signals with the **same** cost model, risk sizing and conservative ambiguity rule as `Engine.Run` |
| `research-go/internal/auditdata/loader.go` | loader of the local archive with derived `known_at` (candle ⇒ close time, funding ⇒ funding time, OI ⇒ observation time), gap detection, no filling |
| `research-go/cmd/research-candidates/main.go` | CLI that evaluates the five cohorts and writes the evidence file |

## 3. §7.7 look-ahead controls (mechanical, per candidate)

| Control | Implementation | Result on the local archive |
|---|---|---|
| HTF candle closure | `lastKnownBarIndex` + `countKnownBars`; a HTF bar is usable only if `close_time <= decision` **and** `known_at <= decision` | pass (5/5) |
| Causal indicator test | every bar/event with `known_at > t` is mutated (×1.5); all decisions at `t <= cutoff` must be unchanged (side, reason, stop, target) | pass (2 cutoffs × 5 candidates) |
| Negative control | deliberately leaky candidate (stop/target derived from the next bar) **must** make the causal test fail | detected (5/5) |
| Previous-bar cutoff | decision at bar *i* must be reproducible from a dataset truncated at bar *i* | pass (3 checks × 5 candidates) |

Golden parity tests exist for every candidate (`candidates_test.go`), including exact signal counts
and sides for crafted scenarios.

## 4. Real-data evaluation (local archive `audit_data/`, read-only)

Source hashes (SHA-256) are recorded in `S7_evidence.json`:
`market_candles.csv` `1434bb15d95ae83a8252773d81ddaa2a2e3734d023e6d9ed08256aeaafa96650`,
`market_funding_events.csv` `df18f2fc66df68de0b12de04099970cc262ce73d1daba5190e4fb50ada55357c`,
`market_open_interest_events.csv` `1b2aac23b232a8c36123a4fddf7534321e18db9b6355fb70b81cf966479f724b`,
`manifest.json` `a0613dcf12537d184a5d1995072df0fa39cd44f3fb69dfd2431380497461f014`.

Dataset: BTCUSDT, entry 5m (3168 bars, 2026-10-07T07:45Z → 2026-10-18T07:45Z, **0 gaps**),
HTF 60m (263 bars), peer ETHUSDT 5m (3168 bars), funding 3 events (2026-10-17T08:00Z → 2026-10-18T00:00Z),
open interest 95 snapshots (2026-10-17T07:45Z → 2026-10-18T07:15Z). Archive staleness at evaluation
time: 73.7 h.

Cost model (identical for every candidate and for the baseline strategy engine): taker fee
0.00055, slippage 0.0002, risk per trade 1 % of equity, fill at the **open of the next bar**,
conservative ambiguity (stop loss first), max holding 48 bars.

### Results — net after simulated costs, chronological 60/20/20 split

| Cohort | Signals (T/V/T) | Test trades | Test net PnL | Test PF | Test avg R | Status |
|---|---|---|---|---|---|---|
| `funding_open_interest_squeeze` | 0 / 0 / 0 | 0 | 0.00 | – | 0.000 | `INSUFFICIENT_EVIDENCE` |
| `liquidity_sweep_reversal` | 191 / 81 / 61 | 33 | **−2777.69** | 0.221 | −0.972 | `NOT_ELIGIBLE` |
| `volatility_compression_breakout` | 60 / 12 / 11 | 9 | −430.10 | 0.442 | −0.478 | `INSUFFICIENT_EVIDENCE` |
| `btc_eth_lead_lag` | 30 / 19 / 4 | 3 | +74.97* | 1.472* | +0.258* | `NOT_EVALUATED_EXECUTION_NOT_VALIDATED` |
| `multi_timeframe_trend_following` | 29 / 16 / 13 | 12 | −649.95 | 0.445 | −0.547 | `INSUFFICIENT_EVIDENCE` |

`*` single-leg proxy of a two-leg hypothesis. It is **not** an executed pair trade: no financing,
no concurrency and no second leg were modelled, therefore the cohort stays
`EXECUTION_NOT_VALIDATED` and the numbers are not a result.

**No candidate shows a positive net result, and no candidate is eligible.** Negative outcomes are
reported as they are; the precommitted thresholds (e.g. funding crowding threshold 0.0001) were
**not** adjusted after seeing the held-out numbers.

### Why `funding_open_interest_squeeze` produced no signal

The published funding rates of the archive are 0.00005128, 0.00006755 and 0.00003568 — all below
the precommitted crowding threshold of 0.0001, and the open-interest window covers only 23.5 h.
The status is `INSUFFICIENT_EVIDENCE` (the data is timestamped and provable, so it is **not**
`DATA_UNAVAILABLE`), and the threshold was left untouched.

## 5. Honest status semantics

| Status | Meaning |
|---|---|
| `VERIFIED_ENGINE_AND_LOOK_AHEAD_CONTROLS` | the engine, the golden parity and the §7.7 controls are verified — it says nothing about profitability |
| `NOT_ELIGIBLE` | no prospective paper history ⇒ a backtest split can never establish eligibility |
| `INSUFFICIENT_EVIDENCE` | fewer than 30 out-of-sample trades, or no decision at all |
| `NOT_EVALUATED_EXECUTION_NOT_VALIDATED` | the signal was researched, no execution model exists, no fictitious fill was simulated |
| `NOT_EVALUATED_DATA_UNAVAILABLE` | the required input cannot be proved (not the case for this archive) |
| `LOOK_AHEAD_CONTROL_FAILED` | a §7.7 control failed ⇒ the cohort is not reportable at all |

## 6. Test evidence (actual commands and results)

| Command | Result |
|---|---|
| `cd research-go && go build ./...` | exit 0 |
| `cd research-go && go vet ./...` | exit 0, no findings |
| `cd research-go && gofmt -l .` | no output (formatting clean) |
| `cd research-go && go test ./...` | exit 0, all packages `ok` (candidates, backtest, auditdata, algorithms, decimal, jobrunner, patterns, replay, simulation, walkforward, golden) |
| `cd research-go && go test -race ./internal/candidates/ ./internal/backtest/ ./internal/auditdata/` | exit 0, no race detected |
| `cd research-go && go run ./cmd/research-candidates -data-dir ../audit_data -out ../reports/profit-growth/S7_evidence.json` | exit 0, evidence written |

Go toolchain: `go1.18.1 linux/amd64`. Module `github.com/traderbybit/research-go`.

## 7. Limitations and residual risk

1. The archive is a bounded historical export (11 days of candles, 1 day of funding/OI). It is
   **not** a prospective paper observation stream, so no cohort can reach eligibility.
2. The 60/20/20 chronological split of 11 days yields small test samples; three cohorts are
   below the 30-trade evidence floor and are reported as `INSUFFICIENT_EVIDENCE`.
3. The lead/lag cohort has no two-leg execution model; its numbers are a single-leg proxy and are
   explicitly not a result.
4. The archive contains no `known_at` column; the knowledge timestamp of a candle is derived from
   its close time (candles are closed only) and is documented in `loader.go`. A live feed with
   later publication would need the real publication timestamp.
5. Signal counts per cohort are large relative to the sample (e.g. 333 sweep signals over 11 days).
   The cohorts are deliberately simple, hypothesis-level rules; they are not claimed to be good
   strategies, and the multiple-hypothesis count (5 families, 2 modes) must be considered by any
   later promotion decision.
6. No candidate was promoted, no threshold was fitted, no account was created. Promotion stays
   outside this contract and requires the Gate 6 evidence that does not exist yet.
