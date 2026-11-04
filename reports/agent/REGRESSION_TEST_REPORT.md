# Regression test report — isolated local environment

## Environment

Git baseline `afd5d194f02e3478c5c6c2e4dfdd00380c216a00`; test-only Docker network `vector-agent-temporal-net`, PostgreSQL 17.11 database `bybit_test`, PHP 8.5.12 image `vector-agent-php85-test:local`, local Redis, and a locally built Go research-worker binary. No production database or VPS was accessed. The host PHP lacks PHPUnit-required extensions, so PHPUnit ran in the isolated PHP image. Command output was captured under `/tmp/vector-agent-*.log` during this session.

| Gate / exact inner command | Status and recorded result |
|---|---|
| `php -d xdebug.mode=off artisan test` | PASS, exit 0: 1,173 passed, 9 skipped, 11,100 assertions, 180.54 s. Nine skips retain their own test preconditions; they are not counted as passes. |
| `php -d xdebug.mode=off artisan test tests/Feature/Backtest/DatasetInspectorTest.php tests/Feature/Backtest/BacktestRunServiceTest.php tests/Feature/AuditExportTest.php tests/Feature/Exchanges/BinanceGapRecoveryTest.php` | PASS, exit 0: 29 passed, 981 assertions after the final source edits. |
| `vendor/bin/pint --test` | PASS, exit 0: 740 files. |
| PHP syntax validation over 720 application/test PHP files | PASS, exit 0. |
| `go test ./...`, `go test -race ./...`, `go vet ./...`, `gofmt -l` (in `research-go`) | PASS, exit 0 each; `gofmt -l` returned no files. |
| `npm test`, `npm run build` (in `frontend`) | PASS, exit 0; 64 frontend tests and production build including `vue-tsc --noEmit`. |
| `python3 scripts/vector_progress.py --strict --base-ref afd5d194f02e3478c5c6c2e4dfdd00380c216a00` | PASS, exit 0 for tracker validity; provisional implementation/verification/product scores remained 0% because acceptance evidence is stale/unavailable. |
| `git diff --check` | PASS, exit 0. |
| `composer audit --locked --no-interaction` | FAIL, exit 1: one medium `symfony/mime` advisory. |
| `npm audit --omit=dev --audit-level=high` | FAIL, exit 1: six high transitive production dependency findings. |

The first full PHP attempt failed seven tests because the initial ephemeral runner lacked `psql`, Redis and a Go worker. After those test tools were supplied, a second run reached 1,169 passed with two wall-clock-sensitive Binance recovery test failures. The fixture was isolated from scheduled dispatch; the final full run above passed. These earlier runs are not reported as acceptance.

## R3 submission stage, after the temporal commit

The new migration ran on isolated `bybit_test` (PostgreSQL 17.11), exit 0. Focused `php -d xdebug.mode=off artisan test tests/Unit/Research/ResearchJobServiceTest.php tests/Integration/ResearchJobSubmissionPostgresIntegrationTest.php` passed 23 tests, 96 assertions, exit 0. The complete PHP suite was rerun with the new migration and code: 1,176 passed, 9 skipped, 11,112 assertions, 176.81 s, exit 0. `vendor/bin/pint --test` passed 743 files, exit 0. The nine skips remain skipped; they were not counted as passes. Go and frontend source did not change in this stage, so their earlier full-suite results above remain the applicable checks.

## Locked dependency stage

After installing the updated local Composer lockfile, the complete PHP suite passed again: 1,176 passed, 9 skipped, 11,112 assertions, 178.44 s, exit 0. Under Node 22, `npm test` passed 64 tests and `npm run build` passed including TypeScript; both exited 0. `composer audit --locked --no-interaction`, `composer validate --strict`, and `npm audit --omit=dev --audit-level=high` all exited 0; both audits reported zero advisories. The initial failed audit results above are retained as baseline evidence, not current lockfile status.

## R4 PAPER safety stage

Focused `php -d xdebug.mode=off artisan test tests/Feature/PaperTrading/AccountSafetyRegressionTest.php tests/Unit/PaperTrading/RiskAndSizingTest.php` passed 40 tests and 84 assertions, exit 0. The complete PHP suite then passed 1,184 tests, skipped 9, made 11,137 assertions in 180.05 s, exit 0. `vendor/bin/pint --test` passed 743 files, exit 0. `find app config database routes tests -type f -name '*.php' -print0 | xargs -0 -P 4 -n 1 php -l` validated 723 files with no syntax error, exit 0. Only PHP/PAPER code changed in this stage; earlier Go and frontend checks remain applicable.

## Not established

Historical replay against a real retained dataset, browser visual checks, production read-only financial evidence, immutable database snapshot consistency, true concurrent mutation of an audit export, and deployed performance were NOT_RUN. Archive candidate benchmarks and phase acceptance gates were not silently counted as current verification.
