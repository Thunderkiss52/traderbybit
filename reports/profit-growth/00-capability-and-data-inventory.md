# G0 — Capability & Data Inventory (Reality Audit)

**Spec:** `VECTOR_NET_PROFIT_GROWTH_SPEC_V1_1.md` (v1.1), §3 G0
**Status:** G0 COMPLETE — gate table below; no unsupported current-profit claim made
**Date / timezone:** 2026-10-20, all timestamps UTC
**Code version:** HEAD `f2e3ace7b65a0d60f9cae4bd0c1070b4f3721c55`, worktree **dirty** (QIM changes untracked/modified; P0 baseline was produced at `19cf504…` — see `reports/profitability/P0_baseline.md`)
**Production writes:** none. QIM ingestion not enabled. No migrations, no commits.

---

## 1. Source snapshots & evidence hashes

| Artifact | SHA-256 |
| --- | --- |
| `vector-audit-1d-e534ac49-60de-5263-9685-4bea563094c2.zip` (authoritative for §1 reference; matches `P0_baseline.md` recorded hash) | `54da31d20c396901c723f8f3255ba0bbda1e39d9918664d4b48a3a84d5d1c0b4` |
| `vector-audit-1d-53d90c98-a643-5bd0-8b5b-d3f90bdf7757.zip` (second archive, **not referenced by the spec** — operator to confirm authority) | `ee703740899b0b378b1d8adb70f2c6cfb21484a630be41a49783db9917db2d5a` |
| `VECTOR_PROFIT_1_Revision_2_1_Full_Spec.md` (governing PROFIT-1 spec) | `6c56efe96f1d2d35263615b0b7608c7077e76a0a3734a92bd722afea7e5d86c0` |
| Extracted key files (from manifest, verified): `virtual_positions.csv` | `2344c85bc77dac5711662525944017fc91bad4db586034ec70fd70de4d5a6e80` |
| `virtual_executions.csv` | `21b66b96b4e4c4d347031426bf35f7740c5c876ce85a7b69a0938a8cbe678353` |
| `paper_accounts.csv` | `4b6ae77ff5f751188504b633825481f7ad6d36d951e6fb3d845109a2e0490662` |
| `equity_snapshots.csv` | `3df1bafcb2b16e0ab61a21d5a36f3e4995c17bac71af32244ea47210ca0ac936` |

Archive manifest (`manifest.json`): window `[2026-10-18T14:45+00:00, 2026-10-19T14:45+00:00)`,
`source_consistency = BOUNDED_BEST_EFFORT_NOT_REPEATABLE_READ`,
`mutable_position_snapshots_may_change_after_cutoff = true`.
**All 32 manifest-listed file checksums verified against extracted files — 32/32 match.**

## 2. Historical reference reproduction (§3 G0.3)

Recomputed directly from the original ZIP (decimal-exact Python sums over `virtual_positions.csv`, 29 rows):

| Quantity | Spec §1 value | Reproduced from ZIP | Match |
| --- | ---: | ---: | --- |
| gross PnL | +142.541066420000 | 142.541066420000 | ✅ |
| fees | 104.499815521882 | 104.499815521882 | ✅ |
| signed funding | −0.535570456588 | −0.535570456588 | ✅ |
| net PnL | +37.505680441530 | 37.505680441530 | ✅ |
| identity `gross − fees + funding = net` | holds | holds (38.041250898118 − 0.535570456588 = 37.505680441530) | ✅ |
| starting capital (8 accounts) | 80,000.00 | 80000.000000000000 | ✅ |
| total current equity | 79,498.241177654195 | 79498.241177654195 | ✅ |
| equity vs initial | −0.6272% (not a one-day return) | −0.6272% (derived) | ✅ |

Independent cross-check: the same four totals are asserted by the existing golden test
`tests/Integration/FinancialAccountingAndReconciliationPostgresIntegrationTest.php:105–141`.
This ZIP reproduction is **not** an acceptance output for any production window.

## 3. Ledger population & scope boundaries

Archive populations (from `summary.json`): 8 paper accounts, 29 positions (28 closed + 1 partially
closed), 65 executions, 1,888 equity snapshots, 632 reconciliation runs, 6 market funding events,
11 position funding events. **Position-snapshot totals must not be mixed with event-window fee
totals, equity-delta totals, or execution populations** (README + manifest warning; enforced by test #5 of the spec).

## 4. Capability inventory — G0 gate

| # | Proposed capability / dependency | Status | Evidence |
| --- | --- | --- | --- |
| 1 | PROFIT-1 spec exists; P-3 rejected-signal replay NOT AUTHORIZED | **EXISTS_VERIFIED** | `VECTOR_PROFIT_1_Revision_2_1_Full_Spec.md` (1459 lines); prohibitions at lines 38, 151–152, 870, 1288 |
| 2 | Gate 6 promotion policy (precommitted, existing) | **EXISTS_VERIFIED** | `config/strategy.php:124–138` v`4.0.0` (train≥300, OOS≥100, shadow≥100, challenger≥14d/≥50, max_dd 12%, Bonferroni+HAC lag 4); `app/Research/Application/PromotionPolicyService.php`; `tests/Feature/Research/Gate6PromotionPolicyTest.php` |
| 3 | P0 read-only baseline already delivered | **EXISTS_VERIFIED** | `reports/profitability/P0_baseline.md` — reuse, do not duplicate |
| 4 | `/api/v1/research/profitability/*` endpoints (P-1…P-6) | **MISSING** | 0 matches for `profitability` in `routes/` + `app/` |
| 5 | P-1/P-2/P-4/P-5/P-6 tables (`slippage_attribution*`, `strategy_risk_metrics`, `position_excursion_metrics`, `research_decision_records`, …) | **MISSING** | 0 matches in `database/migrations/` |
| 6 | Ledger reconciliation (G1 dependency) | **EXISTS_VERIFIED** | `app/Research/Application/LedgerReconciliationService.php`; routes `research/health/reconciliation-runs`, `research/health/reconcile` (`routes/api.php:17–18`) |
| 7 | Position/net/fees/funding statistics (G1 dependency) | **EXISTS_VERIFIED** | `PerformanceStatisticsService.php:36–179`, `DailyPerformanceService.php:96–171`, `PositionLifecycleService.php:101–110` (net identity), endpoints `routes/api.php:50–56` |
| 8 | Population/portfolio metrics (G1 dependency) | **EXISTS_UNVERIFIED** | `QuantPortfolioMetricsService` computes population economics + clusters but has **no HTTP route**; used only by `tests/Integration/QuantPortfolioMetricsPostgresIntegrationTest.php` |
| 9 | `virtual_executions.slippage` semantics: price delta, not USDT | **EXISTS_VERIFIED** | `PaperExecutionEngine.php:41,95–99` (`abs(fill − reference)`); USDT conversion only in `PerformanceStatisticsService.php:77–82` (`quantity × slippage`); golden: price 82260.4488 → slippage 16.4488. **No DB comment / stored units** — documentation gap |
| 10 | Fee rates: VIP0 0.055%/0.020% configured | **EXISTS_VERIFIED** | `config/paper.php:10` (`taker_rate=0.00055`, `maker_rate=0.00020`); **rates are unversioned config** (spec §1 requires versioned rates — gap). `maker_rate`/`default_liquidity` are **dead config**: paper fill is always taker |
| 11 | Signed funding, historical events, no hardcoded 8h in forward | **EXISTS_VERIFIED** | `market_funding_events` + `position_funding_events` (migration `2026_10_14_000000…:106–131`); sign logic `PaperExecutionEngine::funding` (`:83–88`); sync `FundingHistorySynchronizer` (30-day lookback, hourly cron `routes/console.php:39–41`); 8h only in backtest control `config/backtest.php:12` |
| 12 | Eight primary accounts intact + safety tests | **EXISTS_VERIFIED** | migration `2026_10_18_010000…:33–95`; `PaperAccountService::allPrimaryAccounts()`; `PrimaryAccountMigrationRehearsalTest`, `LedgerReconciliationPostgresIntegrationTest:105–117` |
| 13 | QIM (spec §0.5 dependency) | **EXISTS_VERIFIED** | flag `config/quote_integrity.php:16` (default false), 8 `qim_*` migrations, `QuoteIntegrityController`, routes `quote-integrity/*` (`routes/api.php:105–114`), UI `/quote-integrity`, 4 console commands, evidence `reports/evidence/qim_{q0,l1}_evidence.json`. No scheduled QIM jobs (flag OFF). See correction (a): spec cited a non-existent `QUOTE_INTEGRITY_SPEC_FINAL_EN.md` |
| 14 | Metric registry (spec §4) | **MISSING** | no `metric_registry`/registry implementation anywhere |
| 15 | `reports/profit-growth/` | **MISSING → created by this report** | directory created 2026-10-20 |
| 16 | Archive ZIP (spec §8) | **EXISTS_VERIFIED** | both archives in repo root; checksums §1 above. Spec references only one — correction (f) |

**G0 gate: PASSED** — every proposed capability above has a status; no current-profit number is claimed as verified.

## 5. Current read-only diagnostics (§3 G0.4)

- Production database: **not accessible from this environment → `CURRENT_STATE_NOT_VERIFIED`** for all current profitability metrics. Historical §1 numbers were **not** substituted as current.
- Test database `bybit_test` (container `bybit-test-pg`, read-only SELECTs, 2026-10-20 16:47 UTC) — **test fixture state recorded, not economics**:
  9 accounts / starting_balance 90000 / equity 90000; positions 20 CLOSED + 5 OPEN with all-zero PnL; 0 executions; 99 reconciliation runs. Test-DB account count (9) ≠ enforced production set (8) — fixture-only.

## 6. Bounded change list — missing functionality only (§3 G0.5)

1. **Metric registry** (spec §4) — new, machine-readable definitions; no composite “Profit Score”.
2. **G1 dashboard assembly** — read-only endpoints/UI composing *existing* services (reconciliation, statistics, daily performance) + exposing `QuantPortfolioMetricsService` (currently test-only); do not rebuild existing research pages.
3. **Fee-rate versioning** — record-only versioned fee/funding config (spec §1); no rate changes.
4. **Slippage unit documentation** — DB comment + metric-registry entry stating price-delta semantics and verified USDT derivation rule; no double subtraction.
5. **Maker/taker role reporting** — report fills of record as 100% taker (hardcoded in execution path); `UNKNOWN` only when provenance missing (correction (c)).
6. **Not in this change list (gated):** P-1…P-6 tables/endpoints, G2 executor, G3 promotion, G4 outreach, P-3 replay (prohibited).

## 7. Findings requiring operator verdict (no action taken)

- `virtual_positions.funding_complete` is set `false` on open (`PositionLifecycleService.php:53`) and never returns to `true` for forward-paper positions (only backtest sets it) — accounting-flag defect candidate.
- `QUOTE_INTEGRITY_ENABLED` absent from `.env.example` / `.env.production.example`.
- Dead config `maker_rate`/`default_liquidity` in `config/paper.php`.
- Two overlapping report trees: `reports/profitability/P0_baseline.md` (existing) vs required `reports/profit-growth/` — recommendation: keep P0 in place, reference it (correction (e)).

## 8. Commands, exit codes, negative controls

| Command (abridged) | Exit |
| --- | --- |
| `git rev-parse HEAD`, `git status --porcelain` | 0 |
| `sha256sum` both ZIPs + `VECTOR_PROFIT_1_…Spec.md` | 0 |
| `unzip -l` / extract to `/tmp/opencode/veczip` | 0 |
| Python: manifest checksum verification (32 files), decimal totals reproduction | 0 |
| Python: tamper control — corrupted expected hash fed to verifier | 0 (mismatch **detected**, see below) |
| `grep -rn profitability routes/ app/` | 0 matches → 0 |
| `grep -rni 'slippage_attribution\|rejected_signal' database/migrations/ app/ config/ routes/` | 0 matches → 0 |
| `docker exec bybit-test-pg psql …` (read-only SELECTs) | 0 |

Negative controls:
1. **Tamper control:** verifier reports mismatch when the manifest hash is deliberately altered → `tamper_detected: True` ✅
2. **Scope control:** P-1…P-6 endpoint/table greps return 0 → no unauthorized implementation present ✅
3. **P-3 control:** rejected-signal replay artifacts absent from migrations/app/config ✅

## 9. Limitations

- One-day `BOUNDED_BEST_EFFORT_NOT_REPEATABLE_READ` window; no strategy profitability inference.
- Production state unverified; test DB ≠ production.
- Archive cutoff is mutable; re-run of a later export will not reproduce identical rows.
- QIM/L1 changes present in worktree are uncommitted and outside this report’s acceptance.
- Gate 6 thresholds read from config file; runtime evaluation not re-executed in G0.

## 10. Next approval needed

1. Approve `VECTOR_NET_PROFIT_GROWTH_SPEC_V1_1.md` (corrections a–f applied) as the governing spec.
2. Approve G1 scope per §6 change list (items 1–5, read-only).
3. Confirm authoritative archive (recommend `e534ac49…`, matches P0 baseline) and disposition of the second ZIP.
4. Verdicts on §7 findings (funding_complete flag, env documentation, dead config).
5. G2/G3/G4 remain NOT_STARTED pending separate express authorization; P-3 remains prohibited.
