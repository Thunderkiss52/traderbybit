# Security review — local scope

No real-order path, live execution switch, trading credential, production mutation, push, or deployment was added. The temporal and ZIP changes fail closed on incomplete or changed evidence. The audit ZIP verification streams bounded 64 KiB chunks after archive creation; the export retains its existing archive size limit. The history inspector reads exchange-scoped source rows and does not cross-load Binance/Bybit market data.

## Findings requiring follow-up

- The baseline Composer scan found one medium `symfony/mime` CVE-2026-45070 finding. The lockfile was upgraded from v8.1.7 to v8.1.10, the first fixed 8.1 release identified by Symfony: <https://symfony.com/blog/cve-2026-45070-email-header-injection-via-non-token-characters-in-mime-parameter-names>.
- The baseline npm scan found six high production dependency findings through Vue I18n and `follow-redirects`. The lockfile now has Vue I18n 11.4.18 and `follow-redirects` 1.16.2, above the upstream patched thresholds: <https://github.com/advisories/GHSA-83rm-6c8r-g728>, <https://github.com/advisories/GHSA-mrwp-f3q8-3g9p>. Current local `composer audit --locked` and `npm audit --omit=dev --audit-level=high` both exit 0 with zero advisories. This is a lockfile scan result, not an exploitability assessment or deployed-image scan.
- API audit routes appear to use throttling and a signed relative download URL, with `ValidateBrowserOrigin` middleware and documented reverse-proxy HTTP Basic authentication. Effective production proxy authentication and per-user authorization were not verified. Anonymous unsigned download was rejected by a feature test; this does not establish deployment authorization.
- Archived Phase 6 SSRF/DNS-rebinding candidate controls are not in current source. No new offline import or configurable exchange URL was introduced in this stage. Request-boundary review remains open before any new adapter is accepted.

No credential scan, full dynamic penetration test, deployed proxy inspection, or production secret inspection was performed.
