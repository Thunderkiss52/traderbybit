# Docker, Go Worker & CI Provenance Review

**Date:** 2026-11-04T19:19:00+03:00  
**Authority:** Independent Release Verification Lead & SRE Lead  
**Scope:** Build Pipeline, Containerization, Go Toolchain, CI/CD Workflows, and Image Provenance  

---

## 1. Container Architecture & Build Stages

1. **`app` (PHP-FPM):**
   - Multi-stage Dockerfile (`docker/php/Dockerfile`).
   - PHP 8.5+ with required extensions: `pdo_pgsql`, `pcntl`, `opcache`, `bcmath`, `redis`.
   - Production build uses `composer install --no-dev --optimize-autoloader`.
2. **`web` (Nginx):**
   - Nginx Alpine with built Vue/Quasar assets copied into `/usr/share/nginx/html`.
   - Reverse proxies `/api` to `app:9000` via FastCGI.
   - HTTP Basic Auth configured via entrypoint script (`10-auth.sh`).
3. **`research-worker` (Go):**
   - Multi-stage build (`docker/go/Dockerfile`):
     ```dockerfile
     RUN CGO_ENABLED=0 GOOS=linux go build -ldflags="-s -w" -o /research-worker ./cmd/research-worker
     ```
   - Target image: Scratch / Alpine with static binary copied to `/usr/local/bin/research-worker`.
   - Verified static ELF 64-bit binary, zero dynamic library dependencies.
4. **Queue & Background Services:**
   - Dedicated service definitions in Docker Compose:
     * `queue`: Laravel queue worker (`php artisan queue:work --queue=default,high,low`).
     * `scheduler`: Crontab scheduler (`php artisan schedule:run`).
     * `audit-worker`: Dedicated worker for asynchronous ZIP audit export jobs (`php artisan queue:work --queue=audit`).
     * `backtest-worker`: Dedicated worker for backtests (`php artisan queue:work --queue=backtest`).
     * `market-ws`: Real-time Bybit and Binance WebSocket supervisors.

---

## 2. CI/CD Provenance & Security Gates

1. **GitHub Actions CI (`.github/workflows/ci.yml`):**
   - Strict gate sequencing:
     * `go-tests`: `go mod verify`, `go build`, `go vet`, `gofmt -l`, `go test -race`.
     * `frontend-tests`: `npm ci`, `npm test`, `npm run build`.
     * `php-format`: `vendor/bin/pint --test`.
     * `backend-tests`: PHPUnit unit and integration suites against PostgreSQL and Redis services.
     * `build-images`: Docker Buildx builds `app`, `web`, and `research-worker` images with immutable tags.
   - No image publication occurs if any test lane fails.
2. **GitHub Actions Deploy (`.github/workflows/deploy.yml`):**
   - Operator-triggered workflow (`workflow_dispatch`), strictly restricted to `main`.
   - Requires manual authorization and target environment confirmation.
   - Strictly pulls pre-built immutable images by commit SHA from GHCR; never builds untrusted code directly on the VPS.
   - Enforces post-deployment health check (`docker/scripts/healthcheck.sh`) and automatic rollback on failure.

---

## 3. Deployment Safety Preflight Invariants

- **ECONOM Isolation:** All compose definitions and deployment scripts explicitly namespace containers and networks (`bybit-signals-*`). Zero references or cross-talk with `econom-group` resources.
- **Data Protection:** No deployment script executes destructive migration rollbacks or table drops.
- **Zero Push Guarantee:** All verification commits remain strictly on the local `main` branch. No remote push or deployment was executed.
