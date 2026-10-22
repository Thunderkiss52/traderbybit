# PHP 8.5 / Laravel 13 security verification (stage 4)

Date: 2026-10-22
Branch: `main`
Scope: application/runtime security hardening for the PHP 8.5.12 + Laravel 13.38.0 upgrade. Research and paper trading only; no real orders, no live trading endpoints, no trade-enabled API keys.

## 1. Runtime hardening

### PHP (`docker/php/php.ini`, applied in `traderbybit-php85-app:local`)

Verified by executing the built image (`docker run --rm --entrypoint php ... -r 'ini_get(...)'`):

```
session.upload_progress.enabled=0
session.use_strict_mode=1
session.use_only_cookies=1
session.cookie_httponly=1
session.cookie_samesite=Lax
post_max_size=21M
upload_max_filesize=21M
opcache.enable=1
expose_php / display_errors = Off (empty)
```

- `session.upload_progress.enabled=0` removes the PHP upload-progress session handler; the API does not use it and it previously allowed attacker-controlled session keys.
- Session hardening is defence in depth: the API is stateless and no application route uses sessions. `SESSION_DRIVER=redis` remains unchanged.
- `display_errors=Off`, `log_errors=On` to `stderr`, `expose_php=Off` were already present and remain enforced.

### Nginx (`docker/nginx/default.conf`, `docker/nginx/security-headers.conf`)

- Base image raised `nginx:1.27-alpine` (Alpine 3.21.3) → `nginx:1.30-alpine` (resolved to nginx 1.30.6, Alpine 3.24.3). Reason: 59 HIGH/CRITICAL container findings, including 2 CRITICAL OpenSSL entries (see §5).
- Security headers are shipped as a snippet and included in the server block and in every location that declares its own `add_header` (nginx drops inherited headers in such locations):

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: no-referrer
Permissions-Policy: camera=(), microphone=(), geolocation=()
```

All four use `always`, so 401/404/5xx responses carry them too.

Verified against the built `traderbybit-php85-web:local` image:

```
nginx: configuration file /etc/nginx/nginx.conf test is successful
GET /index.html (valid basic auth)  -> 200 + all four headers
GET /             (no credentials)  -> 401 + all four headers
GET /up           (upstream stub)   -> 502 + all four headers
```

- Basic auth (`/etc/nginx/.htpasswd`, fail-closed `10-auth.sh`) remains mandatory except for `/up` (liveness probe only).
- `location ~ \.php$ { return 404; }`, dotfile denial and `try_files` routing are unchanged.

### Bounded uploads

The application limit is 20 MiB (`config/quote_integrity.php`, `QUOTE_INTEGRITY_MAX_UPLOAD_BYTES`). Transport limits previously equalled 16 MiB, so the documented application limit was unreachable. Aligned so the application can enforce and report its own contract:

| Layer | Limit |
|---|---|
| nginx `client_max_body_size` | 22 MiB |
| PHP `post_max_size` / `upload_max_filesize` | 21 MiB |
| application (`QimEvidenceImportService`) | 20 MiB → HTTP 413 `EVIDENCE_TOO_LARGE` |

Uploads remain stored on the private `local` disk with a UUID filename outside the web root, extension allow-listed, SHA-256 hashed, never auto-promoted. Oversize (413) and disallowed-extension (415) behaviour is covered by existing tests (`QimEvidenceImportTest`).

## 2. Browser-origin / CSRF protection

New middleware `app/Http/Middleware/ValidateBrowserOrigin.php`, appended to the `api` group after `ApiRequestDiagnostics` (so rejections include `request_id`).

Rules for state-changing methods (POST/PUT/PATCH/DELETE):

1. Origin present and listed in `config('cors.allowed_origins')` (`CORS_ALLOWED_ORIGINS`) → allowed (explicit CORS trust).
2. `Sec-Fetch-Site` present and not `same-origin`/`none` → 403 `ORIGIN_NOT_ALLOWED` (browsers cannot forge this header).
3. Origin host differs from the request `Host` → 403 `ORIGIN_NOT_ALLOWED`.
4. No Origin (curl, workers, tests) → allowed; existing basic auth still applies.

Why this was required: HTTP Basic credentials are attached automatically by browsers, and several deliberately client-compatible mutations do not use the research token. CORS alone does not prevent request *submission*, only response reading.

Host-only comparison (scheme/port ignored) keeps the check correct behind the TLS-terminating reverse proxy (`docker/nginx/external-reverse-proxy.example.conf` preserves `Host` and sets `X-Forwarded-Proto`).

Development: `frontend/vite.config.ts` now strips the `Origin` header on the `/api` dev proxy, because Vite would otherwise forward the browser origin (`localhost:5173`) while rewriting `Host`. Production clients are unaffected.

Reads (GET/HEAD/OPTIONS) are never rejected by this middleware.

## 3. Authentication, errors, secrets

- Research-token routes (`research.token`) keep their behaviour: missing → 401 `TOKEN_MISSING`, invalid → 403 `TOKEN_INVALID`, unconfigured → 500 `AUTH_NOT_CONFIGURED`; covered by `QimApiAuthTest`. Origin checks run before the token middleware and cannot bypass it (new test).
- Production config remains `APP_ENV=production`, `APP_DEBUG=false`; API failures return the structured `server_error` body with a request ID and no exception text (`ResearchApiTest::test_server_errors_are_structured_and_have_request_ids`).
- `.dockerignore` excludes `.env`/`.env.*` (except examples); the built app image contains no `.env` (verified with `ls /var/www/html`). Secrets stay in Compose/runtime environment.
- `cache.serializable_classes=false` is deliberately retained; the stage-3 fix stores only scalars/arrays in Redis/file caches.

## 4. Tests and commands

New: `tests/Feature/Security/BrowserOriginProtectionTest.php` (8 tests) and `tests/Feature/Security/RuntimeHardeningConfigTest.php` (3 tests) — 11 tests / 44 assertions, PASS.

```
python3 reports/php85-upgrade/run.py vendor/bin/phpunit tests/Feature/Security   # OK (11 tests, 44 assertions)
python3 reports/php85-upgrade/run.py vendor/bin/phpunit                         # 643 tests, 3770 assertions, 3 failures (integration DB guard only)
RUN_DB=bybit_test python3 reports/php85-upgrade/run.py vendor/bin/phpunit tests/Integration  # OK (35 tests, 304 assertions, 1 skipped)
python3 reports/php85-upgrade/run.py vendor/bin/pint --test <changed PHP files>  # PASS
npm test --prefix frontend                                                       # 54 passed
npm run build --prefix frontend                                                  # vue-tsc + vite build OK
```

The three full-suite failures are `LedgerReconciliationPostgresIntegrationTest` safety aborts because the default run has no `bybit_test` PostgreSQL connection; they pass in the isolated integration run above. Logs: `reports/php85-upgrade/stage4-tests.log`, `stage4-integration.log`.

## 5. Container vulnerability scan (Trivy 0.76.1, DB downloaded 2026-10-22)

| Image | HIGH/CRITICAL |
|---|---|
| `traderbybit-php85-app:local` (Alpine 3.24.3 + Composer vendor) | 0 |
| `traderbybit-php85-test:local` (Alpine 3.24.3) | 0 |
| `traderbybit-php85-web:local` (nginx 1.30.6, Alpine 3.24.3) | 0 |
| baseline `nginx:1.27-alpine` (Alpine 3.21.3) | 59 (2 CRITICAL) |

Evidence: `reports/php85-upgrade/evidence/trivy-{app,test,web}.json`, `trivy-web-baseline-nginx127.json`, `trivy-summary.log`. Scanner binary downloaded from the official release and checksum-verified; it is not part of the repository or deployment.

## 6. Residual risks / not verified

- No Content-Security-Policy was added: the Quasar/Vue build relies on inline styles and the UI could not be browser-tested here. Frame-ancestors is covered by `X-Frame-Options: DENY`.
- All non-token mutations are protected by basic auth plus the origin checks above, not per-user authorization. Single shared operator credential remains by design.
- The scan reflects the local images built from this commit; CI rebuilds may resolve newer base-image patches. Re-scan on release.
- `/up` is unauthenticated by design (liveness only) and exposes no application state.
- Reverse-proxy TLS configuration is an operator example and was not executed in this environment.
