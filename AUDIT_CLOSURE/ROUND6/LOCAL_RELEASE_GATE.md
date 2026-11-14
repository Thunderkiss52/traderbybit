# VECTOR V6 — Local integration acceptance (WSL2; no deployment)

This is a handoff for the operator's existing WSL2 environment. **Never run database tests against Production or any non-disposable database.** Before continuing verify that DB_HOST/DB_DATABASE/DB_PORT point to a newly-created, isolated test PostgreSQL database, not real historical accounts.

1. Check runtime: `php -v` (requires 8.5.12), `composer --version`, `go version` (requires pinned Go), `node -v`.
2. In the project repository, apply `PATCH_FROM_V5.diff` against matching V5 files after reviewing the changes; or use the complete V6 payload as the staged source. Do not overwrite a newer Git HEAD without diff/merge.
3. Restore dependencies: `composer install --no-interaction --prefer-dist`, `(cd frontend && npm ci)`, `(cd research-go && go mod download)`.
4. Set APP_ENV=testing and separate isolated PostgreSQL DB credentials; double-check twice. Run only against the disposable test DB: `php artisan test --no-ansi` and target `tests/Feature/PaperTrading/DirectionalPerformanceAndRiskGateTest.php` / `tests/Feature/Audit/SystemUiApiReconciliationAuditTest.php` / `tests/Unit/Backtest/HistoricalExecutionCostPolicyTest.php`.
5. `(cd frontend && npm test && npm run build)` and `(cd research-go && go test ./... && go test -race ./...)`.
6. Run Playwright UI route/pagination scripts against a LOCAL read-only test server. Verify actual model artifacts, 29 browser screenshots and P50/P95 cache miss.
7. Only after full tests pass, inspect Production using authorized read-only queries and compare actual N×M, risk-blocked accounts and financial ledger to preserved reference snapshots. Treat any mismatch as a release blocker.
8. Deployment remains manual GitHub Actions workflow_dispatch, not part of this archive or test run.
