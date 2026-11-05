# CI/CD performance comparison

Measured locally on 2026-11-05: eight logical CPUs, 31.35 GiB RAM, PHP 8.5.12, PostgreSQL 17.11, Redis 7.4, Go 1.27.3, Node 22.23.4 and Chrome 151. Every PHP lane has its own clean checkout, PostgreSQL instance and Redis instance; each PHP container is capped at two CPUs. This is local synthetic verification, not a hosted Actions benchmark or production latency measurement.

| Measurement | Before | After | Evidence/interpretation |
|---|---:|---:|---|
| Hosted total CI wall time | Unavailable | Unavailable | Unauthenticated repository API returned 404; no workflows triggered |
| Local complete PHP, serial | 618.44s | — | Original source: 1 error, 3 failures, 5 skips; not a passing acceptance baseline |
| Local first exploratory partition, two workers | — | 378.09s | Failed isolation/fixture preconditions; excluded from success comparison |
| Local balanced partition, two workers | — | 375.43s | All nine lanes passed; 1,294 cases, existing optional network skip only |
| Local balanced partition, four workers, first pass | — | 280.40s | All nine lanes passed |
| Local balanced partition, four workers, final pass | — | 240.32s | All nine lanes passed; CPU/memory/I/O samples retained |
| PostgreSQL integration case time | 70.30s serial sum | 47.41s longest PG lane | Same discovered cases; optional public API case remains separate from mandatory DB coverage |
| Redis concurrency case time | 2.89s | 6.21s | Two real cross-process Redis/PAPER tests retained |
| PostgreSQL startup/readiness | Not captured separately | 2.21–3.41s/lane | Fresh task-owned clusters |
| Redis startup/readiness | Not captured separately | 0.50–0.68s/lane | Fresh task-owned instances |
| Go tests | 3.65s | 13.50s | 113 same passing case/subcase IDs; baseline warm Alpine cache versus fresh host cache/libc, not a speedup comparison |
| Go race | 18.95s | 22.01s | 113 same passing case/subcase IDs, CGO enabled; caches/environments differ |
| Frontend unit | 0.88s command | 1.33s command | Same 76 cases; no omissions |
| TypeScript + Vite | 13.44s combined | 7.89s typecheck; 9.44s Vite | New CI runs the two checks in separate jobs; local timings include process launch |
| Playwright | Not in original CI | Dashboard 11.13s; builder 46.19s; historical 109.01s; Phase 0 nine checks passed | Exact command timings/logs retained; no before-browser timing comparison available |
| App/web Docker build | Not measured | Aborted after about 392s | Two attempts stalled during npm/apk dependency installation; successful build duration unavailable |
| Go Docker image | Not measured | 15.15s | Local BuildKit image completed; no publication |
| GHCR publication | Not measured | Not executed | No remote publishing authorized/performed |
| CD execution | Not measured | Not executed | No production deployment authorized/performed |
| CD source archive | 9,145,984 bytes | 31,066 bytes | Same source SHA; fixture manifest; all required deployment assets included; runtime code comes from pinned images |

Four workers reduced this local test-phase wall time by 36.0% versus two. The serial baseline was failing, so its difference must not be described as a measured passing CI speedup. The first before/after experiments shared the host with other checks; the final four-worker pass ran after heavy compilation/browser work finished. These are a small number of observations, not a statistical performance guarantee.

| Final PHP lane | Cases | PHPUnit seconds | PostgreSQL ready seconds | Redis ready seconds | End-to-end lane seconds | Result |
|---|---:|---:|---:|---:|---:|---|
| unit-1 | 234 | 14.20 | 2.64 | 0.65 | 25.07 | PASS |
| unit-2 | 267 | 17.94 | 2.48 | 0.68 | 28.89 | PASS |
| feature-1 | 53 | 168.33 | 3.40 | 0.54 | 179.97 | PASS |
| feature-2 | 227 | 185.06 | 3.41 | 0.50 | 196.73 | PASS |
| feature-3 | 183 | 183.82 | 2.68 | 0.65 | 193.66 | PASS |
| feature-4 | 213 | 178.86 | 2.61 | 0.65 | 188.81 | PASS |
| postgres-1 | 58 | 47.41 | 2.64 | 0.64 | 56.90 | PASS |
| postgres-2 | 57 | 34.11 | 2.69 | 0.62 | 43.58 | PASS |
| redis-1 | 2 | 6.21 | 2.21 | 0.59 | 15.05 | PASS |

End-to-end lane time includes temporary checkout preparation, service startup, migrations, PHP startup and owned-container cleanup. It excludes GitHub scheduling, checkout actions, tool installation, dependency downloads and artifact transfer. `php_and_migrations_seconds` is also retained in each benchmark JSON; migration timing messages are in lane logs. Matrix jobs on separate GitHub VMs will not share the local host's disk or PostgreSQL resources.

Measured resource samples: mean PostgreSQL CPU 24.92%, peak 94.38% per container; mean PHP CPU 37.19%, peak 93.82%; maximum sampled aggregate 306.57% (100% represents one CPU). Redis averaged 0.50%. Samples include startup and idle periods. PostgreSQL was not continuously CPU-saturated; I/O contention is not ruled out. Per-lane time increased under concurrency while total wall time fell. Therefore four is the selected measured local setting, not a proven global optimum. Four feature groups also approach the file-granularity bound imposed by the original approximately 125-second historical-research file. No ParaTest workers compete inside a shard.

Reproduce after building the four binaries from the reviewed SHA into a task-owned directory:

```sh
python3 scripts/ci/benchmark_php.py --plan /tmp/ci-plan --binaries /tmp/ci-binaries --output /tmp/ci-bench-2 --workers 2 --ref HEAD
python3 scripts/ci/benchmark_php.py --plan /tmp/ci-plan --binaries /tmp/ci-binaries --output /tmp/ci-bench-4 --workers 4 --ref HEAD
```

The plan is generated with `vendor/bin/phpunit --list-tests-xml /tmp/discovery.xml` and `python3 scripts/ci/php_shards.py plan /tmp/discovery.xml /tmp/ci-plan`. Use PHP 8.5.12 and the required extensions. The benchmark creates only uniquely named task-owned services; final cleanup removes only those containers/anonymous volumes. Earlier exploratory anonymous volumes were not globally pruned because ownership after container removal was not retained.

After an explicitly authorized hosted run, use `GH_TOKEN` with `scripts/ci/collect_timings.py --repository OWNER/REPO --run-id ID --output timings.json`. The aggregate uploads in-progress job/step timings; a post-completion invocation captures completed wall time. BuildKit summaries provide build/export details. Separate publishing/network and production pull/migration/backup/readiness durations still require actual hosted/operator-authorized evidence. Thirty-second CD acceptance remains unverified; the backup, image pulls, storage ownership checks, migrations and graceful worker shutdown remain on the approved execution path.
