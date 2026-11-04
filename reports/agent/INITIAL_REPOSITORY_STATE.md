# Initial repository state — 2026-11-04

## Actual checkout

- Working directory: `/home/thunder/work/traderbybit`.
- Branch: `main`, tracking `origin/main`; HEAD `afd5d194f02e3478c5c6c2e4dfdd00380c216a00`.
- Remote: `git@github.com:Thunderkiss52/traderbybit.git` (fetch/push). No remote operation performed.
- Initial `git status --short --branch`: two untracked files, `VECTOR_INDEPENDENT_AUDIT_20261031.md` and `VECTOR_RELEASE_HARDENING.patch`; no tracked modifications or staged changes. Both remain untouched.
- Initial `git diff --stat` and `git diff`: empty. `git log --oneline --decorate -40` shows later research, Binance, CI, deployment, and WebSocket fixes after the older roadmap work; most recent three are `afd5d19`, `cc9d25a`, `622b8a6`.
- Main checkout is a Git repository. The R9 ZIP is a source snapshot without trustworthy Git ancestry.

## R9 comparison

The nested `VECTOR-R9-FIXED-SOURCE.zip` contains 1,664 file entries. Excluding `.agent/` and archived `bybit_test/` evidence, hash comparison against 1,473 tracked checkout files gives 1,470 shared paths: 1,409 byte-identical, 61 changed, 92 R9-only, and 3 checkout-only. This is a divergent checkout, not an exact R9 restoration. R9-only paths include the separate historical-backtest audit ZIP subsystem, `CandleWindowCoverage`, and candidate Go phase packages. Changed paths include `DatasetInspector.php`, `AuditExportService.php`, the Go backtest/replay implementation, and related tests. File presence is not acceptance evidence.

## Safe implementation strategy

Keep `main` and the two untracked user files intact. Treat R9 patches and source as historical candidates. Trace current consumers before porting any change; make only focused, tested fixes. Use isolated PostgreSQL for mutation tests, local commits only, and no push, deployment, production database access, exchange activation, or financial-history changes. The current PHP 8.5 CLI lacks PHPUnit-required XML/mbstring extensions; testing must use a separate compatible runtime.

## Baseline progress tracker

`python3 scripts/vector_progress.py --generate` exited 0 and reported provisional implementation, verification, and product scores of 0% for scope `VECTOR-SCOPE-20261027-v1`; Phase 0 has 45 requirements and is blocked. It rewrote two generated tracker files locally, which were immediately restored from the known clean initial HEAD. This score is tracker evidence availability, not a claim that source functionality is absent.
