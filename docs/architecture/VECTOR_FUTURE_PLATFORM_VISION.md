# VECTOR future platform vision

Status: proposed post-remediation program; no feature implementation authorized. Source review: 2026-10-27 at `a12bac4a80f2af3baef00611a0ce6365721f165f`. Repository inspection is not production verification.

## Product responsibilities

VECTOR develops, measures and audits deterministic strategies using public market data, reproducible historical experiments and separately labelled prospective PAPER execution. It should make a new strategy inexpensive to register, make weak candidates inexpensive to reject, and make every claim traceable to evidence. Historical improvement is never proof of future profitability.

The intended PRIMARY population remains 13 strategies on each of Bybit and Binance, with 26 independent accounts. Candidate evaluation accounts, if separately admitted, are research accounts outside that PRIMARY population. Never silently add them to PRIMARY totals or recycle an account's history for a new version.

Laravel owns strategy orchestration, eligibility, risk, account lifecycle, financial journals, governance and APIs. Go performs bounded research computation. Vue/Quasar presents actual evidence and explicit missing-data states. PostgreSQL remains authoritative; Redis is transport/cache, not the source of durable experiment truth.

## Current versus planned

Current source already contains a strategy registry, bounded mutation, Candidate Lab validation, promotion policies, metric registry, reconciliation, audit exports, exchange adapters and Go backtest/WFO/Monte Carlo code. These are extension points, not proof that the target requirements are already satisfied. The [architecture](VECTOR_STRATEGY_PLATFORM_ARCHITECTURE.md) records inspected paths and gaps.

Planned: descriptor-driven strategy registration; immutable executable manifests; reproducible experiment families and selection accounting; unified financial/audit views; verified PAPER rollout and rollback; explicit exchange capabilities. Existing research/learning names do not authorize LLM integration.

## Non-goals and authority

No LLM APIs, AI Director, agent orchestration, local language models, GPUs, generated executable code, arbitrary scripts, dynamic PHP uploads, live credentials, real order endpoints or PAPER-to-LIVE toggle. No new infrastructure platform, new exchange activation or PHP ledger migration to Go is authorized by this program. The older Go migration proposal is a separate, unapproved program, not a dependency.

Research autonomy can generate bounded candidates, schedule experiments, reject candidates and recommend admission. It cannot change PRIMARY allocations or risk limits. Autonomous PAPER promotion remains suspended until a separately accepted rollback and governance program explicitly authorizes it. Operator overrides may halt or reject; they cannot certify missing evidence.

A separate future execution service would own all real-money authorization, risk and exchange credentials. The [boundary proposal](../specifications/VECTOR_FUTURE_EXTERNAL_EXECUTION_BOUNDARY.md) creates no connection to it.

## Success and release gate

Success means reproducible decisions, reconciled financial metrics, lower registration effort, bounded compute, visible uncertainty and safe independently testable milestones—not more trades or attractive backtest returns. No new phase starts before the [Phase 0 acceptance gate](../roadmap/VECTOR_POST_REMEDIATION_IMPLEMENTATION_ROADMAP.md) closes F01–F27 and I01–I09 with evidence appropriate to the claimed environment. Documentation approval is not implementation or deployment approval.

## Specification index

- [Strategy SDK and registry](../specifications/VECTOR_STRATEGY_SDK_AND_REGISTRY_SPEC.md)
- [Deterministic evolution](../specifications/VECTOR_DETERMINISTIC_EVOLUTION_ENGINE_SPEC.md)
- [Analytics and audit](../specifications/VECTOR_STRATEGY_ANALYTICS_AND_AUDIT_SPEC.md)
- [Exchange adapter SDK](../specifications/VECTOR_MULTI_EXCHANGE_ADAPTER_SPEC.md)
- [Phased roadmap](../roadmap/VECTOR_POST_REMEDIATION_IMPLEMENTATION_ROADMAP.md)
