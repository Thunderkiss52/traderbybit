# Historical integration matrix — source-qualified

Current authority: Git HEAD `afd5d19`, not the archive. The outer archive has 13 named patches. `git apply --check` was run against a detached, clean `afd5d19` worktree for every patch; a successful check means textual applicability only. No archive patch was applied wholesale. R1/R2 are reconstructed audit-pass labels, not proven release tags.

| Historical item | Intended change / source basis | Current code and tests | Classification | Action |
|---|---|---|---|---|
| R1 senior audit patch | 23 paths, 122 hunks; pre-R2 audit source | All 23 paths exist; current `AuditExportService::writePrimaryCoverage` has the cited R1 coverage behavior. Full semantic parity unproven. `git apply --check` fails. | PARTIALLY_INTEGRATED | Check individual unresolved audit findings; do not replay old hunks. |
| R2 cumulative patch | 43 paths, 214 hunks; includes earlier audit fixes | All paths exist, but source diverged. Check fails. | PARTIALLY_INTEGRATED | Compare findings against current tests before porting. |
| R2 incremental patch | 26 paths, 102 hunks; alternative to cumulative base | All paths exist; check fails. | CONFLICTING | Avoid stacking on cumulative R2. |
| R3 research reliability | 5 paths, 42 hunks; R2/R3 lineage | Active-job submission now uses a transaction lock and partial unique index, with isolated PostgreSQL two-process and duplicate-preflight tests. Other R3 worker-lease/Go hunks remain unassessed. Patch check fails. | PARTIALLY_INTEGRATED | Review lease/fencing hunks separately; production duplicate census remains required before deploying migration. |
| R4 safety evolution | 18 paths, 48 hunks; R3 source | Textually applies to current HEAD. The owner-approved resume journal and hard risk ceilings are absent; 16 paths exist, two sampler paths absent. | MISSING | Review account/risk impact and split into safe units; do not activate blocked accounts. |
| R5 defect fixes | 16 paths, 62 hunks; R4 source | All paths exist but patch fails. | PARTIALLY_INTEGRATED | Verify individual defects against current implementations. |
| R6 topology/global risk | 17 paths, 56 hunks; R5 source | `GlobalPaperCapitalGuard` and `PaperTopologySynchronizer` absent; patch fails. | MISSING | Requires isolated account census, financial regression and owner review of risk policy before rollout. |
| R7 learning/DEMO/risk | 22 paths, 74 hunks; R6 source | 11 paths absent, including DEMO gateway and capacity policy; patch fails. | BLOCKED_BY_ACCEPTANCE_GATE | Keep exchange execution disabled; evaluate learning-only fixes separately. |
| R8 implementation | 33 paths, 102 hunks; R7 source | 23 paths absent, including historical backtest audit service/ZIP; patch fails. | PARTIALLY_INTEGRATED | Design immutable input/version binding before porting export. |
| R9 fixes | 23 paths, 92 hunks; R8 source | 15 paths absent; current `DatasetInspector` differed and R9's `< to` context/funding predicate conflicts with final candle close at `to`. Patch fails. | PARTIALLY_INTEGRATED | Applied a current-source temporal correction, not the patch; historical audit subsystem remains missing. |
| Phase 0 candidate | Phase 0 baseline and evidence packet | 45 requirements, all 45 operational obligations lack current release-bound proof. | BLOCKED_BY_ACCEPTANCE_GATE | Operator checklist in `OWNER_ACTIONS_REQUIRED.md`. |
| Phase 1.0 decimal patch | 5 new `pkg/decimal`/`pkg/types` paths; textually applies | All 5 candidate paths absent; current `internal/decimal` is a different implementation. PostgreSQL/PHP-Go parity unverified. | BLOCKED_BY_ACCEPTANCE_GATE | Do not label accepted; build a separate exact-decimal comparison. |
| Phase 0+1.0 combined patch | 8 paths, alternative/cumulative candidate; textually applies | Five candidate paths absent, three current Go paths differ. | CONFLICTING | Do not stack with standalone Phase 1.0 patch. |
| Phases 1.1–7 consolidated patch | 104 paths, mostly `.agent` evidence and candidate Go packages | Candidate packages absent; check fails. Candidate status document explicitly says local only. | BLOCKED_BY_ACCEPTANCE_GATE | Requirements matrix in `PHASE_ACCEPTANCE_MATRIX.md`; no writer cutover or live execution. |

## Patch disposition

No historical patch was applied verbatim. The current temporal fix adopts verified intent from R9 and corrects its right-boundary assumption for the actual simulator. Textually applicable R4 and decimal patches were intentionally left unapplied because textual applicability does not establish account impact, decimal compatibility, or acceptance. The R3 active-job uniqueness fix was ported selectively after current-source verification; the R8 audit subsystem remains missing. Neither is silently counted as phase acceptance.
