# R3 research-job submission correction — local stage

`ResearchJobService::submitJob` previously used `FOR UPDATE SKIP LOCKED` to find an active `(kind,idempotency_key)` job. With no existing row, or while another submitter held an uncommitted row, independent requests could both insert a job. The table had only a nonunique composite index. This is a current-source defect also described by the archived R3 patch; the patch was not applied wholesale.

The service now takes a PostgreSQL transaction-scoped advisory lock on `(kind,key)` before checking with `FOR UPDATE`. A partial unique index enforces at most one nonterminal job for each pair even for writers outside this service. The migration preflights existing active duplicates and aborts without choosing or rewriting a job; existing data must be investigated before any future deployment if that check fails. Terminal jobs release their key. Exchange-provenance conflict handling is unchanged.

Files: `app/Research/Application/ResearchJobService.php`, `database/migrations/2026_11_04_000000_add_active_research_job_idempotency_index.php`, `tests/Fixtures/submit_research_job.php`, and `tests/Integration/ResearchJobSubmissionPostgresIntegrationTest.php`.

On isolated PostgreSQL 17.11 `bybit_test`, migration ran successfully. Focused `php artisan test tests/Unit/Research/ResearchJobServiceTest.php tests/Integration/ResearchJobSubmissionPostgresIntegrationTest.php` passed 23 tests and 96 assertions. The test uses two separate PHP processes and PostgreSQL lock-wait observations to prove serialization; direct duplicate insert is rejected, a terminal key is reusable, and a preflight with temporary duplicate rows refuses the migration without deleting them. No production job row or database was accessed.

The remainder of R3's worker-lease/fencing patch was not integrated in this unit. Its current-source semantics and Go/PHP coordination need a separate review. The new migration is not production acceptance; a read-only current database duplicate census, deployment window and release gate are still required before operator-triggered deployment.
