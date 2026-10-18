# Gate 3 — Forward UI, API, and Resilience Report

## 1. Dashboard & Interface State Mapping
- Primary accounts displayed with explicit statuses:
  - `account_status`: `ACCOUNT_ACTIVE` / `ACCOUNT_NOT_PROVISIONED` / `ACCOUNT_HALTED`
  - `strategy_status`: `STRATEGY_ENABLED`
  - `evaluation_status`: `STRATEGY_EVALUATING` / `AWAITING_FIRST_CYCLE`
  - `ml_status`: `ML_ACTIVE` / `ML_SHADOW` / `ML_NOT_TRAINED`
  - `paper_execution_status`: `PAPER_EXECUTION_OPERATIONAL`
- **Dashboard Latency & Bounded Queries**:
  - Initial naive row-truncation (`limit(200)`) on 24-hour SKIP reasons was removed to preserve 100% accurate aggregate reporting.
  - Queries are bounded temporally (`decision_time >= now() - 24h`) using composite index `(strategy_id, decision_time)` and narrow column projections (`['reasons']`).
  - Response times verified on populated database (`storage/latency_benchmark.json`): Cold 250.86 ms (< 1500 ms SLA), Warm P95 0.01 ms (< 200 ms SLA).
- UI explicitly mounts global banner warning of research / simulated paper mode (`paper_only: true`).

## 2. Fail-Safe and Degradation Matrix
- **Stale candles**: Block new entries on affected symbol; existing position management remains active.
- **Model failure / invalid artifact**: In ACTIVE mode, missing or invalid model fails closed to `SKIP` (`ml_advisory_no_active_model_fail_closed` / `ml_advisory_corrupt_model_fail_closed`); deterministic baseline execution is restored only when explicitly authorized via `fallback_policy = ALLOW_UNFILTERED`.
- **Go worker / Redis down**: DB-backed fallback protects positions; research pauses safely without corrupting paper trading state.
- **Ledger discrepancy**: Halts new entries for the specific affected account only; does not liquidate open positions prematurely.
