# Gate 6 — Autonomous Promotion, Rollback, and Risk Governance

## 1. Complete Gate 6 Promotion Policy (`PromotionPolicyService`)
- **Canonical Lifecycle**:
  `CANDIDATE -> RESEARCH_VALIDATED -> SHADOW -> ISOLATED_PAPER_CHALLENGER -> PAPER_CHAMPION` or `REJECTED / RETIRED / ROLLED_BACK`.
- **Precommitted Statistical Thresholds (`config/strategy.php`)**:
  - `min_training_samples`: $\ge 300$ matured training examples.
  - `min_oos_samples`: $\ge 100$ effective non-overlapping OOS labeled opportunities.
  - `min_shadow_samples`: $\ge 100$ fresh prospective SHADOW observations.
  - `challenger_duration`: $\ge 14$ calendar days AND $\ge 50$ matured prospective candidate opportunities.
  - `risk_adjusted_improvement`: $\Delta \bar{R} = \bar{R}_{\text{challenger}} - \bar{R}_{\text{champion}} > 0$.
  - `multiplicity_adjusted_lcb95`: Strictly positive 95% Lower Confidence Bound ($\text{LCB}_{95} > 0$) computed using:
    - Newey-West HAC variance estimator accounting for serial correlation in overlapping trading horizons.
    - Bonferroni / Dunn-Šidák critical value $z_{\text{crit}} = \Phi^{-1}(1 - \alpha / (2M))$ for $M$ candidate trials ($M=5 \rightarrow z \approx 2.576$).
    - Verified in `Gate6PromotionPolicyTest`: rejects candidates that naively pass under single trial ($z=1.96$) once multiplicity across concurrent trials is applied.
  - `drawdown_ceiling`: Challenger maximum drawdown $\le \text{champion drawdown} + 2.0\%$ AND hard ceiling $\le 12.0\%$.
  - `calibration`: Brier score $\le 0.28$, ECE $\le 0.15$.
  - `forward_authenticity`: In forward production mode, candidate promotion is strictly blocked without authentic prospective observation history; synthetic fixtures are rejected.
- **Immutable Policy Provenance**:
  - Every promotion evaluation and artifact stores `policy_version` (`v4.0.0`), `policy_hash` (SHA256 of configuration array), and linked `evidence_identifiers` (`validation_run_id`, `challenger_account_id`, `champion_artifact_id`).

## 2. Observable Effect on Trading Decisions & Rollback (`ModelAdvisoryService`)
- **Authoritative Decision Veto**:
  - Verified in `ModelAdvisoryDecisionVetoTest`:
  - When an active model predicts low probability ($P < 0.40$), `ModelAdvisoryService::advise` intercepts baseline `BUY` and vetoes it to `SKIP` with reason `ml_advisory_low_probability` and records an autonomy approval event `MODEL_ADVISORY_VETO`.
- **Fail-Closed Policy**:
  - When active model is rolled back or missing, ACTIVE mode defaults to `FAIL_CLOSED_SKIP`: incoming proposals are converted to `SKIP` (`ml_advisory_no_active_model_fail_closed`) to protect from unmonitored execution.
- **Authorized Baseline Fallback**:
  - When explicitly authorized via `fallback_policy = ALLOW_UNFILTERED`, rolling back the model cleanly restores deterministic baseline trading (`BUY`).
- **Champion Restoration**:
  - Atomic pointer switch restores previous superseded champion model (`SUPERSEDED -> ACTIVE`) and re-engages its calibrated inference.

## 3. Risk Gate & Hard Ceilings
- Centralized Risk Gateway enforces per-account ceilings: maximum risk per trade 1.5%, maximum open positions 3, account drawdown limit 5%.
- Primary accounts cannot be pooled; BTC and ETH compete for resources strictly within each strategy's isolated account.
- Production real-exchange trading orders remain strictly prohibited (`paper_only: true`).
