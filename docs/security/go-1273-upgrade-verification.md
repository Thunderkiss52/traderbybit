# Go 1.27.3 upgrade verification

Date: 2026-10-22. Repository `/home/thunder/work/traderbybit`, branch `main`. Local commits only; no push, deployment or workflow dispatch.

## Summary

- Previous Go toolchain in the repository: `golang:1.27-alpine` (floating tag; the resolved binary happened to be built with go1.27.3), module directive `go 1.18`, developer/test environment Go 1.26.9 (Alpine `go` package), committed worker binary built with go1.18.1.
- Final toolchain everywhere: **Go 1.27.3** (`golang:1.27.3-alpine3.24`), module directive `go 1.27.3`, test image Go 1.27.3, committed worker binary Go 1.27.3.
- No source incompatibilities were found. All tests, vet, gofmt, build, race detector and golden/backtest controls pass without changing financial logic.

## Audit findings

| Item | Before | After |
|---|---|---|
| `docker/go/Dockerfile` builder | `golang:1.27-alpine` (floating) | `golang:1.27.3-alpine3.24`, `GOTOOLCHAIN=local` |
| `docker/php/Dockerfile` test stage | Alpine `go` package (1.26.9) | official Go 1.27.3 toolchain copied from `golang:1.27.3-alpine3.24`, `GOTOOLCHAIN=local` |
| `research-go/go.mod` | `go 1.18` | `go 1.27.3` (no separate `toolchain` line needed: the patch-level `go` directive is the minimum toolchain) |
| `research-go/go.sum` | `lib/pq v1.10.9` | unchanged |
| `.github/workflows/deploy.yml` | no Go toolchain | `actions/setup-go@v5` with `go-version: '1.27.3'`, `cache: false` |
| `research-go/bin/research-worker` (tracked, used by `php artisan research:benchmark` as a fast path) | go1.18.1 | rebuilt with Go 1.27.3 |
| `go.work`, Go Makefiles, Go build scripts | none present | n/a |
| CGO / native libs | worker builds `CGO_ENABLED=0`; only `github.com/lib/pq` | unchanged |

Go 1.27 release-note review (go.dev/doc/go1.27): `encoding/json` is now backed by the v2 implementation while the v1 contract and text output are preserved (error message text may differ); language/toolchain changes (generic methods, response files, `go mod tidy` block merging, gofmt alignment fix, `stdversion` vet check, removal of `asynctimerchan`) do not affect this code base. The repository emits JSON via the `encoding/json` v1 API only, asserts no stdlib error strings, has no timers/HTTP clients of note, and the sole dependency (`lib/pq`) is unaffected. No JSON v2 migration was made.

## Compatibility fixes

None required. Changes are toolchain/version pinning only:

- `go mod tidy` under Go 1.27.3 removed the now-inaccurate `// indirect` marker from the directly imported `github.com/lib/pq`; `go.sum` is unchanged.
- `gofmt -l` reports no files (the Go 1.27 alignment change does not touch this code).
- `go vet ./...` (including the new default `stdversion` check) passes.

## Verification (all with Go 1.27.3)

| Command / check | Result |
|---|---|
| `go version` (builder image, test image, host-mounted toolchain) | `go1.27.3 linux/amd64` |
| `go mod verify` | `all modules verified` |
| `go build ./...` | exit 0 |
| `go vet ./...` | exit 0 |
| `gofmt -l .` | no output |
| `go test -count=1 ./...` | all packages `ok` (`go1273-tests.log`) |
| `go test -race -count=1 ./...` | all packages `ok`, no data races (`go1273-race-and-bench.log`) |
| Go tests inside the configured test image (`traderbybit-php85-test:local`) | all packages `ok` (`go1273-integration.log`) |
| Laravel ↔ Go integration (`GoResearchWorkerIntegrationTest`, `ResearchBenchmarkCommandTest`, isolated PostgreSQL) | 2 tests, 16 assertions, OK |
| Worker SIGTERM with the rebuilt image on the isolated database | graceful shutdown, exit code 0 (`go1273-sigterm.log`) |

Binary metadata (`go version -m`):

- `research-go/bin/research-worker`: `go1.27.3`, module `github.com/traderbybit/research-go (devel)`, `lib/pq v1.10.9`.
- `traderbybit-php85-research-worker:local:/usr/local/bin/research-worker`: `go1.27.3`, same build info.
- Runtime image: Alpine 3.24.3, runs as UID 10001.

## Determinism and numerical parity

`research-worker --standalone` (synthetic 1,000-candle benchmark) produces identical financial output before and after the rebuild, across repeated runs and in both the previously built and newly built binaries:

- `Trades=67 WinRate=29.85% NetPnL=-3161.74311831`
- `PATTERNS Found=11`
- `WFO Folds=3 WFE=0.000 Degradation=16.9%`
- `MONTE_CARLO Iterations=500 MedianFinal=6864.87501489 RuinRisk=93.8%`

Only timing/peak-RSS figures vary (12.5–17.6 MB), as expected. The golden tests in `research-go/tests/golden` and the backtest/walk-forward/simulation suites pass unchanged.

## Security scans (Trivy 0.76.1, DB 2026-10-22)

| Image | HIGH/CRITICAL |
|---|---|
| `traderbybit-php85-research-worker:local` (Go 1.27.3, Alpine 3.24.3) | 0 |
| `traderbybit-php85-test:local` (PHP 8.5.12 + Go 1.27.3) | 0 |

Baseline before the PHP migration stage was 29 HIGH/CRITICAL for the Go worker (Go 1.21.13 stdlib, musl). Evidence: `evidence/trivy-go-1273.json`, `evidence/trivy-test-go1273.json`.

## Known warnings and limitations

- `research-go/backtest.test` was a tracked, unreferenced go1.18.1 test binary. Removed from Git in `fccf38e`; `/research-go/*.test` is ignored as of `037dcc8`. The required `research-go/bin/research-worker` artifact was rebuilt with Go 1.27.3 and is preserved.
- The Go toolchain in the deployment workflow is pinned to 1.27.3 (for Go-dependent PHP tests); the `go-tests` job in `deploy.yml` runs `go mod verify`, build, vet, gofmt, tests and the race detector, and gates `backend-tests`/`frontend-tests`, image publishing and deployment. `deploy.yml` remains the only workflow and is `workflow_dispatch`-only; the interim `ci.yml` was removed.
- The deploy workflow was not dispatched; the VPS build path was not exercised here.
- The first `docker buildx` run of the Go image failed once at `go mod download` (transient network); the retry succeeded and the image was verified by metadata and execution.

## Commits

Verification of the final cleanup: local run of the new CI steps with Go 1.27.3 on a cold module/build cache (working directory `research-go`): `go mod verify`, `go build ./...`, `go vet ./...`, `gofmt -l` clean, `go test ./...`, `go test -race ./...` — all passed (`go1273-ci-local.log`). Workflow triggers: `ci.yml` on push/pull_request to `main`; `deploy.yml` remains `workflow_dispatch` only.

- `2acf3e8` — `chore(go): pin toolchain and builders to Go 1.27.3` (module, Dockerfiles, CI setup-go)
- `969fe31` — `chore(go): rebuild tracked research worker with Go 1.27.3`
- `fccf38e` — `ci: add non-deploying Go 1.27.3 verification workflow` (also removed the stale test binary; the workflow was later folded into `deploy.yml` as the `go-tests` job)
- `037dcc8` — `chore(go): ignore compiled Go test artifacts`
- `8c9ed49` — `docs: record Go 1.27.3 cleanup and CI integration`

## Release readiness

Go 1.27.3 upgrade is verified for build, test, race, runtime, integration, determinism and image security. PHP/Laravel behaviour is unchanged (Go-only changes). Production remains undeployed pending operator approval; no production resource or data was touched.
