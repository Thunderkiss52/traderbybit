# Go research engine profiling — iteration 2 (realistic dataset + integer RSI)

- Run: `vector-20261021T143809Z-379762`
- UTC: 2026-10-21T14:38:09Z → 2026-10-21T16:30:00Z (soft shutdown 2026-10-21T16:38:09Z)
- Environment: Go 1.18.1 linux/amd64, WSL2, `runtime.NumCPU` reported by the tool
- Repository HEAD at start: `f2e3ace7b65a0d60f9cae4bd0c1070b4f3721c55` (dirty worktree preserved)
- Predecessor report: `reports/overnight/GO_PROFILE_BASELINE.md` (iteration 1, exact-rational RSI fix)

Iteration 1 removed the pathological O(N^2) growth of the exact-rational RSI. It left two gaps
against VECTOR section 2: the **representative realistic data set was never measured**, and the
benchmark used a **single trial per size**, so no variance was recorded. This iteration closes
both gaps and removes the remaining dominant hotspot that the iteration-1 profile exposed.

## 1. Synthetic benchmark — 3 comparable trials per size (variance recorded)

Command: `go build -o /tmp/opencode/research-worker ./cmd/research-worker && for N in 2000 5000 10000; do for T in 1 2 3; do ./research-worker -benchmark -benchmark-count=$N; done; done`
(`research-go`, strategy `trend_pullback`, synthetic candles, seed 42)

| N | BACKTEST iteration 1 (ms) | BACKTEST iteration 2 (ms) | trials (ms) | mean | std dev | PATTERNS iteration 2 (ms) | trades / net PnL (unchanged) |
|---|---|---|---|---|---|---|---|
| 2,000 | 52–53 | **20–22** | 20, 22, 20 | 20.7 | 1.16 | 16–17 | 136 / -6146.60626498 |
| 5,000 | 131–140 | **48–54** | 54, 48, 48 | 50.0 | 3.46 | 40–42 | 342 / -9128.86034728 |
| 10,000 | 241–275 | **101–119** | 101, 104, 119 | 108.0 | 9.90 | 80–118 | 682 / -9831.61489905 |

Throughput at 10,000 candles: 84,034–99,010 candles/s (iteration 1: 36,364–41,494 candles/s).
Growth is linear: 2,000 → 10,000 candles costs ~5.2x the time for 5x the candles.
Peak RSS is unchanged (13–34 MB) because the working set is dominated by the candle series itself.

## 2. Representative realistic data set (local archive, read-only)

New command: `research-go/cmd/research-bench` (real archive loader, wall/CPU/RSS/allocation
accounting, optional CPU and heap pprof). Source: `audit_data/market_candles.csv`
(read-only export, closed candles only, manifest SHA-256 verified by the loader).

Series: BTCUSDT 5m, **3,168 closed candles**, `2026-10-15T04:40:00Z` → `2026-10-18T03:40:00Z`,
dataset hash `e3108afd040ffc8a…`.

Command:
```
go run ./cmd/research-bench \
  -data-dir /home/thunder/work/traderbybit/audit_data -symbol BTCUSDT -interval 5 -trials 3 \
  -cpuprofile .../profiles/realdata_btcusdt_5m_cpu_after.pprof \
  -heapprofile .../profiles/realdata_btcusdt_5m_heap_after.pprof \
  -out .../profiles/realdata_bench_btcusdt_5m_after.json
```

| Metric (3 trials) | iteration 1 (rational RSI) | iteration 2 (integer RSI) | change |
|---|---|---|---|
| backtest wall time | 76 / 82 / 78 ms (mean 78.7, sd 6.2) | 30 / 38 / 46 ms (mean 37.0) | **-53%** |
| pattern mining wall time | 89 / 71 / 82 ms (mean 80.7) | 30 / 33 / 35 ms (mean 31.7) | **-61%** |
| allocated bytes / trial | ~85.8 MB | ~34.5 MB | **-60%** |
| allocations / trial | ~2,705,800 | ~1,428,120 | **-47%** |
| process peak RSS | 19.7 MB | 19.7 MB | unchanged |
| trades / net PnL | 289 / -9778.82229158 | 289 / -9778.82229158 | **identical** |

## 3. Root cause of the remaining hotspot (measured, not guessed)

`go tool pprof -top -cum` on the iteration-1 CPU profile of the realistic series:

```
360ms 57.14%  github.com/traderbybit/research-go/internal/algorithms.CalculateRSI
270ms 42.86%  math/big.(*Rat).norm
230ms 36.51%  math/big.(*Int).lehmerGCD
130ms 20.63%  github.com/traderbybit/research-go/internal/decimal.Decimal.Float64
```

The iteration-1 fix bounded the RSI denominators, which removed the O(N^2) growth, but every bar
still paid a GCD normalisation for the exact-rational average and three further rational
operations for the RSI value itself. The averages are quantised to the internal scale 24 in every
step, so the rational arithmetic was exact but carried an avoidable constant factor.

**Fix (iteration 2):** the RSI averages are kept as exact integers in the internal scale 24 and the
RSI value is computed from the exact integer ratio `100 * ag / (ag + al)`, rounded to the decimal
scale with the same half-away-from-zero rule the rational path used. No rational normalisation is
left in the hot path.

- `research-go/internal/algorithms/indicators.go` — integer RSI, `roundHalfEvenDiv`, `roundHalfAwayDiv`
- `research-go/internal/decimal/decimal.go` — `Decimal.ScaledInt()`, `decimal.FromScaledInt()`

## 4. Parity evidence (no strategy semantics changed)

| Check | Result |
|---|---|
| `TestCalculateRSIWilderReferenceParity` (fixture `70.46413502`) | PASS |
| `TestCalculateRSIIntegerParityWithRationalReference` (new oracle test: periods 2/7/14 × 3 lengths, deterministic random series, compares every bar against the exact-rational reference) | PASS |
| `TestCalculateRSIDeterministicFlatSeries` (new, degenerate series) | PASS |
| `TestCalculateRSI` (existing) | PASS |
| `tests/golden` package (locked backtest golden hash) | PASS |
| `go test ./...` | all packages ok |
| `go test -race ./internal/algorithms/ ./internal/decimal/ ./internal/backtest/ ./internal/candidates/ ./tests/golden/` | ok, no race |
| `go vet ./...` | ok |
| `gofmt -l .` | no output |
| Synthetic backtest output (trades, win rate, net PnL) at 2k/5k/10k | byte-identical to iteration 1 |
| Realistic archive backtest output | 289 trades / -9778.82229158, identical |
| Section 7 candidate evaluation on the archive (`research-candidates`) | identical statuses and signal counts for all 5 cohorts (333 / 83 / 53 / 58 / 0 signals); the only JSON differences are `generated_at_utc`, the absolute `source_manifest` path and the archive age field `stale_after_hours` |

## 5. Remaining hotspot (measured, deliberately NOT changed in this run)

After iteration 2 the profile is dominated by the Bollinger band computation:

```
150ms 45.45%  internal/algorithms.CalculateBollingerBands
100ms 30.30%  internal/decimal.Decimal.Float64
 30ms 30.30%  runtime.mallocgc
```

`Decimal.Float64()` converts a fixed-point `big.Int` through `big.Float` division. A faster
conversion is possible but it changes the rounding path, and the contract requires exact decimal
parity with the PHP accounting (`Brick\Math\BigDecimal`, internal scale 24). The remaining work is
therefore a **follow-up with a defined acceptance rule**: the replacement must reproduce the
current float64 bit pattern for every value of the research archive, and the golden hash must stay
unchanged. It is not started here because the engine is already at 30–119 ms per 10,000 candles and
the remaining run budget is reserved for verification and evidence.

## 6. Honest limitations

- The archive is a **bounded single export**, not a prospective observation stream. The measurement
  describes engine cost on this dataset only; it says nothing about future market behaviour.
- `CPUTimeMS` is the process-wide user+system CPU time measured around the stage, therefore it is
  an upper bound for the stage, not an isolated measurement.
- `PeakRSSBytes` is the process high water mark and includes the loader, the report writer and the
  Go runtime.
- Only the `BTCUSDT` 5m series was measured with the new command; the `ETHUSDT` and the 15m/30m/60m
  series have the same shape and were not re-measured separately.
- The synthetic benchmark measures the engine, not the queue, the database round trip or the job
  runner; no end-to-end queue latency was measured in this run.

## 7. Files

- `research-go/cmd/research-bench/main.go` (new) — realistic dataset benchmark with pprof output
- `research-go/internal/algorithms/indicators.go` (modified) — integer RSI
- `research-go/internal/algorithms/rsi_parity_test.go` (new) — oracle parity test
- `research-go/internal/decimal/decimal.go` (modified) — scaled integer accessors
- `research-go/internal/decimal/scaled_test.go` (new) — accessor tests
- `reports/overnight/runs/vector-20261021T143809Z-379762/profiles/` — before/after pprof profiles and JSON measurements
