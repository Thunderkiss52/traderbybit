# Remaining defects and technical debt

| Priority | Finding | Evidence / next safe action |
|---|---|---|
| High | Historical runs and audit exports lack a retained immutable dataset or stable multi-query PostgreSQL snapshot. | Before/after checksum and ZIP member verification detect visible changes but cannot prove all intermediate reads used the same version. Design a dataset version and retention pin before claiming reproducible replay. |
| High | R3 worker lease/fencing defects require separate review. | The submission race is locally fixed and cross-process tested. Archived R3 lease-expiry and Go-worker hunks were not applied; verify current worker transitions and PHP/Go parity before any further change. |
| High | Phase 0 is blocked. | All 45 operational obligations lack current release-bound evidence; see `OWNER_ACTIONS_REQUIRED.md`. Local tests and old reports cannot replace row-level read-only production verification and owner attestation. |
| Medium | Deployed dependency state and future advisories remain unverified. | Local lockfile scans now pass after targeted upgrades. No deployed image or production package scan was performed. |
| High | Missing R6 global PAPER capital guard and topology synchronizer. | Archive source differs from HEAD; account/risk policy and historical records must remain unchanged until tests and owner risk review support an implementation. |
| Medium | No current historical-backtest audit ZIP/export replay subsystem. | R8/R9 candidate paths are absent. Do not present forward-PAPER ZIPs as historical simulation evidence. |
| Medium | Input manifest omits immutable row IDs/versions, complete auxiliary strategy inputs and run-wide funding coverage. | Current checksum covers configured candle frames, context/funding, and lead-lag peer, but retained provenance is incomplete. |
| Medium | Archive phase candidates 1.0–7 remain unverified and uncoupled from current Laravel/Go contracts. | Run isolated decimal, Redis fencing, dataset, import, strategy parity, accounting, and SSRF conformance tests before selective integration. No Go financial-writer cutover. |

Initial R9 differs from current HEAD in 61 shared files; each patch requires source-specific validation. Candidate code or a passing local unit test is not production acceptance.
