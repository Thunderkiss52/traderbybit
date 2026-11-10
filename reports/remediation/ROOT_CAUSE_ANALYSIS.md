# VECTOR — Root Cause Analysis Report

> **Date:** 2026-11-10T19:02:00Z  
> **Repository:** `traderbybit` (`main`)  
> **Commit Baseline:** `5390325`  

---

## 1. Root Cause Analysis by Finding

### Finding F-01: Blind Strategy × Exchange Topology Cross-Product
- **Symptom:** Every registered strategy was provisioned on every registered exchange ($N \times 13$), violating the operator's intention of explicit, isolated strategy assignments.
- **Root Cause:** In `app/StrategyBuilder/PrimaryStrategies.php#L30-L39`, `byExchange()` mapped every exchange to the entire list of `self::ids()`. `PaperTopologySynchronizer::sync()` iterated through this full cross product and inserted accounts whenever `exchanges.paper_approved` was true.
- **Remediation:** 
  1. Create explicit strategy assignment configuration / database mapping (`strategy_exchange_assignments` or registered scopes).
  2. The canonical baseline preserves the original 26 Bybit and Binance accounts and any existing approved accounts.
  3. New strategies and new exchanges require explicit assignment before accounts can be provisioned or activated.
  4. Topology sync becomes strictly constrained to approved pairs.

---

### Finding F-02: `expected_net_edge` Null & `sample_size` Zero in Pipeline
- **Symptom:** Signals passing through `PaperProcessingService` evaluated the Pre-Execution Quality Risk Gate with null edge and zero sample size, ignoring historical performance evidence.
- **Root Cause:** In `app/PaperTrading/Application/PaperProcessingService.php#L489-L496`, the call to `PreExecutionQualityRiskGate::evaluate()` hardcoded `'expected_net_edge' => null` and `'sample_size' => 0`.
- **Remediation:**
  1. Build a strategy-quality evaluator that inspects historical realized performance (expectancy, fees, funding, slippage, average R, win rate, sample size, walk-forward results) strictly available prior to the entry candle.
  2. Pass the calculated expectancy and sample size into `PreExecutionQualityRiskGate`.
  3. Return explicit rejections when evidence is insufficient (`INSUFFICIENT_VALIDATED_EDGE`, `INSUFFICIENT_SAMPLE_SIZE`, `STALE_PERFORMANCE_EVIDENCE`, `UNSUPPORTED_MARKET_REGIME`).

---

### Finding F-03: Directional Attribution Property Key Mismatch
- **Symptom:** In the Risk page, the Directional Execution Attribution card showed closed positions count, but 0 USDT for Net PnL and Fees, and `undefined%` for win rate.
- **Root Cause:** 
  1. `DirectionalPerformanceService.php#L220-L237` returned dictionary keys `'net_pnl'`, `'fees'`, `'win_rate'`, `'average_r'`.
  2. `frontend/src/pages/RiskPage.vue#L240-L265` bound to properties `'total_net_pnl'`, `'total_fees'`, `'win_rate_percent'`, `'avg_r'`.
  3. In `DirectionalPerformanceService::calculateCohortMetrics()`, when `$strategyId` was provided without `$accountId`, `$posQuery` failed to join or filter by `paper_accounts.strategy_id`.
- **Remediation:** Provide both canonical and UI-friendly aliases in the backend response (`net_pnl` and `total_net_pnl`, `fees` and `total_fees`, `win_rate` and `win_rate_percent`, `average_r` and `avg_r`), filter by `$strategyId` in `$posQuery`, and ensure consistent data types.

---

### Finding F-04: Bybit Ledger Reconciliation `INCOMPLETE` Status
- **Symptom:** 13 Bybit portfolios showed `INCOMPLETE` in reconciliation reports.
- **Root Cause:**
  1. Positions from earlier dates lacked continuous 8-hour funding events in `market_funding_events`.
  2. For open positions, `LedgerReconciliationService` evaluated expected funding boundaries up to `now('UTC')`. If the current 8-hour window had not yet settled or been ingested, `hasFundingGap` was flagged true and marked `funding_gap_detected`.
- **Remediation:** Restrict open position funding boundary expectations to the latest completed funding settlement interval (i.e. strictly prior to current unsettled funding hour), while preserving strict gap detection for closed positions.

---

### Finding F-05: P-1 Slippage Attribution Status Hardcoding
- **Symptom:** The Profitability Research UI showed `IMPLEMENTED_VERIFIED` for P-1 even when no report had been generated or data was insufficient.
- **Root Cause:** In `app/Http/Controllers/Api/V1/ProfitabilityResearchController.php#L233`, `'P-1' => ['name' => 'Slippage Attribution', 'status' => 'IMPLEMENTED_VERIFIED', 'authorized' => true]` was hardcoded.
- **Remediation:** Query `research_generations` where `package_code = 'P-1'` and derive the real lifecycle status (`NOT_IMPLEMENTED`, `NOT_GENERATED`, `QUEUED`, `RUNNING`, `INSUFFICIENT_DATA`, `FAILED`, `STALE`, `IMPLEMENTED_VERIFIED`).

---

### Finding F-06: Strategy Monitor 15s Query Abort
- **Symptom:** `GET /api/v1/strategy-monitor` timed out and aborted after 15 seconds.
- **Root Cause:** `StrategyMonitorController.php#L115-L146` executed 16 separate sequential SQL `count()` queries on large tables (`strategy_observations`, `signals`, `virtual_positions`, `risk_decisions`).
- **Remediation:** Consolidate multiple counts into a single conditional aggregation query (`SELECT count(*), sum(case when side='BUY' then 1 else 0 end) ... FROM signals`), add composite indexes on `signals (exchange, symbol, strategy_id, decision_time)`, and ensure queries complete under 50ms.

---

### Finding F-07: SSRF Vulnerability in Dynamic Exchange Manifests
- **Symptom:** Operators could submit an exchange manifest with `base_url` pointing to internal private IP addresses or services (e.g. `http://127.0.0.1:26379`, `http://169.254.169.254/`).
- **Root Cause:** `ExchangeManifestValidator.php#L76-L78` only checked `filter_var($url, FILTER_VALIDATE_URL)` without validating IP address resolution, private/loopback ranges, allowed ports (80/443), or TLS requirements.
- **Remediation:** Implement a strict SSRF guard that resolves DNS, rejects loopback (`127.0.0.0/8`, `::1`), private networks (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`), link-local (`169.254.0.0/16`), and non-standard ports, and requires HTTPS for external exchanges.

---

### Finding F-08: Hardcoded Exchanges in Market Recovery
- **Symptom:** Market data recovery only inspected `['bybit', 'binance']`.
- **Root Cause:** In `app/Trading/Application/TradingPipelineRecoveryService.php#L246`, `$exchanges` was hardcoded as `['bybit', 'binance']`.
- **Remediation:** Dynamically discover active exchanges via `ExchangeRegistry` and filter by capability contracts.

---

### Finding F-09: Non-Neutral Market Context Pipeline
- **Symptom:** `ProcessClosedCandleEventJob.php` only triggered market context collection for Bybit and Binance.
- **Root Cause:** Line 328 checked `if ($this->exchange === 'bybit') ... elseif ($this->exchange === 'binance') ...` with no universal adapter fallback.
- **Remediation:** Build an exchange-neutral `UniversalMarketContextCollector` that uses `ExtendedExchangeAdapter` methods (`fetchTicker`, `fetchOrderBook`, `fetchInstruments`, `fetchOpenInterest`) to store context snapshots for any registered exchange.
