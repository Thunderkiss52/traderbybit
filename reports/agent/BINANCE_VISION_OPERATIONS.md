# VECTOR — Binance Vision Historical Acquisition Operations Manual

**Document Version:** 1.0  
**Effective Date:** 2026-11-05  
**Target Platform:** VECTOR Quantitative Research & Virtual PAPER Trading Platform  
**Target Exchange:** Binance USD-M Futures (`futures/um`) via Binance Data Collection (Binance Vision)

---

## 1. Operational Overview

The Binance Vision historical data pipeline allows the platform owner to acquire, verify, and validate official historical market datasets and run deterministic Go-powered research replays without writing production code, manual database operations, or impacting active virtual PAPER accounts.

### Preserved Invariants & Safety Constraints
- **PAPER & Research Only:** Absolutely zero real-money order placement or private trading credentials.
- **26 PAPER Accounts Untouched:** All 26 canonical paper trading accounts (13 Bybit + 13 Binance) and their risk-blocked statuses remain strictly preserved.
- **Zero Financial Cross-Contamination:** Historical candles and historical funding events are stored in dedicated schema tables and never modify live-forward paper positions, executions, balances, or drawdown ledgers.
- **Resource Protection:** Bounded memory (streaming chunk size 5,000 rows, channel capacity 1,000), single-archive concurrency, disk space checks, and automatic temporary file cleanup.
- **Zero Intrusion on VPS Colocated Services:** `econom-group` and unrelated services are never touched, scanned, or modified.

---

## 2. Web UI Operations

### 2.1 Navigation Path
Navigate in the VECTOR Dashboard to:  
`Exchanges` → `Binance` → `Binance historical data`  
Direct URL: `/exchanges/binance/historical`

### 2.2 Default Acquisition Preset
The UI provides a pre-configured preset:
- **Provider:** Binance Data Collection (`BINANCE_VISION`)
- **Market:** USD-M Futures (`futures/um`)
- **Instruments:** `BTCUSDT`, `ETHUSDT`
- **Timeframes:** `1m`, `5m`, `15m`, `30m`, `1h`
- **Date Range:** `2024-01-01 00:00:00 UTC` to `2025-12-31 23:59:59.999 UTC` (731 UTC days)
- **Archive Granularity:** Monthly ZIP containing CSV
- **Funding:** Included (Official monthly archives + settlement API marks)
- **Mark Prices:** Optional separate 1m mark-price archives (default: off)
- **Intended Use:** `NONCOMMERCIAL_RESEARCH`

### 2.3 Workflow Steps
1. **Authentication:** Enter the configured Research Owner Token in the "Research owner token" input.
2. **Review / Adjust Parameters:** Select instruments, intervals, and date boundaries.
3. **Dry-Run Preview:** Click `Preview download plan`. The system displays:
   - Planned archive count (240 candlestick ZIP packages for default preset).
   - Expected candle count (~2,771,952 bars).
   - Expected database and temporary storage requirements.
   - Non-commercial licensing terms (`CC-BY-NC-SA-4.0`, `VISION-1.0-2026-08-26`).
4. **Save Plan:** Click `Save plan without downloading` to persist the batch in `PLANNED` state.
5. **Owner Acknowledgement:** Check the terms acknowledgement box and enter an owner reference (e.g. `OWNER-OPS-20261105`).
6. **Start Acquisition:** Click `Start / resume downloads` to transition the batch to `ACTIVE`. The background queue processes archives one by one.
7. **Controls:** Use `Pause downloads`, `Start / resume downloads`, `Retry failed archives`, or `Cancel batch` as needed.
8. **Validate Coverage:** Once downloads complete, click `Validate imported coverage` to verify candle continuity, gap detection, funding completeness, and conflict audits. A successful check transitions the batch to `VALIDATED` (`research_ready = true`).
9. **Launch Historical Backtest:** Under "Run Historical Backtest":
   - Select a registered strategy (from the 13 canonical compiled strategies or custom DSL strategies).
   - Choose symbol, replay UTC range, virtual capital, fee, slippage, and funding mode (`EXACT`).
   - Click `Run Historical Backtest` to launch deterministic Go simulation.
10. **Inspect Research Evidence:** View generated trade features, equity curves, and executions directly in the UI.

---

## 3. CLI Operational Interface (`historical:data`)

The platform provides a dedicated Artisan command: `php artisan historical:data`.

```bash
php artisan historical:data {action} {id?} [options]
```

### Supported Actions

| Action | Description | Required Arguments / Options |
|---|---|---|
| `plan` | Dry-run planning without database mutations | `--input=<path_to_json>` |
| `create` | Persist a durable acquisition batch manifest | `--input=<path_to_json> --key=<idempotency_key>` |
| `status` | View batch status, counts, and active worker lease | `{id}` (Batch UUID) |
| `start` | Authorize and start downloading an acquisition batch | `{id} --reference=<owner_ref> [--terms-version=<ver>]` |
| `pause` | Pause active acquisition | `{id}` |
| `resume` | Resume paused or interrupted acquisition | `{id}` |
| `cancel` | Cancel an acquisition batch | `{id}` |
| `retry` | Retry failed archives in an active/paused batch | `{id}` |
| `validate` | Compute coverage, gaps, funding, and conflicts | `{id}` (Batch UUID) |
| `verify` | Verify immutable dataset pin integrity | `{id}` (Pin UUID) |
| `recover` | Manually dispatch recovery check for orphaned batches/runs | None |
| `research` | Create and queue a historical Go replay run | `{id}` (Batch UUID), `--input=<path_to_json>` |
| `run` | Inspect research run status and summary | `{id}` (Run hash) |

### CLI Usage Examples

#### 1. Preview Download Plan (Dry-Run)
```bash
cat << 'EOF' > /tmp/plan.json
{
  "provider": "BINANCE_VISION",
  "market": "futures/um",
  "symbols": ["BTCUSDT", "ETHUSDT"],
  "intervals": ["1m", "5m", "15m", "30m", "1h"],
  "from": "2024-01-01T00:00:00Z",
  "to": "2025-12-31T23:59:59.999Z",
  "funding": true,
  "mark_prices": false,
  "intended_use": "NONCOMMERCIAL_RESEARCH"
}
EOF

php artisan historical:data plan --input=/tmp/plan.json
```

#### 2. Create and Start Acquisition Batch
```bash
php artisan historical:data create --input=/tmp/plan.json --key=OPS-PLAN-20261105-01

# Inspect created batch ID from JSON output, then:
php artisan historical:data start <BATCH_ID> --reference="OWNER-OPS-BINANCE-2026"
```

#### 3. Monitor Status and Validate Coverage
```bash
php artisan historical:data status <BATCH_ID>

# When all files reach IMPORTED:
php artisan historical:data validate <BATCH_ID>
```

#### 4. Run Historical Backtest Replay
```bash
cat << 'EOF' > /tmp/replay.json
{
  "strategy_id": "trend_pullback",
  "symbol": "BTCUSDT",
  "from": "2024-07-01T00:00:00Z",
  "to": "2024-12-31T23:59:59Z",
  "capital": "10000",
  "fee": "0.0005",
  "slippage": "0.0002",
  "risk_percent": "0.5",
  "funding": "EXACT",
  "intended_use": "NONCOMMERCIAL_RESEARCH"
}
EOF

php artisan historical:data research <BATCH_ID> --input=/tmp/replay.json
```

---

## 4. REST API Specification

All endpoints are prefixed with `/api/v1/historical/` and require the `X-Research-Token` header.

| Method | Endpoint | Description | Key Headers / Parameters |
|---|---|---|---|
| `POST` | `/api/v1/historical/preview` | Preview plan & resource estimate | Body: Plan parameters |
| `POST` | `/api/v1/historical/batches` | Create acquisition batch | `Idempotency-Key`, Body: Plan parameters |
| `GET` | `/api/v1/historical/batches` | List batches (paginated) | Query: `page` |
| `GET` | `/api/v1/historical/batches/{batch}` | Detailed batch diagnostics | Path: `batch` (UUID) |
| `GET` | `/api/v1/historical/batches/{batch}/entries` | Per-file status & audit | Path: `batch`, Query: `page`, `status` |
| `POST` | `/api/v1/historical/batches/{batch}/{action}` | Batch control (`start`, `pause`, `resume`, `cancel`, `retry`) | Path: `action`, Body: `reference`, `terms_version` |
| `POST` | `/api/v1/historical/batches/{batch}/validate` | Compute coverage & pin readiness | Path: `batch` |
| `GET` | `/api/v1/historical/batches/{batch}/audit` | View audit trail events | Path: `batch`, Query: `page` |
| `GET` | `/api/v1/historical/strategies` | List applicable strategies | None |
| `POST` | `/api/v1/historical/batches/{batch}/research` | Start historical Go backtest | Body: Research configuration |
| `GET` | `/api/v1/historical/batches/{batch}/research` | List research runs for batch | Path: `batch`, Query: `page` |
| `GET` | `/api/v1/historical/runs/{run}` | View research run result | Path: `run` (SHA-256 hash) |
| `POST` | `/api/v1/historical/runs/{run}/cancel` | Cancel active replay run | Path: `run` |
| `GET` | `/api/v1/historical/runs/{run}/{artifact}` | Paginated artifacts (`trades`, `equity`, `executions`) | Path: `artifact`, Query: `page` |
| `POST` | `/api/v1/historical/runs/{run}/export` | Export ML features (`train`, `validation`, `holdout`) | Body: `partition`, `limit`, `after` |
| `POST` | `/api/v1/historical/batches/{batch}/walk-forward` | Queue walk-forward windows | Body: Replay config & window settings |

---

## 5. Security & Resource Bounds

1. **Host Allowlisting:** `OfficialTransport` strictly allows:
   - `data.binance.vision` (for `/data/futures/um/...`)
   - `fapi.binance.com` (strictly `/fapi/v1/fundingRate`)
   - Arbitrary URLs, local IP addresses (127.0.0.1, 169.254.x.x, 10.x.x.x, 192.168.x.x), and unsafe protocols are rejected immediately.
2. **Decompression Bounds:**
   - Maximum decompressed archive bytes: 2.0 GB.
   - Maximum individual file size: 1.5 GB.
   - Maximum archive entry count: 100 entries.
   - Decompression timeout: 120 seconds.
   - Directory traversal (`../`) and zip-slip strictly blocked.
3. **Memory & Concurrency Bounds:**
   - 1 active download/import worker per batch by default.
   - Streaming batch size: 5,000 rows.
   - Go channel capacity: 1,000 items.
   - Memory usage: ~22 MiB peak PHP allocation, ~77 MiB RSS (well within 256 MiB target and 512 MiB container ceiling).

---

## 6. Safe Owner-Operated Deployment Procedure

Deployments to production VPS are operator-managed via GitHub Actions (`.github/workflows/deploy.yml`).

### Deployment Checklist:
1. Ensure working tree clean and all tests passing in local verification.
2. Review final diff for unintended modifications.
3. Push to `main` ONLY after explicit owner authorization:
   ```bash
   git push origin main
   ```
4. Trigger the deployment workflow in GitHub Actions (or wait for push trigger if configured).
5. The deployment executes Docker Compose build and service restart.
6. Verify service health post-deployment.

### Post-Deployment Smoke Test:
1. Open VECTOR Web UI in browser: `https://<vps_domain>/exchanges/binance/historical`.
2. Verify Research Owner Token authentication.
3. Click "Preview download plan" for 1-month single-pair range (e.g., BTCUSDT 2024-01 1h). Verify dry-run estimate renders without error.
4. Check that existing 26 PAPER accounts remain intact on `/risk` and `/journal`.
5. Verify scheduler log: `historical:recover` command is running every minute.
