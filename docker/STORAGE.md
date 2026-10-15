# Storage policy

The production preflight blocks deployment below `MIN_FREE_DISK_GB` (10 GiB by
default). `storage-report.sh` reports free space, the two application images,
the three project volumes, container logs, and the largest PostgreSQL tables.

- Runtime images contain no Node.js, Composer binary, dev dependencies, tests,
  or frontend sources. CI uses BuildKit's GitHub cache; the VPS only pulls images.
- The server keeps the current and one previous Bybit Signals image tag. Cleanup
  is repository- and label-scoped, checks container use, and never prunes global
  images, build cache, networks, or volumes.
- Docker logs rotate at 10 MiB times 3 per container. Laravel writes structured
  production logs to stderr, so it shares the same bound.
- PostgreSQL custom-format backups use compression level 9, checksum validation,
  a 14-day/7-copy bound, and always preserve the newest verified copy. An optional
  mounted `BACKUP_EXTERNAL_DIR` receives another verified copy.
- `market_candles` has a unique natural key, so repeated syncs do not duplicate
  rows. Indicator series are calculated rather than persisted. Backtest runs keep
  parameters, checksums, results, trades, and equity points; they do not copy the
  candle dataset or create per-run temporary history files.
- `MARKET_DATA_RETENTION_DAYS=0` disables candle deletion. Before enabling any
  future retention job, measure table growth with `storage-report.sh`, produce and
  export a verified backup, and confirm that retained dataset checksums still meet
  research reproducibility requirements. No candle deletion is scheduled by the
  deployment.

Run the report without changing server state:

```bash
cd /srv/bybit-signals/current
sudo ENV_FILE=/srv/bybit-signals/.env.production \
  DEPLOY_STATE_DIR=/srv/bybit-signals/deploy-state \
  ./docker/scripts/storage-report.sh
```
