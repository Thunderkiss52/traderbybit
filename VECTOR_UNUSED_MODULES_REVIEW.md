# VECTOR Audit — Unused, Dormant and Candidate Modules Review

## 1. Executive Summary

During the comprehensive audit remediation of VECTOR (commit baseline `858c084`), all candidate unused, dormant, legacy, and orphaned components were systematically inspected across:
- Direct codebase references and symbol definitions.
- Laravel Service Providers, Dependency Injection containers, configuration files (`config/*.php`), console route definitions (`routes/console.php`), and scheduled tasks.
- API route definitions (`routes/api.php`) and controllers.
- Background worker jobs and queue handlers.
- Go research worker (`research-go`) internal packages and commands.
- Frontend Vue/Quasar components and routes (`frontend/src/`).
- Root scaffolding vs subproject configurations.

Every audited candidate has been evaluated against trading safety, research validity, and future architecture roadmap requirements, and assigned an explicit classification:
- **`KEEP`**: Retain in codebase; strategic, fully tested, or active research/infrastructure component.
- **`INTEGRATE`**: Valid functionality requiring explicit wiring or scheduler registration in upcoming roadmap increments.
- **`DEPRECATE`**: Retained for backward read-only compatibility / historical record preservation, but active execution is quarantined or disabled.
- **`REMOVE`**: Dead code with zero dependents, no architectural value, and verified safe deletion.

---

## 2. Detailed Module Audits

### 2.1. `app/QuoteIntegrity/Application/QimAlertService.php`
- **Purpose**: Generates and deduplicates Quote Integrity Monitoring (QIM) alerts across time windows, rules, and symbols. Uses deterministic SHA-256 deduplication hashing: `sha256(ruleVersion:ruleKey:incidentGroup:windowStart:windowEnd)`.
- **References & Wiring**:
  - Entity: `App\QuoteIntegrity\Infrastructure\Persistence\QimAlertRecord`
  - Controller: `QuoteIntegrityController` (`/api/v1/quote-integrity/alerts`)
  - Frontend: `QuoteIntegrityPage.vue`, `QimAlertResource.php`
  - Tests: `docs/progress/evidence/phase0-20261027/php-lint.log`
- **Operational Status**: Dormant / Strategic Infrastructure. The service is fully implemented, syntactically verified, and maps directly to the persisted table `qim_alerts` and the frontend QIM dashboard.
- **Classification**: **`KEEP`**
- **Recommendation**: Retain without changes. The service provides idempotent deduplication for real-time market data quote anomaly alerting.

---

### 2.2. `app/Strategy/Research/FundingOpenInterestFilter.php`
- **Purpose**: Evaluates market context for extreme funding rates (`funding_extreme`) and abnormal open interest changes (`open_interest_change_extreme`) to reject crowded trade setups.
- **References & Wiring**:
  - Registered in configuration: `config/research.php` line 74 (`context_filter` configuration array).
  - Domain types: `App\Strategy\Domain\SignalSide`, `App\Strategy\Domain\StrategyInput`.
- **Operational Status**: Active Research Component. Used by research simulation harnesses and candidate lab evaluation when filtering setups during extreme market positioning regimes.
- **Classification**: **`KEEP`** (Pluggable Research Filter)
- **Recommendation**: Retain. Maintain configuration-driven parameterization via `config/research.php`.

---

### 2.3. `app/Strategy/Application/SignalQualityPolicyService.php`
- **Purpose**: Evaluates signal quality grades from `A` through `E` and `UNRATED` (version `1.1.0`). Implements conservative expectancy estimation using 95% lower confidence bounds (`expectedNetR - 1.645 * uncertainty`). Crucially decouples signal intrinsic technical grading from trade risk execution decisions.
- **References & Wiring**:
  - Domain enums: `App\Strategy\Domain\QualityClass`, `SignalDecision`, `SignalSide`.
  - Comprehensive unit suite: `tests/Unit/Strategy/SignalQualityPolicyTest.php` (verifies grades A-E, UNRATED, sample size thresholds, uncertainty intervals, conservative R lower bound).
- **Operational Status**: Decoupled Strategic Evaluator. Provides canonical quality grading without contaminating paper trading execution boundaries.
- **Classification**: **`KEEP`**
- **Recommendation**: Retain. It is the authoritative implementation for Stage 3 signal quality scoring.

---

### 2.4. `app/Research/Application/MetaLabelingResearchService.php`
- **Purpose**: Evaluates 4 paired policy configurations on identical decision cohorts:
  1. Base Strategy alone
  2. Base + ML Decision Advisor ($P \ge \text{threshold}$)
  3. Base + Quality Grade Policy (A, B, C eligible; D, E, UNRATED filtered)
  4. Base + Combined (Advisor + Grade)
  Enforces strict direction invariance (no policy can invert side from BUY to SELL or vice versa) and calculates Brier score reductions.
- **References & Wiring**:
  - Feature test suite: `tests/Feature/Research/MetaLabelingResearchTest.php` (verifies zero direction flips, Brier score calibration, paired cohort isolation).
- **Operational Status**: Active Research Core. Essential for validating whether ML filtering adds true edge over technical rules without data leakage.
- **Classification**: **`KEEP`**
- **Recommendation**: Retain. Fully covered by regression tests.

---

### 2.5. `app/Research/Application/AdaptiveTimeframeResearchService.php`
- **Purpose**: Analyzes empirical market dynamics across supported intervals (`1m`, `5m`, `15m`, `30m`, `60m`, `240m`, `D`) for trend persistence, turnover, and ATR volatility regimes. Strictly validates causal multi-timeframe hierarchy:
  $$\text{context\_tf} \ge \text{setup\_tf} \ge \text{decision\_tf} \ge \text{trigger\_tf}$$
- **References & Wiring**:
  - Feature test suite: `tests/Feature/Research/AdaptiveTimeframeResearchTest.php` (verifies timeframe hierarchy validation, empirical ATR regimes, trend autocorrelation).
- **Operational Status**: Active Research Core. Validates the structural hierarchy of 1h trend filter, 15m decision proposal, and 5m entry trigger.
- **Classification**: **`KEEP`**
- **Recommendation**: Retain. Guards against inverted timeframe configurations.

---

### 2.6. `app/Research/Application/CounterfactualAnalyzerService.php` (Legacy Branches)
- **Purpose**: Historically replayed SKIP observations into hypothetical trades to estimate counterfactual PnL.
- **Audit Findings**:
  - Replaying rejected signals into hypothetical fills contradicts strict virtual paper trading safety (P-3 risk).
  - The service is quarantined: `legacyAnalysisEnabled()` strictly returns `false` in production:
    ```php
    public function legacyAnalysisEnabled(): bool
    {
        return app()->runningUnitTests() && (bool) config('research.counterfactual.legacy_analysis_enabled', false);
    }
    ```
  - Historical evaluations remain safely readable via `CounterfactualEvaluationRecord`.
- **Classification**: **`DEPRECATE` / `QUARANTINED`**
- **Recommendation**: Keep quarantined behind feature flag. Under no circumstances should live or scheduled execution be enabled for live PAPER accounts. Historical database records remain immutable for research lineage.

---

### 2.7. Root Laravel Scaffolding vs `frontend/` Subproject
- **Audit Findings**:
  - The repository root contains `package.json`, `vite.config.js`, `resources/css`, `resources/js`, and `resources/views`.
  - These files are standard Laravel 11/12 default Vite scaffolding for Blade/Tailwind templates.
  - The authoritative VECTOR frontend dashboard is completely located in `frontend/` (Vue 3, Quasar 2, Vite, Pinia, TypeScript, ECharts, Lightweight Charts).
  - Docker builds and deployments solely build from `frontend/` (`docker/frontend.Dockerfile`).
- **Classification**: **`DEPRECATE` (Root Scaffolding)**
- **Recommendation**: Retain the root scaffolding files without edits because Laravel artisan commands (e.g. package discovery / dev tools) expect the directory structure, but document that production builds strictly ignore root `package.json` in favor of `frontend/package.json`.

---

## 3. Inventory & Classification Matrix

| Module / Component | Path | Status | Recommendation | Rationale & Evidence |
| :--- | :--- | :--- | :--- | :--- |
| **QimAlertService** | `app/QuoteIntegrity/Application/QimAlertService.php` | Dormant / Ready | **KEEP** | Idempotent quote anomaly alert deduplication; backed by `qim_alerts` table and UI. |
| **FundingOpenInterestFilter** | `app/Strategy/Research/FundingOpenInterestFilter.php` | Active | **KEEP** | Configured in `config/research.php`; protects research setups from extreme funding crowdedness. |
| **SignalQualityPolicyService** | `app/Strategy/Application/SignalQualityPolicyService.php` | Active / Decoupled | **KEEP** | Authoritative signal quality grading (A-E/UNRATED); verified by `SignalQualityPolicyTest`. |
| **MetaLabelingResearchService** | `app/Research/Application/MetaLabelingResearchService.php` | Active | **KEEP** | Paired cohort policy evaluator without direction inversion; verified by `MetaLabelingResearchTest`. |
| **AdaptiveTimeframeResearchService** | `app/Research/Application/AdaptiveTimeframeResearchService.php` | Active | **KEEP** | Multi-timeframe causal hierarchy validator; verified by `AdaptiveTimeframeResearchTest`. |
| **CounterfactualAnalyzerService** | `app/Research/Application/CounterfactualAnalyzerService.php` | Quarantined | **DEPRECATE** | Quarantined via `legacyAnalysisEnabled() = false`; historical reads preserved, new replay blocked. |
| **Root Vite Scaffolding** | `package.json`, `vite.config.js` (root) | Scaffolding | **DEPRECATE** | Default Laravel Vite template. Authoritative UI is in `frontend/`. Safe to leave as skeleton. |
