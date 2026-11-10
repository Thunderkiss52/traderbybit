# VECTOR — Remediation Baseline & Findings Classification

> **Generated:** 2026-11-10T19:02:00Z  
> **Repository:** `traderbybit` (`main`)  
> **Commit SHA:** `53903253fff2ce6e87de727d44025e30766fb9f6`  
> **Environment:** Linux x86_64, PHP 8.3.16, PostgreSQL 16.11 (`bybit_test` on port 25432), Redis 7.2.7 (port 26379), Go 1.23.4, Node.js 20+  
> **Operational Boundary:** Strictly Virtual PAPER Trading (`PAPER_TRADING=true`); Zero Live Trading Credentials; Read-Only Public Market Data.

---

## 1. Executive Baseline

The working tree baseline has been inspected at commit `5390325` on branch `main`. All 88 database migrations are applied. The local PostgreSQL test database (`bybit_test`) is operational on port 25432. All prior commits and working tree files have been preserved.

---

## 2. Findings Classification Matrix

Each reported finding from the audit and prompt has been independently analyzed against the source code, tests, and database schema:

| ID | Domain / Component | Description | Actual Code Location | Status |
|---|---|---|---|---|
| **F-01** | Strategy & Exchange Topology Isolation | `PrimaryStrategies::byExchange` unconditionally creates a blind $N \times 13$ cross-product of accounts. Registering a new exchange or strategy automatically provisions accounts across all exchanges without explicit operator assignment. | `app/StrategyBuilder/PrimaryStrategies.php#L30-L39`, `app/PaperTrading/Application/PaperTopologySynchronizer.php#L54-L85`, `app/Strategy/Application/StrategyRegistry.php#L42-L70` | **CONFIRMED_OPEN** |
| **F-02** | Paper Execution Quality Gate | `expected_net_edge` is passed as `null` and `sample_size` as `0` hardcoded during real execution in `PaperProcessingService`. No genuine pre-entry strategy quality assessment occurs. | `app/PaperTrading/Application/PaperProcessingService.php#L489-L496` | **CONFIRMED_OPEN** |
| **F-03** | Directional Performance Attribution | The Risk UI displayed closed positions but 0 USDT for Net PnL and Fees, and `undefined%` for win rate. The backend returned `net_pnl`, `fees`, `win_rate`, `average_r`, while `RiskPage.vue` bound to `total_net_pnl`, `total_fees`, `win_rate_percent`, `avg_r`. Furthermore, `$posQuery` failed to filter by `$strategyId` when `$accountId` was omitted. | `app/PaperTrading/Application/DirectionalPerformanceService.php#L82-L110, L220-L237`, `frontend/src/pages/RiskPage.vue#L240-L265` | **CONFIRMED_OPEN** |
| **F-04** | Financial Reconciliation & Funding Gaps | `LedgerReconciliationService` fails safe to `INCOMPLETE` when historical 8-hour funding events are absent in `market_funding_events`. Open positions checked boundaries against `now('UTC')` triggering false positive funding gaps before boundary settlement. | `app/Research/Application/LedgerReconciliationService.php#L106-L176` | **CONFIRMED_OPEN** |
| **F-05** | Slippage Attribution P-1 Status | The UI reported `IMPLEMENTED_VERIFIED` for P-1 Slippage Attribution because the status was hardcoded in `ProfitabilityResearchController` rather than resolved dynamically from persisted `research_generations` records. | `app/Http/Controllers/Api/V1/ProfitabilityResearchController.php#L233` | **CONFIRMED_OPEN** |
| **F-06** | Strategy Monitor 15s Request Abort | `GET /api/v1/strategy-monitor` executed 16 sequential full-table `count()` queries on `signals`, `strategy_observations`, and `virtual_positions`, causing timeouts exceeding Axios's 15s client timeout. | `app/Http/Controllers/Api/V1/StrategyMonitorController.php#L115-L146`, `frontend/src/services/api.ts#L12` | **CONFIRMED_OPEN** |
| **F-07** | SSRF Security in Dynamic Exchange Manifests | `ExchangeManifestValidator` only performed basic `filter_var(..., FILTER_VALIDATE_URL)`. It accepted loopback (`127.0.0.1`), private RFC1918 IPs, link-local metadata addresses (`169.254.169.254`), non-standard ports (e.g. 6379, 5432), and unverified schemes. | `app/Exchanges/Domain/ExchangeManifestValidator.php#L76-L78`, `app/Exchanges/Application/ExchangeCapabilityValidator.php#L38-L82` | **CONFIRMED_OPEN** |
| **F-08** | Dynamic Market Data Recovery Hardcoding | `TradingPipelineRecoveryService` hardcoded `$exchanges = ['bybit', 'binance']` and hardcoded queue resolution, ignoring the other 8 registered exchanges and dynamic onboarding. | `app/Trading/Application/TradingPipelineRecoveryService.php#L205, L246` | **CONFIRMED_OPEN** |
| **F-09** | Market Context Neutrality | `ProcessClosedCandleEventJob` exclusively handled Bybit via `MarketContextCollector` and Binance via `BinanceContextCapture`. All other exchanges were skipped and had zero market context captured. | `app/Trading/Jobs/ProcessClosedCandleEventJob.php#L328-L333`, `app/Strategy/Application/MarketContextCollector.php#L20` | **CONFIRMED_OPEN** |
| **F-10** | Browser Audit Tool Pagination Coverage | `scripts/audit/ui_api_audit_runner.py` only checked 7 API endpoints, did not test UI table paginators, capped pagination without honoring `--max-pages`, and omitted Model Artifacts / Candidates. | `scripts/audit/ui_api_audit_runner.py#L323-L413` | **CONFIRMED_OPEN** |
| **F-11** | Autonomous Candidate Validation & Learning UI | Learning UI showed 0 promoted candidates because automated walk-forward validation jobs were not executing to completion against available candle datasets. | `app/Http/Controllers/Api/V1/AutonomousLearningController.php#L100-L250` | **CONFIRMED_OPEN** |
| **F-12** | Database Migration Safety & PostgreSQL Concurrency | Recent migrations (`2026_11_10_150000_add_sizing_snapshot_to_virtual_positions.php`) require explicit rollback verification, concurrent locking verification, and PostgreSQL JSONB index verification. | `database/migrations/2026_11_10_150000_add_sizing_snapshot_to_virtual_positions.php` | **PARTIALLY_RESOLVED** |

---

## 3. Implementation Sequence & Staging Plan

- **Stage 1 (Topology & Capability Integrity):** Implement explicit strategy-to-exchange assignment matrix, update `PaperTopologySynchronizer`, `PrimaryStrategies`, and capability contract verification. Fix SSRF protection in `ExchangeManifestValidator`.
- **Stage 2 (Trading Pipeline & Market Context):** Build universal `UniversalMarketContextCollector`, update `ProcessClosedCandleEventJob`, remove hardcoded exchange arrays in `TradingPipelineRecoveryService`, and implement genuine pre-execution `expected_net_edge` & strategy quality evaluation in `PaperProcessingService` and `PreExecutionQualityRiskGate`.
- **Stage 3 (Financial Accounting & Attribution):** Fix directional performance property mismatch in `DirectionalPerformanceService` & `RiskPage.vue`, filter `$strategyId`, refine funding boundary checks in `LedgerReconciliationService` for open positions, and verify financial scope isolation.
- **Stage 4 (Research, Slippage & Self-Learning):** Implement dynamic P-1 slippage status resolution in `ProfitabilityResearchController`, verify candidate validation lifecycle, and add Model Artifacts pagination.
- **Stage 5 (UI, API Performance & Audit Tooling):** Consolidate SQL counts in `StrategyMonitorController`, add composite indexes, enhance `ui_api_audit_runner.py` with full table pagination and classification.
- **Stage 6 (Full Verification & Deliverables):** Execute complete PHPUnit, Go, and frontend suites, generate all 11 required reports in `reports/remediation/`, and verify end-to-end multi-exchange PAPER trading.
