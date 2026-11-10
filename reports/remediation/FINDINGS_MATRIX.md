# VECTOR — Findings Matrix & Remediation Register

> **Date:** 2026-11-10T19:02:00Z  
> **Repository:** `traderbybit` (`main`)  
> **Commit Baseline:** `5390325`  

---

## 1. Findings Register

| Finding ID | Severity | Category | Summary | Status |
|---|---|---|---|---|
| **F-01** | **P0** | Architecture / Topology | Blind $N \times 13$ cross-product of accounts created without operator assignment approval. | **CONFIRMED_OPEN** |
| **F-02** | **P0** | Risk & Execution | `expected_net_edge` passed as `null` and `sample_size` as `0` during live paper pipeline evaluation. | **CONFIRMED_OPEN** |
| **F-03** | **P1** | Accounting & Analytics | Directional performance property key mismatch between backend and Vue frontend (`net_pnl` vs `total_net_pnl`, `fees` vs `total_fees`, `win_rate` vs `win_rate_percent`, `average_r` vs `avg_r`), plus unindexed/unfiltered `$strategyId`. | **CONFIRMED_OPEN** |
| **F-04** | **P1** | Accounting & Audit | 13 Bybit portfolios report `INCOMPLETE` reconciliation due to missing historical funding records and false-positive funding checks on open positions up to `now('UTC')`. | **CONFIRMED_OPEN** |
| **F-05** | **P1** | Research & Slippage | P-1 Slippage Attribution status hardcoded as `IMPLEMENTED_VERIFIED` in `ProfitabilityResearchController` regardless of actual generation state. | **CONFIRMED_OPEN** |
| **F-06** | **P1** | Performance & API | `GET /api/v1/strategy-monitor` aborts after 15s due to 16 sequential full-table scans/counts across `signals`, `strategy_observations`, and `virtual_positions`. | **CONFIRMED_OPEN** |
| **F-07** | **P0** | Security / SSRF | Declarative exchange manifests accept internal IP ranges (127.0.0.1, 10.0.0.0/8, 169.254.169.254, non-standard ports), enabling SSRF. | **CONFIRMED_OPEN** |
| **F-08** | **P1** | Pipeline & Resilience | `TradingPipelineRecoveryService` hardcodes `$exchanges = ['bybit', 'binance']`, preventing candle recovery on the other 8 exchanges. | **CONFIRMED_OPEN** |
| **F-09** | **P1** | Market Data Pipeline | `ProcessClosedCandleEventJob` exclusively captures market context for Bybit and Binance; all other exchanges lack context snapshots. | **CONFIRMED_OPEN** |
| **F-10** | **P2** | Audit & Tooling | Browser audit runner does not perform UI table pagination, omits Model Artifacts / Candidates, and silently caps page sweeps. | **CONFIRMED_OPEN** |
| **F-11** | **P2** | Research & Learning | Candidate validation lifecycle incomplete for newly generated candidates, resulting in 0 promoted candidates. | **CONFIRMED_OPEN** |
| **F-12** | **P1** | Database Safety | Migration `2026_11_10_150000_add_sizing_snapshot_to_virtual_positions.php` requires PostgreSQL index and rollback verification. | **PARTIALLY_RESOLVED** |

---

## 2. Remediation Priority & Ownership Plan

- **Phase 1 (Immediate / P0):** F-01 (Topology Isolation), F-02 (Expected Net Edge Gate), F-07 (SSRF Security).
- **Phase 2 (High / P1):** F-03 (Directional Attribution), F-04 (Funding Reconciliation), F-05 (Slippage P-1 Status), F-06 (Strategy Monitor Performance), F-08 (Recovery Hardcoding), F-09 (Universal Market Context).
- **Phase 3 (Medium / P2):** F-10 (Audit Tooling & Pagination), F-11 (Candidate Lifecycle), F-12 (Migration Verification).
