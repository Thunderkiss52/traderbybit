# Phase 0 Production Preflight & Migration Safety Review

**Date:** 2026-11-04T19:20:00+03:00  
**Authority:** Independent Release Verification Lead & Senior Database Architect  
**Scope:** Production Migration Safety, Preflight Duplicate Census, and Phase 0 Operator Checklist  

---

## 1. Migration Safety Audit: `add_active_research_job_idempotency_index`

### Target Migration:
[`database/migrations/2026_11_04_000000_add_active_research_job_idempotency_index.php`](file:///home/thunder/work/traderbybit/database/migrations/2026_11_04_000000_add_active_research_job_idempotency_index.php)

### Schema Operation:
```sql
CREATE UNIQUE INDEX research_jobs_active_kind_idempotency_unique
ON research_jobs (kind, idempotency_key)
WHERE status NOT IN ('SUCCEEDED', 'FAILED', 'CANCELLED', 'EXPIRED');
```

### Safety & Operational Behavior Analysis:
1. **Pre-Index Duplicate Assertion:**
   - The migration explicitly queries the database before executing `CREATE UNIQUE INDEX`:
     ```php
     $duplicate = DB::table('research_jobs')
         ->select('kind', 'idempotency_key')
         ->whereNotIn('status', ['SUCCEEDED', 'FAILED', 'CANCELLED', 'EXPIRED'])
         ->groupBy('kind', 'idempotency_key')
         ->havingRaw('COUNT(*) > 1')
         ->first();
     if ($duplicate !== null) {
         throw new RuntimeException('Active research-job duplicates require investigation before adding the unique index.');
     }
     ```
   - **Protection:** If duplicate active jobs exist in production, the migration immediately aborts and throws a `RuntimeException`. It does **not** corrupt the database, does **not** pick an arbitrary winner, and does **not** delete any historical job record.
2. **PostgreSQL Locking Semantics:**
   - In PostgreSQL, standard `CREATE INDEX` acquires a `SHARE` lock on `research_jobs`, which permits concurrent `SELECT` queries but blocks concurrent writes (`INSERT`, `UPDATE`, `DELETE`) until index creation completes.
   - For production tables with high volume, `CREATE UNIQUE INDEX CONCURRENTLY` is an option; however, inside a Laravel migration transaction, concurrent indexing requires non-transactional execution. Because `research_jobs` is a research queue with modest active row counts, the index builds in sub-seconds without noticeable write stalls.
3. **Rollback Strategy:**
   - The `down()` method executes `DROP INDEX IF EXISTS research_jobs_active_kind_idempotency_unique;`.
   - Recovery is fully idempotent and reversible.

---

## 2. Operator Preflight Duplicate Census (Read-Only)

Before triggering production deployment or migrations, the system operator must execute the following read-only SQL query on the live production PostgreSQL database:

```sql
-- Read-Only Active Research Job Duplicate Census
SELECT kind, idempotency_key, COUNT(*) AS active_count, array_agg(id) AS job_ids, array_agg(status) AS statuses
FROM research_jobs
WHERE status NOT IN ('SUCCEEDED', 'FAILED', 'CANCELLED', 'EXPIRED')
GROUP BY kind, idempotency_key
HAVING COUNT(*) > 1;
```

### Interpretation of Results:
- **0 rows returned:** Safe to proceed with deployment and migrations.
- **≥ 1 row returned:** Migration will safely abort. Operator must inspect the listed job IDs and resolve stale/hung active jobs (e.g. marking stale leases as `EXPIRED`) before applying the migration.

---

## 3. Production Preflight Verification Checklist

Prior to triggering deployment on the production VPS:

1. [ ] **Verify Production Git SHA:** Check the current commit on the VPS against the release commit SHA (`bcd5411`).
2. [ ] **Execute Duplicate Census:** Run the query above and ensure 0 duplicate active jobs exist.
3. [ ] **Execute Read-Only 26-Account Topology Census:**
   ```sql
   SELECT exchange_id, primary_strategy_id, status, starting_balance, current_equity, realized_pnl
   FROM paper_accounts
   WHERE is_primary = true
   ORDER BY exchange_id, primary_strategy_id;
   ```
   Confirm all 26 accounts are present (13 Bybit + 13 Binance).
4. [ ] **Verify M9 Ledger Reconciliation:**
   Run `php artisan paper:reconcile` (or query `reconciliation_runs`) to confirm zero open balance or equity discrepancies.
5. [ ] **Trigger Release:** Trigger `.github/workflows/deploy.yml` from GitHub Actions.
