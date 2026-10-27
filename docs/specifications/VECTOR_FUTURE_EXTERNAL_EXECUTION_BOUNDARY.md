# Future external execution boundary

Status: architectural proposal only. No live implementation, transport, endpoint, credentials, network destination or PAPER-to-LIVE toggle is authorized. This contract cannot activate execution in current VECTOR.

## Ownership

| VECTOR owns | Independently operated future service owns |
|---|---|
| Strategy/source/parameter identity, market-data provenance, decision validity and research evidence | Real account credentials, trading permissions and account authorization |
| Deterministic strategy decision and research/PAPER outcomes | Independent risk assessment, capital limits and order sizing |
| Decision/revocation identifiers and audit correlation | Exchange order lifecycle, fills, cancellations and private reconciliation |
| Governance recommendation and PAPER admission | Real-money activation approval, deployment, incident response and kill switch |

A research decision is not execution authorization. PAPER approval cannot be converted into real-money permission. External fills never enter VECTOR's PAPER ledger or count as PAPER evidence. Future external observations would require a separate namespace, schema and explicitly approved reporting design.

## Non-operational message proposal

Potential versioned `StrategyDecisionIntent` fields: schema version; message and decision IDs; strategy manifest/hash and parameter hash; exchange-qualified instrument; decision/as-of/created/expiry UTC times; BUY/SELL/SKIP; entry/exit policy references; price zone and research risk hint; input digest; producer build digest; correlation ID; monotonically scoped sequence; idempotency key; intended audience; authentication key ID and signed canonical payload digest. No account credential or claim of permission belongs in this payload. The external service derives permitted accounts and enforceable size independently.

Idempotency key binds immutable decision/version/instrument/audience. A repeated key with different payload is a conflict requiring halt/investigation. Schema major mismatch, expired intent, missing identity, unknown key, replayed nonce or revocation fails closed. TTL cannot be refreshed by retry. Authenticate both sender and recipient; a later security design must choose signing/mTLS, key rotation, replay storage, clock tolerance and trust boundaries. Do not implement cryptography from this conceptual sketch.

Potential acknowledgments distinguish RECEIVED, REJECTED, AUTHORIZED_BY_EXTERNAL_SERVICE, EXECUTION_PENDING and terminal external outcome. Delivery acknowledgment is not risk approval, fill or successful reconciliation. At-least-once delivery requires receiver deduplication, durable acknowledgment and bounded retries; loss of acknowledgment must not create a second order. Callbacks must be authenticated, ordered/versioned and scoped to external state. VECTOR cannot turn a callback into PAPER cashflow.

Revocation references decision/sequence/audience and effective time. Halt is sticky until separately authorized release. A received halt cannot undo a completed fill; the external system owns safe order/position response and records the race. Disconnection defaults to no new execution authority. Revocation, callback delay, replay and clock drift require correlated audit evidence.

## Current impossibility boundary and future gates

The current program adds documents only: no outbound publisher, subscription, real-order route, SDK, destination configuration or live credential storage. Therefore these documents introduce no execution path. Do not overstate this as a security audit of every existing dependency. Any future work needs a separate threat model, credentials/network isolation, authorization design, deployment program and explicit owner approval; configuration alone must never activate it.

Future tests must use a fake external service: tampering, wrong audience, duplicate/conflicting IDs, stale decision, clock skew, key rotation/revocation, acknowledgment loss, callbacks out of order, simultaneous halt/acceptance, unauthorized account and attempted PAPER contamination. Require proof that VECTOR availability and research approval are insufficient to authorize real orders. Real-money tests and credentials are out of scope here.

No database/API/frontend/runtime changes are proposed for this phase. Deliverables are a reviewed responsibility matrix, message schema proposal and threat/test checklist. Performance values for a real service are deliberately unassigned until latency, security and exchange requirements are independently approved. Documentation rollback has no runtime effects.
