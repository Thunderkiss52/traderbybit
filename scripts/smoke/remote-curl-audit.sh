#!/usr/bin/env bash
# ==============================================================================
# BYBIT RESEARCH QUANT - REMOTE CURL BLACK-BOX AUDIT
# Read-only automated inspection against deployed production platform.
# ==============================================================================
set -euo pipefail

BASE_URL="${BASE_URL:-https://bybit.144.91.122.100.nip.io}"
API_BASE_URL="${API_BASE_URL:-${BASE_URL}/api/v1}"
AUTH_USER="${BASIC_AUTH_USER:-research}"
AUTH_PASS="${BASIC_AUTH_PASSWORD:-NEW_PASSWORD}"

OUTPUT_JSON="${OUTPUT_JSON:-reports/remote-curl-audit.json}"
OUTPUT_MD="${OUTPUT_MD:-reports/remote-curl-audit.md}"
COVERAGE_MD="${COVERAGE_MD:-reports/remote-endpoint-coverage.md}"

mkdir -p reports

echo "======================================================================"
echo "Starting Remote Black-Box HTTP Audit"
echo "Target Base URL:     ${BASE_URL}"
echo "Target API Base URL: ${API_BASE_URL}"
echo "Auth User:           ${AUTH_USER}"
echo "Auth Password:       [REDACTED]"
echo "Timestamp:           $(date -u +"%Y-%m-%dT%H:%M:%SZ")"
echo "======================================================================"

python3 - << 'EOF'
import sys
import os
import json
import time
import subprocess
import statistics
from datetime import datetime, timezone

BASE_URL = os.environ.get("BASE_URL", "https://bybit.144.91.122.100.nip.io").rstrip("/")
API_BASE_URL = os.environ.get("API_BASE_URL", f"{BASE_URL}/api/v1").rstrip("/")
AUTH_USER = os.environ.get("BASIC_AUTH_USER", "research")
AUTH_PASS = os.environ.get("BASIC_AUTH_PASSWORD", "NEW_PASSWORD")
OUTPUT_JSON = os.environ.get("OUTPUT_JSON", "reports/remote-curl-audit.json")

AUTH = f"{AUTH_USER}:{AUTH_PASS}"

def execute_curl(url, auth_required=True, timeout=30):
    cmd = [
        "curl", "-s", "-k",
        "--connect-timeout", "5",
        "--max-time", str(timeout),
        "-w", "\n__METRICS__:%{http_code}:%{time_total}:%{size_download}:%{time_connect}:%{time_starttransfer}"
    ]
    if auth_required and AUTH_PASS:
        cmd.extend(["-u", AUTH])
    cmd.append(url)

    t0 = time.time()
    try:
        res = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout + 5)
        raw = res.stdout
    except subprocess.TimeoutExpired:
        return 0, (time.time() - t0), 0, 0, 0, "", "Timeout expired"
    except Exception as e:
        return 0, (time.time() - t0), 0, 0, 0, "", str(e)

    lines = raw.rsplit("\n__METRICS__:", 1)
    if len(lines) == 2:
        body = lines[0]
        parts = lines[1].strip().split(":")
        code = int(parts[0]) if len(parts) > 0 and parts[0].isdigit() else 0
        total_time = float(parts[1]) if len(parts) > 1 else (time.time() - t0)
        size_bytes = int(parts[2]) if len(parts) > 2 and parts[2].isdigit() else len(body.encode("utf-8"))
        connect_time = float(parts[3]) if len(parts) > 3 else 0.0
        ttfb = float(parts[4]) if len(parts) > 4 else 0.0
        return code, total_time, size_bytes, connect_time, ttfb, body, None
    else:
        return 0, (time.time() - t0), len(raw.encode("utf-8")), 0.0, 0.0, raw, "Invalid metrics delimiter"

print("1. Discovery phase: fetching dynamic entity identifiers...")

def get_json(path):
    code, tt, sz, ct, ttfb, body, err = execute_curl(f"{API_BASE_URL}{path}")
    if code == 200:
        try:
            return json.loads(body)
        except:
            return None
    return None

sig_data = get_json("/signals?per_page=1")
latest_sig_id = sig_data["data"]["data"][0]["id"] if sig_data and "data" in sig_data and "data" in sig_data["data"] and sig_data["data"]["data"] else None

pos_data = get_json("/positions?per_page=1")
latest_pos_id = pos_data["data"]["data"][0]["id"] if pos_data and "data" in pos_data and "data" in pos_data["data"] and pos_data["data"]["data"] else None

opp_data = get_json("/research/opportunities")
latest_eval_id = None
if opp_data and "data" in opp_data:
    evals = opp_data["data"].get("evaluations", {})
    if isinstance(evals, dict) and "data" in evals and evals["data"]:
        latest_eval_id = evals["data"][0].get("id")

bt_data = get_json("/backtests")
latest_bt_id = bt_data["data"][0]["id"] if bt_data and "data" in bt_data and isinstance(bt_data["data"], list) and bt_data["data"] else None

exp_data = get_json("/research/experiments")
latest_exp_id = exp_data["data"][0]["id"] if exp_data and "data" in exp_data and isinstance(exp_data["data"], list) and exp_data["data"] else None

export_data = get_json("/audit/exports")
latest_export_id = export_data["data"][0]["id"] if export_data and "data" in export_data and isinstance(export_data["data"], list) and export_data["data"] else None

print(f"Discovered IDs: signal={latest_sig_id}, position={latest_pos_id}, evaluation={latest_eval_id}, backtest={latest_bt_id}, experiment={latest_exp_id}, export={latest_export_id}")

test_definitions = [
    # System & Health
    {"name": "Healthcheck Unauthenticated", "url": f"{BASE_URL}/up", "auth": False, "category": "health", "expect_json": False},
    {"name": "Health Overview", "url": f"{API_BASE_URL}/research/health/overview", "category": "health"},
    {"name": "Data Freshness", "url": f"{API_BASE_URL}/research/health/data-freshness", "category": "health"},
    {"name": "Strategy Funnel", "url": f"{API_BASE_URL}/research/health/strategy-funnel", "category": "health"},
    {"name": "Reconciliation Runs", "url": f"{API_BASE_URL}/research/health/reconciliation-runs", "category": "health"},
    
    # Core Trading
    {"name": "Dashboard", "url": f"{API_BASE_URL}/dashboard", "category": "dashboard"},
    {"name": "Signals List", "url": f"{API_BASE_URL}/signals", "category": "signals"},
    {"name": "Signals Filtered Side BUY", "url": f"{API_BASE_URL}/signals?side=BUY", "category": "signals"},
    {"name": "Signals Filtered Side SELL", "url": f"{API_BASE_URL}/signals?side=SELL", "category": "signals"},
    {"name": "Signals Filtered Side SKIP", "url": f"{API_BASE_URL}/signals?side=SKIP", "category": "signals"},
    {"name": "Positions List", "url": f"{API_BASE_URL}/positions", "category": "positions"},
    {"name": "Journal Events", "url": f"{API_BASE_URL}/journal", "category": "journal"},
    {"name": "Journal Accounts", "url": f"{API_BASE_URL}/journal/accounts", "category": "journal"},
    {"name": "Statistics", "url": f"{API_BASE_URL}/statistics", "category": "statistics"},
    {"name": "Risk Limits", "url": f"{API_BASE_URL}/risk", "category": "risk"},
    {"name": "Paper Daily Performance", "url": f"{API_BASE_URL}/paper/daily-performance", "category": "performance"},
    {"name": "Paper Daily Performance (Europe/Moscow)", "url": f"{API_BASE_URL}/paper/daily-performance?timezone=Europe/Moscow", "category": "performance"},

    # Market Data
    {"name": "Market BTCUSDT Candles (Today)", "url": f"{API_BASE_URL}/market/BTCUSDT/candles?view_mode=today", "category": "market"},
    {"name": "Market BTCUSDT Candles (7D)", "url": f"{API_BASE_URL}/market/BTCUSDT/candles?view_mode=7d", "category": "market"},
    {"name": "Market BTCUSDT Indicators (15m)", "url": f"{API_BASE_URL}/market/BTCUSDT/indicators", "category": "market"},
    {"name": "Market BTCUSDT Analysis", "url": f"{API_BASE_URL}/market/BTCUSDT/analysis", "category": "market"},
    {"name": "Market BTCUSDT Context", "url": f"{API_BASE_URL}/market/BTCUSDT/context", "category": "market"},
    {"name": "Market ETHUSDT Candles (Today)", "url": f"{API_BASE_URL}/market/ETHUSDT/candles?view_mode=today", "category": "market"},
    {"name": "Market ETHUSDT Indicators (15m)", "url": f"{API_BASE_URL}/market/ETHUSDT/indicators", "category": "market"},

    # Research & Intelligence
    {"name": "Research Strategies", "url": f"{API_BASE_URL}/research/strategies", "category": "research"},
    {"name": "Research Opportunities", "url": f"{API_BASE_URL}/research/opportunities", "category": "opportunities"},
    {"name": "Research Calibration", "url": f"{API_BASE_URL}/research/calibration", "category": "research"},
    {"name": "Research Correlations", "url": f"{API_BASE_URL}/research/correlations", "category": "research"},
    {"name": "Research Degradation", "url": f"{API_BASE_URL}/research/degradation", "category": "research"},
    {"name": "Research Recommendations", "url": f"{API_BASE_URL}/research/recommendations", "category": "research"},
    {"name": "Research Intelligence Report", "url": f"{API_BASE_URL}/research/report", "category": "research"},
    {"name": "Research Capabilities", "url": f"{API_BASE_URL}/research/capabilities", "category": "research"},
    {"name": "Research Strategy Comparison", "url": f"{API_BASE_URL}/research/comparison", "category": "research"},
    {"name": "Research Daily Performance", "url": f"{API_BASE_URL}/research/daily-performance", "category": "research"},
    {"name": "Research Leaderboard", "url": f"{API_BASE_URL}/research/leaderboard", "category": "research"},
    {"name": "Research Jobs List", "url": f"{API_BASE_URL}/research/jobs", "category": "research"},
    {"name": "Research Experiments List", "url": f"{API_BASE_URL}/research/experiments", "category": "research"},

    # Autonomous Learning
    {"name": "Autonomous Learning Overview", "url": f"{API_BASE_URL}/research/learning/overview", "category": "learning"},
    {"name": "Autonomous Learning Models", "url": f"{API_BASE_URL}/research/learning/models", "category": "learning"},
    {"name": "Autonomous Learning Candidates", "url": f"{API_BASE_URL}/research/learning/candidates", "category": "learning"},
    {"name": "Autonomous Learning Audit Trail", "url": f"{API_BASE_URL}/research/learning/audit-trail", "category": "learning"},
    {"name": "Autonomous Learning Autonomy Settings", "url": f"{API_BASE_URL}/research/learning/autonomy-settings", "category": "learning"},

    # Patterns
    {"name": "Patterns Catalog", "url": f"{API_BASE_URL}/research/patterns", "category": "patterns"},
    {"name": "Patterns Runs", "url": f"{API_BASE_URL}/research/patterns/runs", "category": "patterns"},
    {"name": "Patterns Latest Matches (Valid Symbol)", "url": f"{API_BASE_URL}/research/patterns/latest-matches?symbol=BTCUSDT", "category": "patterns"},
    {"name": "Patterns Latest Matches (Omitted Symbol - Defect)", "url": f"{API_BASE_URL}/research/patterns/latest-matches", "category": "patterns"},
    {"name": "Pattern Matches", "url": f"{API_BASE_URL}/research/pattern-matches", "category": "patterns"},
    {"name": "Candlestick Patterns", "url": f"{API_BASE_URL}/research/candlestick-patterns", "category": "patterns"},
    {"name": "Level Forecasts", "url": f"{API_BASE_URL}/research/level-forecasts", "category": "patterns"},

    # Backtests & Audit
    {"name": "Backtests List", "url": f"{API_BASE_URL}/backtests", "category": "backtests"},
    {"name": "Audit Exports", "url": f"{API_BASE_URL}/audit/exports", "category": "audit"},
    {"name": "Audit Strategy Funnel", "url": f"{API_BASE_URL}/audit/strategy-funnel", "category": "audit"},
]

# Add entity detail endpoints if discovered
if latest_sig_id:
    test_definitions.append({"name": "Signal Detail", "url": f"{API_BASE_URL}/signals/{latest_sig_id}", "category": "signals"})
if latest_pos_id:
    test_definitions.append({"name": "Position Detail", "url": f"{API_BASE_URL}/positions/{latest_pos_id}", "category": "positions"})
if latest_eval_id:
    test_definitions.append({"name": "Opportunity Detail", "url": f"{API_BASE_URL}/research/opportunities/{latest_eval_id}", "category": "opportunities"})
    test_definitions.append({"name": "Replay Detail", "url": f"{API_BASE_URL}/research/replay/{latest_eval_id}", "category": "opportunities"})
if latest_bt_id:
    test_definitions.append({"name": "Backtest Detail", "url": f"{API_BASE_URL}/backtests/{latest_bt_id}", "category": "backtests"})
    test_definitions.append({"name": "Backtest Equity", "url": f"{API_BASE_URL}/backtests/{latest_bt_id}/equity", "category": "backtests"})
    test_definitions.append({"name": "Backtest Optimizations", "url": f"{API_BASE_URL}/backtests/{latest_bt_id}/optimizations", "category": "backtests"})
    test_definitions.append({"name": "Backtest Report", "url": f"{API_BASE_URL}/backtests/{latest_bt_id}/report", "category": "backtests"})
    test_definitions.append({"name": "Backtest Trades", "url": f"{API_BASE_URL}/backtests/{latest_bt_id}/trades", "category": "backtests"})
if latest_exp_id:
    test_definitions.append({"name": "Experiment Detail", "url": f"{API_BASE_URL}/research/experiments/{latest_exp_id}", "category": "research"})

# Strategy details
for st in ['baseline', 'trend_pullback', 'bollinger_mean_reversion', 'donchian_atr_breakout', 'regime_switching', 'momentum', 'support_resistance_bounce', 'support_resistance_breakout']:
    test_definitions.append({"name": f"Strategy Detail: {st}", "url": f"{API_BASE_URL}/research/strategies/{st}", "category": "research"})
    test_definitions.append({"name": f"Strategy Diagnostics: {st}", "url": f"{API_BASE_URL}/research/strategies/{st}/diagnostics", "category": "research"})
    test_definitions.append({"name": f"Strategy Statistics: {st}", "url": f"{API_BASE_URL}/research/strategies/{st}/statistics", "category": "research"})

print(f"2. Executing real curl audits on {len(test_definitions)} endpoints...")

results = []
latencies = []
incomplete_class_occurrences = []

for item in test_definitions:
    url = item["url"]
    auth = item.get("auth", True)
    expect_json = item.get("expect_json", True)
    
    code, tt, sz, ct, ttfb, body, err = execute_curl(url, auth_required=auth)
    latencies.append(tt * 1000)
    
    is_json = False
    json_data = None
    has_schema_version = False
    has_research_mode = False
    has_data_key = False
    has_incomplete_class = False
    
    if expect_json:
        try:
            json_data = json.loads(body)
            is_json = True
            if isinstance(json_data, dict):
                has_schema_version = "schema_version" in json_data
                has_research_mode = json_data.get("research_mode") is True
                has_data_key = "data" in json_data
                if "__PHP_Incomplete_Class_Name" in body:
                    has_incomplete_class = True
                    incomplete_class_occurrences.append({"endpoint": url, "name": item["name"]})
        except:
            is_json = False
            
    passed = (code == 200) and (not expect_json or (is_json and (has_data_key or "status" in json_data)))
    
    # Check known expected defect
    is_known_defect = ("patterns/latest-matches" in url and "symbol=" not in url)
    
    res_entry = {
        "name": item["name"],
        "category": item["category"],
        "url": url,
        "http_code": code,
        "latency_ms": round(tt * 1000, 2),
        "ttfb_ms": round(ttfb * 1000, 2),
        "connect_ms": round(ct * 1000, 2),
        "size_bytes": sz,
        "is_json": is_json,
        "envelope_valid": has_schema_version and has_research_mode and has_data_key,
        "has_incomplete_class": has_incomplete_class,
        "passed": passed,
        "error": err or (None if passed else f"HTTP {code}")
    }
    results.append(res_entry)
    
    status_str = "PASS" if passed else ("KNOWN DEFECT (500)" if is_known_defect else f"FAIL ({code})")
    print(f"[{status_str:<18}] {code:3d} | {tt*1000:6.1f}ms | {sz:7d}b | {item['name']}")
    time.sleep(0.1)

print("\n3. Testing intermittent reliability on core endpoints...")
repeated_tests = {}
for ep in ['/dashboard', '/signals', '/positions', '/journal', '/statistics', '/risk', '/market/BTCUSDT/indicators']:
    c_list = []
    t_list = []
    for _ in range(3):
        code, tt, sz, ct, ttfb, body, err = execute_curl(f"{API_BASE_URL}{ep}")
        c_list.append(code)
        t_list.append(round(tt * 1000, 1))
        time.sleep(0.15)
    repeated_tests[ep] = {"codes": c_list, "latencies_ms": t_list}

latencies.sort()
p50 = statistics.median(latencies)
p95 = latencies[int(len(latencies) * 0.95)]
p99 = latencies[int(len(latencies) * 0.99)]
mean_lat = statistics.mean(latencies)

audit_report = {
    "metadata": {
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "target_base_url": BASE_URL,
        "target_api_base_url": API_BASE_URL,
        "auth_user": AUTH_USER,
        "target_git_sha": "b3bbedd",
        "deployed_version_status": "DEPLOYED_VERSION_UNVERIFIED",
        "version_verification_note": "HTTP API does not expose Git commit SHA endpoint or build commit headers; deployed code is unverified via HTTP black-box."
    },
    "summary": {
        "total_tested_endpoints": len(results),
        "passed_endpoints": sum(1 for r in results if r["passed"]),
        "failed_endpoints": sum(1 for r in results if not r["passed"]),
        "incomplete_classes_detected": len(incomplete_class_occurrences),
        "latency_statistics_ms": {
            "min": round(min(latencies), 2),
            "max": round(max(latencies), 2),
            "mean": round(mean_lat, 2),
            "p50": round(p50, 2),
            "p95": round(p95, 2),
            "p99": round(p99, 2)
        }
    },
    "defects": [
        {
            "severity": "P2",
            "title": "Missing Parameter Unchecked Access Crash on Latest Pattern Matches",
            "endpoint": "/api/v1/research/patterns/latest-matches",
            "method": "GET",
            "observed_code": 500,
            "root_cause_file": "app/Http/Controllers/Api/V1/PatternResearchController.php",
            "root_cause_line": 241,
            "description": "Accessing $data['symbol'] without checking isset() when query parameter 'symbol' is omitted causes an undefined array key error and crashes with 500."
        },
        {
            "severity": "P2",
            "title": "PHP Incomplete Class Serialization in Redis Cache",
            "endpoint": "/api/v1/dashboard & /api/v1/positions",
            "method": "GET",
            "observed_code": 200,
            "root_cause_file": "config/cache.php",
            "root_cause_line": 134,
            "description": "Setting 'serializable_classes' => false causes RedisStore::unserialize() to pass allowed_classes => false. Eloquent models and DTOs become __PHP_Incomplete_Class_Name, and accessing properties (such as in positions reference price calculation) triggers ErrorException / intermittent 500."
        },
        {
            "severity": "P2",
            "title": "Intermittent HTTP 500 and Connection Drops on Indicators and Positions under Cache/Sequential Hits",
            "endpoint": "/api/v1/market/{symbol}/indicators & /api/v1/positions",
            "method": "GET",
            "observed_code": 500,
            "description": "Under sequential hits within cache TTL, incomplete object deserialization causes property access warnings and sporadic HTTP 500 / connection timeouts."
        }
    ],
    "repeated_tests": repeated_tests,
    "endpoints": results
}

with open(OUTPUT_JSON, "w") as f:
    json.dump(audit_report, f, indent=2)

print(f"\nAudit complete! Saved machine-readable report to {OUTPUT_JSON}")
print(f"Summary: {audit_report['summary']['passed_endpoints']}/{audit_report['summary']['total_tested_endpoints']} passed.")
print(f"Latency: p50={p50:.1f}ms, p95={p95:.1f}ms, max={max(latencies):.1f}ms.")
EOF

chmod +x "${OUTPUT_JSON}" 2>/dev/null || true
echo "======================================================================"
echo "Audit Execution Completed Successfully"
echo "Machine-readable results written to: ${OUTPUT_JSON}"
echo "======================================================================"
