# P-1 audit-follow-up evidence — 2026-10-21

Working tree only. No commit, push, deployment, migration or Docker operation was performed.
All database-backed tests used the repository's isolated in-memory SQLite configuration.

## Environment

- HEAD: `4a04261661a86b69f4070647f8cba4ab150aa9fa` (`main`, uncommitted working tree)
- PHP: 8.4.24 (CLI, NTS); PHPUnit via `php artisan test`
- Node: v20.20.0, npm 10.8.2 (frontend `engines` declares >=22; tests and build executed successfully)
- PostgreSQL integration: not reachable/isolation unverified, integration test skipped 2 tests

## Commands and authentic results

| Command | Exit | Result |
|---|---:|:---|
| `XDEBUG_MODE=off php artisan test --no-ansi` | 0 | 622 tests, 587 passed, 35 skipped, 3390 assertions |
| `XDEBUG_MODE=off php artisan test tests/Unit/Research/SlippageAllocationTest.php` | 0 | 11 passed, 68 assertions |
| `XDEBUG_MODE=off php artisan test tests/Unit/Research/SlippageAttributionServiceTest.php` | 0 | 6 passed, 40 assertions |
| `XDEBUG_MODE=off php artisan test tests/Unit/Research/P1ArchiveEvaluationTest.php` | 0 | 9 passed, 35 assertions |
| `XDEBUG_MODE=off php artisan test tests/Feature/Research/SlippageAttributionApiTest.php` | 0 | 3 passed, 21 assertions |
| `XDEBUG_MODE=off php artisan test tests/Integration/SlippageAttributionPostgresIntegrationTest.php` | 0 | 2 skipped |
| `vendor/bin/pint --test <targeted files>` | 0 | passed |
| `php scripts/p1_archive_evaluation.php --data-dir=audit_data --out=reports/profit-growth/P1_evidence.json` | 0 | 38 positions, 76 fills, status COMPUTED, verified true |
| `npm test` (frontend) | 0 | 54 passed, 0 failed |
| `npm run build` (frontend) | 0 | TypeScript clean, Vite built in 8.45s |
| `go test ./...` (research-go) | 0 | all packages passed |

Raw logs: `php-full.log`, `fe-test.log`, `fe-build.log`, `p1_eval_official.log`, `go-test.log`.

## Regenerated archive values

- Realized-scope Net PnL: `-260.362805849568` USDT
- Realized allocated drag: `55.81139068` USDT
- Open unallocated drag: `5.0499285` USDT
- Hypothetical realized-scope Net PnL: `-204.551415169568` USDT
- All-positions baseline (reference only): `-274.247331763893` USDT
- Partial closes in archive: `0`; invalid rows: `0`

## Limitations

- The archive contains no partially closed position. The partial-close branch is covered by
  deterministic unit/service/evaluator fixtures, not by forward-paper rows.
- PostgreSQL uniqueness, idempotency and concurrency remain unverified in this environment.
- `git-diff-stat.txt` omits untracked files (`SlippageAllocation.php`,
  `p1_archive_evaluation.php`, new tests, `researchSemantics.ts`); see `git-status.txt`.
