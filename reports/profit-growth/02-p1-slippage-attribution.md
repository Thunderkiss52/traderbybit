# VECTOR PROFIT-1 P-1 — Slippage Attribution Report

**Date / Timezone:** 2026-10-20, UTC  
**Master Specification:** `VECTOR_AUTONOMOUS_PROFIT_RESEARCH_MASTER_SPEC_V1_EN.md` (v1.0)  
**Governing Technical Contract:** `VECTOR_PROFIT_1_Revision_2_1_Full_Spec.md` (Rev 2.1 §7)  
**Status:** **P-1 IMPLEMENTATION & VERIFICATION COMPLETED**  
**Git HEAD:** `f2e3ace7b65a0d60f9cae4bd0c1070b4f3721c55` (branch `main`)  

---

## 1. Executive Summary & Operator Corrections Applied

The PROFIT-1 P-1 deliverable implements deterministic, fill-level slippage attribution and immutable research generation in strict accordance with the governing specification and operator corrections:

1. **Dynamic Calculation from Immutable Research Generations:**
   - Historical numeric figures ($35.1732$ USDT drag, $2.00$ bps, $+37.5057$ USDT baseline Net PnL, $+72.6789$ USDT hypothetical Net PnL) are **not hardcoded constants**.
   - Every value is dynamically calculated from immutable research generation runs (`research_generations`) with cryptographic output hashing (`SHA-256`), request payload hashing, and heartbeat timestamps.
2. **Reconciled Counterfactual Scope & Zero Double Deduction:**
   - Prior to computing the hypothetical zero-slippage counterfactual, the service verifies that the position population and baseline Net PnL reconcile across the same accounting scope (`FULL_LIFECYCLE` or `WINDOW_FLOW`).
   - If population counts differ or data is missing, the counterfactual returns `INSUFFICIENT_DATA` / `NOT_VERIFIED`.
   - Double deduction is strictly prohibited: Net PnL is defined solely as $\text{Gross} - \text{Fees} + \text{Signed Funding}$. Slippage is modeled as price distortion included in Gross PnL. The hypothetical zero-slippage Net PnL is computed as $\text{Baseline Net PnL} + \text{Attributable Drag}$, with prominent `HYPOTHETICAL` provenance markings and explicit calibration disclaimers.
3. **Signed Execution Impact & Reference Price Reconstruction:**
   - Adverse slippage drag is evaluated per execution side and fill role:
     - **BUY:** $P_{\text{ref}} = P_{\text{fill}} - \Delta P$; $\text{drag}_{\text{USDT}} = (P_{\text{fill}} - P_{\text{ref}}) \times Q \times M$.
     - **SELL:** $P_{\text{ref}} = P_{\text{fill}} + \Delta P$; $\text{drag}_{\text{USDT}} = (P_{\text{ref}} - P_{\text{fill}}) \times Q \times M$.
   - Reference notional is computed as $P_{\text{ref}} \times Q \times M$, and weighted bps is computed as $\frac{\sum \text{drag}_{\text{USDT}}}{\sum \text{notional}_{\text{USDT}}} \times 10{,}000$.
   - Supports `ENTRY`, `PARTIAL_EXIT` (TP1), and `FINAL_EXIT` (TP2 / SL / timeout).
   - In-bar dual TP/SL triggers are flagged with `ambiguous_execution = true` and reason `IN_BAR_BOTH_TP_SL_HIT`.
4. **Enforced Security Guard on Write Endpoints:**
   - `POST /api/v1/research/profitability/generations` is strictly guarded by `RequireResearchToken` middleware (`research.token`).
   - Missing token yields HTTP `401 Unauthorized`; invalid token yields HTTP `403 Forbidden`.
   - Conflicting parameters with the same idempotency key trigger HTTP `409 Conflict` via `IdempotencyConflictException`.
5. **Zero Modification to Trading Ledger:**
   - Dedicated research tables (`research_generations`, `slippage_attribution_fills`, `slippage_attribution`) keep research data completely separate from core trading tables (`paper_accounts`, `virtual_positions`, `virtual_executions`).
   - All 8 primary paper accounts, positions, and executions remain intact and unmodified.

---

## 2. Deliverables Inventory

### Database Migrations
- [`database/migrations/2026_10_20_200000_create_slippage_attribution_tables.php`](file:///home/thunder/work/traderbybit/database/migrations/2026_10_20_200000_create_slippage_attribution_tables.php):
  - `research_generations`: UUID PK, `package_code = 'P-1'`, `accounting_scope`, `as_of`, `status`, `request_payload_hash`, `output_hash`, `idempotency_key` (unique), `versioned_config`, `row_counts`, `heartbeat_at`, `stale_after_seconds`.
  - `slippage_attribution_fills`: UUID PK, `generation_id`, `execution_id`, `position_id`, `paper_account_id`, `strategy_id`, `symbol`, `execution_side`, `fill_role`, `quantity`, `contract_multiplier`, `reference_price`, `fill_price`, `reference_notional`, `slippage_drag_usdt`, `slippage_bps`, `price_source`, `provenance_status`, `ambiguous_execution`, `ambiguity_reason`. Unique constraint on `(generation_id, execution_id)`.
  - `slippage_attribution`: UUID PK, `generation_id`, `position_id`, `paper_account_id`, `strategy_id`, `symbol`, `position_side`, `entry_slippage_usdt`, `partial_exit_slippage_usdt`, `final_exit_slippage_usdt`, `total_slippage_drag_usdt`, `total_reference_notional`, `weighted_slippage_bps`, `fill_count`, `ambiguous_fill_count`. Unique constraint on `(generation_id, position_id)`.

### Domain Models & Exceptions
- [`app/Research/Domain/ResearchGenerationRecord.php`](file:///home/thunder/work/traderbybit/app/Research/Domain/ResearchGenerationRecord.php)
- [`app/Research/Domain/SlippageAttributionRecord.php`](file:///home/thunder/work/traderbybit/app/Research/Domain/SlippageAttributionRecord.php)
- [`app/Research/Domain/SlippageAttributionFillRecord.php`](file:///home/thunder/work/traderbybit/app/Research/Domain/SlippageAttributionFillRecord.php)
- [`app/Research/Exceptions/IdempotencyConflictException.php`](file:///home/thunder/work/traderbybit/app/Research/Exceptions/IdempotencyConflictException.php)

### Application Services & APIs
- [`app/Research/Application/SlippageAttributionService.php`](file:///home/thunder/work/traderbybit/app/Research/Application/SlippageAttributionService.php): Pure mathematical calculation, cryptographic hashing, fill-role classification, and counterfactual calibration.
- [`app/Http/Controllers/Api/V1/ProfitabilityResearchController.php`](file:///home/thunder/work/traderbybit/app/Http/Controllers/Api/V1/ProfitabilityResearchController.php):
  - `GET /api/v1/research/profitability/slippage-attribution`: Read-only summary and breakdown.
  - `GET /api/v1/research/profitability/slippage-attribution/positions/{positionId}`: Position-level trace with individual fills.
  - `POST /api/v1/research/profitability/generations`: Guarded generation endpoint with idempotency conflict handling (HTTP 409).
- [`routes/api.php`](file:///home/thunder/work/traderbybit/routes/api.php): Mounted P-1 endpoints.

### Frontend User Interface
- [`frontend/src/pages/ProfitabilityResearchPage.vue`](file:///home/thunder/work/traderbybit/frontend/src/pages/ProfitabilityResearchPage.vue):
  - P-1 Slippage Attribution tab with summary KPI cards: Total Drag ($35.17$ USDT / $2.00$ bps), Entry Drag, Partial Exit Drag, Final Exit Drag.
  - Counterfactual Zero-Slippage card with prominent `HYPOTHETICAL` badge and mandatory calibration disclaimer.
  - Strategy-level slippage distribution table.
  - Position-level execution trace modal showing individual fills, roles, prices, drag, and ambiguity flags.
- [`frontend/tests/profitability_research.test.js`](file:///home/thunder/work/traderbybit/frontend/tests/profitability_research.test.js): Node.js test suite asserting P-1 tab, `HYPOTHETICAL` badge, double deduction prohibition, and trace modal.

---

## 3. Verification & Authentic Test Counts

All tests executed cleanly against the codebase:

| Test Suite | Command | Exit Code | Authentic Counts | Result |
|---|---|:---:|---|:---:|
| **Full PHPUnit Regression Suite** | `XDEBUG_MODE=off php artisan test --no-ansi` | `0` | **435 total:** 430 passed, 5 skipped, 0 failed (2685 assertions) | ✅ PASSED |
| **P-1 Unit Tests** | `php artisan test tests/Unit/Research/SlippageAttributionServiceTest.php` | `0` | 4 passed, 0 failed (24 assertions) | ✅ PASSED |
| **P-1 Feature API Tests** | `php artisan test tests/Feature/Research/SlippageAttributionApiTest.php` | `0` | 3 passed, 0 failed (21 assertions) | ✅ PASSED |
| **P-1 PostgreSQL Integration** | `php artisan test tests/Integration/SlippageAttributionPostgresIntegrationTest.php` | `0` | 2 passed, 0 failed (6 assertions) | ✅ PASSED |
| **Pint Style Linter** | `vendor/bin/pint --test` | `0` | All files compliant | ✅ PASSED |
| **Frontend Unit Tests** | `npm test` (in `frontend/`) | `0` | **51 total:** 51 passed, 0 failed | ✅ PASSED |
| **Frontend Production Build** | `npm run build` (in `frontend/`) | `0` | TypeScript check clean, Vite production bundle built in 7.89s | ✅ PASSED |
| **Go Research Engine** | `go test ./...` (in `research-go/`) | `0` | All packages passed | ✅ PASSED |

---

## 4. Invariants & Negative Controls Verified

1. **Zero Database Writes on GET Requests:** Verified via database query listener in `SlippageAttributionApiTest::test_get_slippage_attribution_is_purely_read_only` and `test_position_slippage_attribution_endpoint_read_only_and_not_found`. Exactly 0 write queries executed.
2. **PostgreSQL Real Schema Unique Constraints:** Verified in `SlippageAttributionPostgresIntegrationTest`:
   - Duplicate `(generation_id, execution_id)` throws `UniqueConstraintViolationException`.
   - Duplicate `idempotency_key` throws `UniqueConstraintViolationException`.
3. **Idempotency Conflict Handling:** Calling `POST /research/profitability/generations` with the same idempotency key but differing parameters returns HTTP `409 Conflict` with `IDEMPOTENCY_CONFLICT` error code. Calling with identical parameters returns HTTP `201` with the existing record.
4. **Security Guard Verification:** `POST` without token returns HTTP `401 Unauthorized`; with invalid token returns HTTP `403 Forbidden`.
5. **No Double Deduction:** Net PnL remains $\text{Gross} - \text{Fees} + \text{Signed Funding}$. Slippage drag is reported as diagnostic impact and added to Net PnL only in the hypothetical counterfactual.

---

## 5. Strictly Disclosed Remaining Limitations & Boundaries

1. **Paper Trading Only:** Real exchange orders and execution keys remain strictly prohibited.
2. **Gated Modules Preserved:** P-2 (fee/funding models), P-4 (drawdown/TWAE), P-5 (portfolio/sharpe), and P-6 (recommendations) remain gated as `NOT_IMPLEMENTED`.
3. **P-3 Prohibited:** P-3 rejected-signal SHADOW replay is strictly `BLOCKED_PROHIBITED`.
4. **Primary Accounts Preserved:** All 8 primary paper accounts, balances, positions, and executions remain intact.
5. **Zero Unauthorized Deployments:** No production migrations, Docker restarts, or Git pushes have been performed.
