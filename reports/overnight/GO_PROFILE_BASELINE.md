# Go research engine profiling — baseline

Environment: Go 1.18.1 linux/amd64, WSL2. Synthetic candles (seed=42), strategy `trend_pullback`.
Command: `cd research-go && go run ./cmd/research-worker -benchmark -benchmark-count=N`

| N | BACKTEST dur | Backtest throughput | PATTERNS dur | Peak RSS | WFO | Monte Carlo |
|---|---|---|---|---|---|---|
| 2,000 | 1216 ms | 1645 c/s | 1223 ms | ~18 MB | 167 ms | 1 ms |
| 5,000 | 12601 ms | 397 c/s | 12671 ms | ~23 MB | 1194 ms | 2 ms |
| 10,000 | >300,000 ms (did not complete within 5 min; terminated) | n/a | n/a | n/a | n/a | n/a |

Observation: super-linear growth confirmed (2k->5k ~10x per ~2.5x candles). 10k pathological (>5min vs ~1.2s at 2k). The pattern is ~O(N^2) in both BACKTEST and PATTERNS.
No pprof-only attribution yet; both BACKTEST and PATTERNS show near-identical duration whereas WFO/MC are cheap.
Note: strategy engine reports identical duration for backtest and patterns because they scan the same data; this is likely two independent O(N^2) loops.

Status: BASELINE_MEASURED (2k, 5k). 10k TIMEOUT — recorded, not fabricated.

## pprof attribution (baseline, pre-fix)
`go tool pprof` on a 5000-candle backtest benchmark showed:
- ~93% cumulative time in `algorithms.CalculateRSI` -> `math/big.(*Rat).Quo`/`Add`/`Mul` -> `big.Int.lehmerGCD` (GCD normalization).
- Root cause: RSI kept `avgGain`/`avgLoss` as exact `big.Rat`, whose denominators grow multiplicatively each Wilder step (period^k), so each rational op degenerates to O(k) digit work; total O(N^2).
- PHP `RsiCalculator` uses BigDecimal with bounded internalScale=24 (HalfEven), so Go's exact-Rat RSI was both slow and conceptually divergent.

## Fix
- Added `decimal.RatQuantize(r, scale)` implementing round-half-even to a bounded scale.
- Rewrote `CalculateRSI` to quantize `avgGain`/`avgLoss` to scale 24 after each step, mirroring PHP's `Decimal` internalScale=24.
- Denominators are now bounded; RSI numeric output unchanged at scale 8.

## Before/after (identical strategy outputs)
| N | BACKTEST before | BACKTEST after | speedup | trades/pnl parity |
|---|---|---|---|---|
| 2000 | 1216 ms | 60 ms | ~20x | identical (136, -6146.60626498) |
| 5000 | 12601 ms | 124 ms | ~100x | identical (342, -9128.86034728) |
| 10000 | did not finish >5 min (terminated) | 254 ms | >1000x | (baseline output unavailable — was never produced) |
| PATTERNS 2000 | 1223 ms | 53 ms | ~23x | |
| PATTERNS 5000 | 12671 ms | 131 ms | ~96x | |

Golden parity: RSI Wilder fixture remains `70.46413502`; backtest trade-event SHA-256 hash locked in `TestBacktestGoldenParityHash`.

Status: FIXED and VERIFIED locally (Go unit + golden + race + vet pass).
