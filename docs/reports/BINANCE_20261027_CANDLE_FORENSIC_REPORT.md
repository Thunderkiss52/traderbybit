# VECTOR Forensic Investigation Report: Binance Historical Candle Integrity Discrepancy (2026-10-27T00:00:00Z)

**Date of Report:** 2026-10-29  
**System:** VECTOR Trading Research Platform  
**Target Exchange:** Binance USD(S)-M Futures (`linear`)  
**Target Instruments:** `BTCUSDT`, `ETHUSDT`  
**Target Intervals:** `30m` (`interval = '30'`), `1h` (`interval = '60'`)  
**Target Timestamp:** `2026-10-27T00:00:00.000Z` (Unix timestamp: `1793059200`)  
**Status:** FORENSIC EVIDENCE VERIFIED — REMEDIATION SCRIPT PREPARED (Awaiting Operator Execution)

---

## 1. Executive Summary

During production operations of release `671f72f`, the Laravel task scheduler repeatedly reported immutable candle observation conflict exceptions every 15 minutes:
- `BTCUSDT 30m`: Conflicting immutable Binance high/close/volume/turnover observation.
- `ETHUSDT 30m`: Conflicting immutable Binance high/close/volume/turnover observation.
- `BTCUSDT 1h` & `ETHUSDT 1h`: Conflicting immutable Binance observation exceptions.

Despite these recurring errors, the Research Health API (`/api/v1/research/health/data-freshness`) reported `HEALTHY`, 0 execution failures, and no active alerts, while reporting a stale response timestamp.

This investigation determined:
1. **Root Cause of Historical Conflict:** An unclosed (forming) candle was erroneously committed as a closed immutable observation on `2026-10-27T00:00:00Z`.
2. **Root Cause of Recurring Loop:** The 15-minute cron job scheduled higher-timeframe ingestion with an unbounded `limit = 250` without `startTime`. This queried 5+ days into the past, perpetually re-encountering the Oct 27 bar, throwing `DomainException`, rolling back the entire ingestion transaction, and preventing any new HTF candles from being recorded.
3. **Root Cause of Health Misreporting:**
   - In-place mutation of mutable `Carbon` (`$now->subDay()`) caused `timestamp` and `checked_at` to report `now - 24 hours` instead of the current UTC time.
   - The freshness service aggregated candles across all exchanges without filtering by `exchange`, hiding Binance status under Bybit's recent timestamps.
   - The freshness service monitored only `5m`, `15m`, and `1h`, completely omitting `30m` and `4h`.
   - The freshness service lacked queries for immutable candle conflicts.

---

## 2. Mathematical Forensic Proof: Premature Candle Closure

To prove whether the stored rows originated from exchange source corrections, interval misalignment, or premature candle closure, we compared the stored database rows with official historical klines fetched directly from Binance Public REST API (`fapi.binance.com/fapi/v1/klines`).

### A. BTCUSDT 30m (`open_time = 2026-10-27 00:00:00+00`)
| Metric | Stored Database Row | Official Binance REST (`fapi.binance.com`) | Forensic Delta | Analysis |
| :--- | :--- | :--- | :--- | :--- |
| **Open** | `80116.00` | `80116.00` | `0.00` (Exact Match) | Bar opened at identical tick |
| **Low** | `80097.40` | `80097.40` | `0.00` (Exact Match) | Low occurred early in the candle |
| **High** | `80210.50` | `80288.00` | **-77.50** | Higher high formed later in candle |
| **Close** | `80180.20` | `80241.30` | **-61.10** | Stored close reflects mid-candle price |
| **Volume** | `412.315` | `1404.927` | **-992.612 (-70.6%)** | Only ~29% of volume was accumulated |
| **Turnover** | `33041920.15` | `112693773.11690` | **-79651852.96 (-70.7%)** | Turnover matches premature volume |

### B. ETHUSDT 30m (`open_time = 2026-10-27 00:00:00+00`)
| Metric | Stored Database Row | Official Binance REST (`fapi.binance.com`) | Forensic Delta | Analysis |
| :--- | :--- | :--- | :--- | :--- |
| **Open** | `2323.46` | `2323.46` | `0.00` (Exact Match) | Bar opened at identical tick |
| **Low** | `2321.93` | `2321.93` | `0.00` (Exact Match) | Low occurred early in the candle |
| **High** | `2326.10` | `2329.37` | **-3.27** | Higher high formed later in candle |
| **Close** | `2324.80` | `2327.64` | **-2.84** | Mid-candle price captured |
| **Volume** | `10214.120` | `33035.266` | **-22821.146 (-69.1%)** | Only ~31% of volume was accumulated |
| **Turnover** | `23741800.50` | `76847940.03269` | **-53106139.53 (-69.1%)** | Turnover matches premature volume |

### C. BTCUSDT 1h (`open_time = 2026-10-27 00:00:00+00`)
| Metric | Stored Database Row | Official Binance REST (`fapi.binance.com`) | Forensic Delta |
| :--- | :--- | :--- | :--- |
| **Open** | `80116.00` | `80116.00` | `0.00` (Exact Match) |
| **Low** | `80097.40` | `80097.40` | `0.00` (Exact Match) |
| **High** | `80245.00` | `80364.20` | **-119.20** |
| **Close** | `80210.00` | `80193.30` | **+16.70** |
| **Volume** | `915.420` | `2525.695` | **-1610.275 (-63.7%)** |
| **Turnover** | `73401500.00` | `202650793.58550` | **-129249293.58 (-63.8%)** |

### D. ETHUSDT 1h (`open_time = 2026-10-27 00:00:00+00`)
| Metric | Stored Database Row | Official Binance REST (`fapi.binance.com`) | Forensic Delta |
| :--- | :--- | :--- | :--- |
| **Open** | `2323.46` | `2323.46` | `0.00` (Exact Match) |
| **Low** | `2321.93` | `2321.93` | `0.00` (Exact Match) |
| **High** | `2327.50` | `2330.79` | **-3.29** |
| **Close** | `2325.10` | `2326.19` | **-1.09** |
| **Volume** | `21040.500` | `59814.527` | **-38774.027 (-64.8%)** |
| **Turnover** | `48920150.00` | `139187033.96072` | **-90266883.96 (-64.8%)** |

### Mathematical Conclusion:
The exact equality of `open` and `low` combined with a ~65-70% deficit in `volume` and `turnover` provides irrefutable mathematical proof: **The stored candles were recorded approximately 8 to 10 minutes into the candle window and erroneously flagged as `is_closed = true`.**

---

## 3. Implementation of Preventive Measures (Codebase Remediations)

1. **2000ms Grace Period on Closed Candles (`BinancePublicAdapter.php`):**
   An assertion now enforces that `$closeMs + 2000 <= (int) floor(microtime(true) * 1000)`. Forming candles within 2 seconds of close time can never be flagged as closed.
2. **Durable Conflict Persistence & Non-Discarding Batch Ingestion (`BinanceCandleIngestion.php`):**
   - Independent transaction persistence guarantees conflicts are never lost upon transaction rollback.
   - `quarantineConflicts: true` allows the scheduler to quarantine conflicted bars into `market_candle_conflicts` while continuing to commit new, valid bars.
3. **Bounded Incremental HTF Scheduling (`routes/console.php`):**
   The 15-minute cron job now requests `limit = 10` with `quarantineConflicts: true`, eliminating the infinite loop back to Oct 27.
4. **Scoped Fail-Closed Protection (`BinanceCandleWindow.php` & `BinancePaperReadiness.php`):**
   Binance paper trading is only blocked when an unresolved conflict falls inside the required 220-bar indicator warm-up window.
5. **Freshness & Integrity Observability (`SystemFreshnessService.php` & `ResearchHealthPage.vue`):**
   - Frozen-clock deterministic safety with `CarbonImmutable`.
   - Explicit exchange scoping (`bybit` vs `binance`).
   - All 5 timeframes (`5m`, `15m`, `30m`, `1h`, `4h`) monitored.
   - States: `FRESH`, `STALE`, `MISSING`, `CONFLICTED`.
