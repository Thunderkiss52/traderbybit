#!/usr/bin/env python3
import json
import os
import sys
import time
import urllib.request
import urllib.error
import csv

BASE_URL = "http://127.0.0.1:8000"
TOKEN = "vector-research-token-2026"

def get_sample_ids():
    # Fetch a sample account ID from /api/v1/paper/accounts
    account_id = "6573dfda-71ed-5444-8d95-08c2a6fadfa7"
    try:
        req = urllib.request.Request(f"{BASE_URL}/api/v1/paper/accounts?exchange=bybit")
        with urllib.request.urlopen(req, timeout=5) as resp:
            data = json.loads(resp.read().decode('utf-8'))
            if data.get('data') and len(data['data']) > 0:
                account_id = data['data'][0]['id']
    except Exception:
        pass
    return {
        "account_id": account_id,
        "exchange_id": "bybit",
        "symbol": "BTCUSDT",
        "strategy_id": "baseline",
    }

def resolve_uri(uri, sample_ids):
    # Replace route parameters with safe sample parameters
    uuid_id = "00000000-0000-0000-0000-000000000001"
    
    # Determine appropriate replacement for {id} based on path context
    if "exchanges/{id}" in uri:
        target_id = sample_ids["exchange_id"]
    elif "strategies/{id}" in uri or "strategy-methods/{id}" in uri or "research/strategies/{id}" in uri:
        target_id = sample_ids["strategy_id"]
    elif "accounts/{id}" in uri or "accounts" in uri:
        target_id = sample_ids["account_id"]
    else:
        target_id = uuid_id

    replacements = {
        "{symbol}": sample_ids["symbol"],
        "{exchange}": sample_ids["exchange_id"],
        "{exchangeId}": sample_ids["exchange_id"],
        "{id}": target_id,
        "{account}": sample_ids["account_id"],
        "{strategyId}": sample_ids["strategy_id"],
        "{strategy}": sample_ids["strategy_id"],
        "{runId}": "1",
        "{packageCode}": "P-1",
        "{positionId}": sample_ids["account_id"],
        "{evaluationId}": uuid_id,
        "{draft}": uuid_id,
        "{experiment}": uuid_id,
        "{batch}": "1",
        "{run}": "1",
        "{artifact}": "report",
    }
    for placeholder, val in replacements.items():
        if placeholder in uri:
            uri = uri.replace(placeholder, val)
    return uri

def run_sweep():
    routes_file = "routes_api.json"
    if not os.path.exists(routes_file):
        print(f"Error: {routes_file} not found.")
        sys.exit(1)

    with open(routes_file, "r") as f:
        routes = json.load(f)

    get_routes = [r for r in routes if "GET" in r.get("method", "")]
    print(f"Starting API sweep across {len(get_routes)} GET routes...")

    sample_ids = get_sample_ids()
    results = []
    total_5xx = 0

    for r in get_routes:
        raw_uri = r["uri"]
        uri = resolve_uri(raw_uri, sample_ids)
        url = f"{BASE_URL}/{uri}"

        req = urllib.request.Request(
            url,
            headers={
                "X-Research-Token": TOKEN,
                "Accept": "application/json",
                "User-Agent": "Vector-ApiAudit-Runner/1.0",
            }
        )

        start_time = time.time()
        status_code = None
        error_msg = None
        content_length = 0

        try:
            with urllib.request.urlopen(req, timeout=10) as response:
                status_code = response.status
                body = response.read()
                content_length = len(body)
        except urllib.error.HTTPError as e:
            status_code = e.code
            try:
                body = e.read()
                content_length = len(body)
            except Exception:
                pass
        except Exception as e:
            status_code = 0
            error_msg = str(e)

        elapsed_ms = round((time.time() - start_time) * 1000, 2)
        is_5xx = status_code is not None and status_code >= 500
        if is_5xx:
            total_5xx += 1

        results.append({
            "raw_uri": raw_uri,
            "resolved_uri": uri,
            "url": url,
            "status_code": status_code,
            "latency_ms": elapsed_ms,
            "content_length": content_length,
            "is_5xx": is_5xx,
            "error": error_msg,
        })
        print(f"[{status_code}] {elapsed_ms:>7.2f}ms | {uri}")

    os.makedirs("reports/audit", exist_ok=True)
    json_path = "reports/audit/curl_api_sweep_results.json"
    with open(json_path, "w") as f:
        json.dump({
            "timestamp": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "total_tested": len(results),
            "total_5xx": total_5xx,
            "results": results,
        }, f, indent=2)

    csv_path = "reports/audit/curl_api_sweep_summary.csv"
    with open(csv_path, "w", newline="") as f:
        writer = csv.writer(f)
        writer.writerow(["status_code", "latency_ms", "content_length", "uri", "resolved_uri"])
        for res in results:
            writer.writerow([res["status_code"], res["latency_ms"], res["content_length"], res["raw_uri"], res["resolved_uri"]])

    print("\n" + "=" * 60)
    print(f"API SWEEP COMPLETE:")
    print(f"Total Routes Tested: {len(results)}")
    print(f"5xx Server Errors:    {total_5xx}")
    print(f"Machine JSON saved:  {json_path}")
    print(f"Summary CSV saved:   {csv_path}")
    print("=" * 60)

    if total_5xx > 0:
        print(f"FAIL: Detected {total_5xx} unexpected 5xx responses.")
        sys.exit(1)
    else:
        print("SUCCESS: 0 unexpected 5xx server errors detected across all tested API endpoints.")

if __name__ == "__main__":
    run_sweep()
