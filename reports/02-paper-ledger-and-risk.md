# Gate 2 — Paper Accounts, Authoritative Execution, Financial Correctness

## 1. Eight Independent Primary Accounts
The eight authoritative strategies are mapped 1-to-1 to isolated paper accounts:
1. `baseline` (`traderbybit:paper:default`): `94b97f13-107d-54a9-b4eb-1ca5a9c79743`
2. `trend_pullback`: `ec9dd1b1-1001-5c4f-a203-4a2a9ba9f9ca`
3. `bollinger_mean_reversion`: `6573dfda-71ed-5444-8d95-08c2a6fadfa7`
4. `donchian_atr_breakout`: `5f742264-cc16-57f2-bd5a-d1de7d06b0e8`
5. `regime_switching`: `38fcded4-48f4-52d1-b436-b8ef018f6d8c`
6. `momentum`: `458c0fd5-7673-5ce8-ba07-a4576c854945`
7. `support_resistance_bounce`: `794fa4f9-33cd-5338-867e-a9c27f834652`
8. `support_resistance_breakout`: `9eab0b93-2696-53fd-9ecb-ff734d04670f`

Enforced via migration `2026_10_18_010000_enforce_eight_primary_accounts_and_widen_quality.php` with a database uniqueness constraint on `primary_strategy_id`. BTCUSDT and ETHUSDT share capital only within each strategy's account ($10,000 virtual USDT); zero cross-account capital pooling.

Verified via `tests/Feature/PaperTrading/PrimaryAccountMigrationRehearsalTest.php` (74 assertions confirming exact UUID, balance retention, peak equity, realized PnL, and open positions across migrations).

## 2. One-Way Position Conflict Policy
- Implemented in `PaperProcessingService::assessment`:
  - Opposite side proposal when an open position exists -> rejected with `position_conflict` (recorded as SKIP:POSITION_CONFLICT).
  - Same side proposal when an open position exists -> rejected with `position_already_open`.
- Arbitrary reversal, pyramiding, and unvalidated hedging are blocked.

## 3. Financial Accounting & Reconciliation
- Strict decimal-safe accounting via `Brick\Math\BigDecimal` for all wallet and ledger balance mutations.
- `trade_net_PnL = realized_price_PnL - execution_fees - allocated_funding`.
- Net R calculation: `net_R = attributable_net_trade_PnL / initial_monetary_risk`.
- Daily reconciliation recalculates equity and transaction integrity independently; mismatches halt new exposure for the affected account.
- Verified in `PaperLifecycleTest` and `SupportResistanceStrategiesTest`.
