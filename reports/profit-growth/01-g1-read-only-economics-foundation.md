# VECTOR G1 — Read-Only Economics Foundation Report

**Date / Timezone:** 2026-10-20, UTC  
**Master Specification:** `VECTOR_AUTONOMOUS_PROFIT_RESEARCH_MASTER_SPEC_V1_EN.md` (v1.0)  
**Governing Technical Contract:** `VECTOR_PROFIT_1_Revision_2_1_Full_Spec.md` (Rev 2.1)  
**Status:** **G1 IMPLEMENTATION & VERIFICATION COMPLETED (READ-ONLY)**  
**Git HEAD:** `f2e3ace7b65a0d60f9cae4bd0c1070b4f3721c55` (branch `main`)  

---

## 1. Executive Summary & Operator Corrections Applied

The G1 deliverable establishes the read-only economic foundation for the VECTOR quant research platform in strict accordance with the operator corrections:

1. **Strict Accounting Scope Separation:**
   - Every metric explicitly declares its scope (`POINT_IN_TIME`, `FULL_LIFECYCLE`, `WINDOW_FLOW`, `EVENT_EXECUTION`).
   - Realized, unrealized, lifecycle, and window flow amounts are never aggregated into an ambiguous total.
   - Cashflows and positions are partitioned by scope.
2. **Reuse of Verified Implementations:**
   - Composed existing verified services (`PaperAccountService`, `PerformanceStatisticsService`, `DailyPerformanceService`, `LedgerReconciliationService`, and `QuantPortfolioMetricsService`).
   - No future PROFIT-1 P-2 functionality implemented prematurely. Missing margin data is explicitly returned as `NOT_RECORDED_IN_LEDGER`.
3. **Pure Read-Only Invariant Enforced:**
   - Negative control verified: `GET /api/v1/research/profitability/*` endpoints execute **0 database write queries** (no hidden writes from reconciliation or snapshot provisioning).
4. **Canonical Archive Decision Preserved:**
   - Primary archive `vector-audit-1d-e534ac49-*.zip` (`54da31d2...`) is recorded as `PENDING_OPERATOR_CONFIRMATION`.
   - Secondary archive `vector-audit-1d-53d90c98-*.zip` (`ee703740...`) is preserved untouched as an unapproved dataset.
5. **Strict Prohibitions Observed:**
   - Zero live trading code, exchange keys, or real order routes.
   - P-3 rejected-signal SHADOW replay is **blocked and prohibited**.
   - No production migrations or deployments.
   - All 8 primary paper accounts, strategy algorithms, signals, and risk rules remain intact.

---

## 2. Deliverables Inventory

### Backend Components
- [`app/Research/Domain/MetricRegistry.php`](file:///home/thunder/work/traderbybit/app/Research/Domain/MetricRegistry.php): Machine-readable catalog of quantitative metrics with units, formulas, denominator rules, and provenance.
- [`app/Research/Application/MetricRegistryService.php`](file:///home/thunder/work/traderbybit/app/Research/Application/MetricRegistryService.php): Service serving metric definitions and prohibition rules.
- [`config/paper.php`](file:///home/thunder/work/traderbybit/config/paper.php): Added record-only fee schedule versioning (`PAPER_FEE_SCHEDULE_VERSION=1.0.0`), VIP0 taker baseline provenance, and slippage price-delta semantics documentation.
- [`app/Http/Controllers/Api/V1/ProfitabilityResearchController.php`](file:///home/thunder/work/traderbybit/app/Http/Controllers/Api/V1/ProfitabilityResearchController.php): Read-only API controller:
  - `GET /api/v1/research/profitability/summary`
  - `GET /api/v1/research/profitability/strategies`
  - `GET /api/v1/research/profitability/strategies/{strategyId}`
  - `GET /api/v1/research/profitability/metric-registry`
- [`routes/api.php`](file:///home/thunder/work/traderbybit/routes/api.php): Mounted G1 routes under `/api/v1/research/profitability/`.

### Frontend Components
- [`frontend/src/pages/ProfitabilityResearchPage.vue`](file:///home/thunder/work/traderbybit/frontend/src/pages/ProfitabilityResearchPage.vue): Russian-language Quasar research interface with:
  - Disclaimers: Bybit paper trading only, no live trading, no profitability promise.
  - PnL Waterfall with accounting identity verification: $\text{Gross} - \text{Fees} + \text{Signed Funding} = \text{Net}$.
  - Execution slippage diagnostic (price delta vs dollar drag, double deduction warning).
  - 8 Primary Strategy Accounts table with latest reconciliation status.
  - Roadmap panel with transparent `NOT_IMPLEMENTED` badges for P-1..P-6 and `BLOCKED_PROHIBITED` badge for P-3.
  - Machine-readable Metric Registry viewer dialog.
- [`frontend/src/router/index.ts`](file:///home/thunder/work/traderbybit/frontend/src/router/index.ts): Route `research/profitability`.
- [`frontend/src/layouts/MainLayout.vue`](file:///home/thunder/work/traderbybit/frontend/src/layouts/MainLayout.vue): Sidebar navigation link `Доходность`.

---

## 3. Verification & Authentic Test Counts

All tests executed cleanly with zero failures:

| Test Suite | Command | Exit Code | Authentic Counts | Result |
|---|---|:---:|---|:---:|
| **Full PHPUnit Suite** | `XDEBUG_MODE=off php artisan test --no-ansi` | `0` | **426 total:** 421 passed, 5 skipped, 0 failed (2634 assertions) | ✅ PASSED |
| **G1 Metric Registry Unit** | `php artisan test tests/Unit/Research/MetricRegistryTest.php` | `0` | 5 passed, 0 failed (80 assertions) | ✅ PASSED |
| **G1 API & Negative Controls** | `php artisan test tests/Feature/Research/ProfitabilityResearchApiTest.php` | `0` | 6 passed, 0 failed (120 assertions) | ✅ PASSED |
| **Pint Style Linter** | `vendor/bin/pint --test` | `0` | All files compliant | ✅ PASSED |
| **Frontend Unit Tests** | `npm test` (in `frontend/`) | `0` | **50 total:** 50 passed, 0 failed | ✅ PASSED |
| **Frontend Build** | `npm run build` (in `frontend/`) | `0` | TypeScript check clean, Vite production bundle built in 8.13s | ✅ PASSED |
| **Go Research Engine** | `go test ./...` (in `research-go/`) | `0` | All packages passed | ✅ PASSED |

---

## 4. Negative Controls Verified

1. **Zero Database Writes on GET Requests:** Verified via query listener during API feature tests (`test_profitability_get_endpoints_are_strictly_read_only`). Exactly 0 `INSERT`, `UPDATE`, or `DELETE` queries executed.
2. **Double-Slippage Deduction Prohibited:** Verified that Net PnL is computed strictly from gross, commissions, and funding. Slippage dollar drag is marked as diagnostic-only.
3. **Zero-Loss Denominator Handling:** Verified that `profit_factor` and `payoff_ratio` return `NULL` with `UNDEFINED_NO_LOSSES` when loss cohort is empty, preventing divide-by-zero or arbitrary fallback.
4. **Missing Margin Schema Semantics:** Verified that `average_margin_used_pct` is returned as `NULL with NOT_RECORDED_IN_LEDGER`, preventing speculative or fabricated numbers.
5. **Gated Package Isolation:** Verified that P-1..P-6 report `NOT_IMPLEMENTED` and P-3 reports `BLOCKED_PROHIBITED`.
