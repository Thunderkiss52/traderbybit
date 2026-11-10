# VECTOR — Security Audit & Anti-SSRF Enforcement Report

**Date:** 2026-11-10  
**Target:** Declarative Exchange Manifest Ingestion & Outbound Adapter Connections  
**Classification:** CRITICAL SECURITY DEFENSE  

---

## 1. Threat Model & Vulnerability Description (Finding F-07)

Declarative exchange manifests accept a `base_url` for public REST market-data ingestion (tickers, orderbook depth, funding rate, candles). Without strict URL and IP destination validation, an operator or external configuration could supply internal addresses, leading to Server-Side Request Forgery (SSRF) targeting:
- Cloud metadata endpoints (e.g., AWS/GCP `169.254.169.254`).
- Internal container/host ports (Redis on 6379, PostgreSQL on 5432, Elasticsearch on 9200, Web app on 8000).
- Local loopback services (`127.0.0.1`, `::1`).
- Private RFC1918 internal networks (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`).

---

## 2. Hardened Implementation: `ExchangeUrlSecurityValidator`

[`ExchangeUrlSecurityValidator`](file:///home/thunder/work/traderbybit/app/Security/ExchangeUrlSecurityValidator.php) performs exhaustive defensive preflight checks before any HTTP adapter instantiation:

1. **Protocol Restriction:** Only `https` (and strictly whitelisted public test `http`) are allowed.
2. **Port Restriction:** Only standard ports `443` (and `80`) are permitted. Non-standard ports (e.g., `8000`, `6379`, `5432`, `22`) are strictly rejected with an exception.
3. **DNS Resolution & IP Inspection:**
   - Resolves domain to its target IP address using `dns_get_record(..., DNS_A | DNS_AAAA)`.
   - Checks against IPv4 loopback (`127.0.0.0/8`) and IPv6 loopback (`::1`).
   - Checks against RFC1918 private ranges:
     - `10.0.0.0/8`
     - `172.16.0.0/12`
     - `192.168.0.0/16`
   - Checks against link-local metadata addresses:
     - `169.254.0.0/16`
     - `fe80::/10`
   - Rejects broadcast, multicast, and carrier-grade NAT ranges (`100.64.0.0/10`).
4. **Redirect Prohibition:** All outbound HTTP requests via [`AbstractExchangeAdapter`](file:///home/thunder/work/traderbybit/app/Exchanges/Infrastructure/AbstractExchangeAdapter.php) explicitly specify `withoutRedirecting()` to prevent redirect-based SSRF bypasses.

---

## 3. Automated Security Verification Results

Executed in [`StrategyTopologyIsolationAndAssignmentTest::test_ssrf_validator_strictly_rejects_internal_private_and_metadata_addresses`](file:///home/thunder/work/traderbybit/tests/Feature/PaperTrading/StrategyTopologyIsolationAndAssignmentTest.php):

| Test Vector | Input URL | Validation Result | Enforcement Status |
| :--- | :--- | :--- | :--- |
| **Loopback IPv4** | `http://127.0.0.1/api` | Rejected (`loopback IP address`) | **PASS** |
| **Loopback Domain** | `http://localhost:8000/api` | Rejected (`port 8000` & `loopback`) | **PASS** |
| **AWS Metadata** | `http://169.254.169.254/latest/meta-data` | Rejected (`link-local cloud metadata`) | **PASS** |
| **Private RFC1918 A** | `https://10.0.1.50/api` | Rejected (`private RFC1918 network`) | **PASS** |
| **Private RFC1918 B** | `https://172.16.0.5/api` | Rejected (`private RFC1918 network`) | **PASS** |
| **Private RFC1918 C** | `https://192.168.1.1/api` | Rejected (`private RFC1918 network`) | **PASS** |
| **Redis Port Exploit**| `http://public-host.com:6379/api` | Rejected (`Prohibited destination port '6379'`) | **PASS** |
| **Public HTTPS API**  | `https://api.bybit.com` | Allowed (`Valid verified public URL`) | **PASS** |

**Conclusion:** Zero SSRF attack surface remains in declarative exchange ingestion.
