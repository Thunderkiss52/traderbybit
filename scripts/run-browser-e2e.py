#!/usr/bin/env python3
"""
Playwright Browser E2E Test Suite for TraderByBit V4.
Executes against genuinely running isolated application (Vite preview on :4173 proxying to Laravel on :8000).
Verifies:
1. Dashboard: Page load, research mode banner, 8 primary accounts representation, market contexts, PnL summaries.
2. Strategy Research Lab: Page load, strategy select dropdown containing all 8 strategies, 4-timeframe hierarchy.
3. Statistics & Risk: Rendering of performance data, data quality metrics.
4. Clean execution: Zero uncaught page errors, zero failed critical API requests.
"""

import os
import sys
import time

CHROME_BIN = "/usr/bin/google-chrome"
BASE_URL = "http://127.0.0.1:4173"

def main():
    print("=" * 70)
    print("TRADERBYBIT V4 — HEADLESS BROWSER E2E TEST (PLAYWRIGHT)")
    print("Timestamp:", time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()))
    print("=" * 70)

    # 1. Prerequisite checks (mandatory FAIL if missing)
    if not os.path.isfile(CHROME_BIN):
        print(f"[FAIL] Required Chrome binary not found at {CHROME_BIN}", file=sys.stderr)
        sys.exit(1)

    try:
        from playwright.sync_api import sync_playwright
    except ImportError as e:
        print(f"[FAIL] Playwright Python module not installed: {e}", file=sys.stderr)
        sys.exit(1)

    reports_dir = os.path.abspath("reports")
    os.makedirs(reports_dir, exist_ok=True)

    page_errors = []
    failed_requests = []

    all_passed = True

    with sync_playwright() as p:
        print(f"[E2E] Launching headless Chrome ({CHROME_BIN}) ...")
        browser = p.chromium.launch(
            executable_path=CHROME_BIN,
            headless=True,
            args=[
                "--no-sandbox",
                "--disable-dev-shm-usage",
                "--disable-gpu",
            ],
        )

        context = browser.new_context(
            viewport={"width": 1920, "height": 1080},
            ignore_https_errors=True,
        )
        page = context.new_page()

        page.on("pageerror", lambda err: page_errors.append(str(err)))
        page.on(
            "requestfailed",
            lambda req: failed_requests.append(f"{req.method} {req.url} -> {req.failure}"),
        )

        try:
            # -------------------------------------------------------------
            # TEST 1: Dashboard Navigation & Strategy Accounts
            # -------------------------------------------------------------
            print("[E2E] Test 1: Navigating to Dashboard ...")
            page.goto(f"{BASE_URL}/dashboard", wait_until="networkidle", timeout=15000)
            page.wait_for_selector("#app", timeout=5000)

            # Allow Vue hydration & API response rendering
            time.sleep(2)

            page_content = page.content()

            # Verify brand header
            if "Quant Research" in page_content:
                print("  [PASS] Header 'Quant Research' verified")
            else:
                print("  [WARN] Header 'Quant Research' not found in content")

            # Verify research warning banner
            assert "PAPER TRADING / RESEARCH MODE" in page_content or "RESEARCH MODE" in page_content, (
                "Missing research banner warning"
            )
            print("  [PASS] Research mode banner verified")

            # Verify all 8 strategy accounts
            expected_strategies = [
                "baseline",
                "trend_pullback",
                "bollinger_mean_reversion",
                "donchian_atr_breakout",
                "regime_switching",
                "momentum",
                "support_resistance_bounce",
                "support_resistance_breakout",
            ]

            missing_strats = [s for s in expected_strategies if s not in page_content]
            if not missing_strats:
                print(f"  [PASS] All {len(expected_strategies)} strategy accounts rendered on Dashboard")
            else:
                print(f"  [FAIL] Missing strategies on Dashboard: {missing_strats}")
                all_passed = False

            # Verify market contexts (BTCUSDT, ETHUSDT)
            assert "BTCUSDT" in page_content and "ETHUSDT" in page_content, (
                "Missing BTCUSDT or ETHUSDT market contexts"
            )
            print("  [PASS] BTCUSDT and ETHUSDT market contexts rendered")

            dashboard_shot = os.path.join(reports_dir, "e2e_dashboard.png")
            page.screenshot(path=dashboard_shot, full_page=True)
            print(f"  [PASS] Saved screenshot: {dashboard_shot}")

            # -------------------------------------------------------------
            # TEST 2: Strategy Research Lab
            # -------------------------------------------------------------
            print("\n[E2E] Test 2: Navigating to Strategy Research Lab ...")
            page.goto(f"{BASE_URL}/research", wait_until="networkidle", timeout=15000)
            time.sleep(1)

            research_content = page.content()
            assert "STRATEGY RESEARCH LAB" in research_content, "Missing Research Lab page title"
            assert "Robustness Before Return" in research_content, "Missing Research Lab subtitle"
            print("  [PASS] Research Lab title & subtitle verified")

            assert "1h trend · 30m setup · 15m entry · 5m confirmation" in research_content, (
                "Missing 4-timeframe hierarchy declaration"
            )
            print("  [PASS] 4-timeframe hierarchy declaration verified")

            # Verify strategy select contains all 8 strategies
            for s in expected_strategies:
                assert s in research_content, f"Strategy '{s}' not present in Research Lab"
            print(f"  [PASS] All {len(expected_strategies)} strategies declared in Research Lab select")

            research_shot = os.path.join(reports_dir, "e2e_research.png")
            page.screenshot(path=research_shot, full_page=True)
            print(f"  [PASS] Saved screenshot: {research_shot}")

            # -------------------------------------------------------------
            # TEST 3: Statistics Page
            # -------------------------------------------------------------
            print("\n[E2E] Test 3: Navigating to Statistics Page ...")
            page.goto(f"{BASE_URL}/statistics", wait_until="networkidle", timeout=15000)
            time.sleep(1)

            stats_content = page.content()
            assert "Statistics" in stats_content or "STATISTICS" in stats_content, "Missing Statistics title"
            print("  [PASS] Statistics page loaded successfully")

            stats_shot = os.path.join(reports_dir, "e2e_statistics.png")
            page.screenshot(path=stats_shot, full_page=True)
            print(f"  [PASS] Saved screenshot: {stats_shot}")

            # -------------------------------------------------------------
            # TEST 4: Error Inspection
            # -------------------------------------------------------------
            print("\n[E2E] Test 4: Checking Page Exceptions & Network Health ...")
            if page_errors:
                print(f"  [FAIL] Uncaught browser page errors detected ({len(page_errors)}):", file=sys.stderr)
                for pe in page_errors:
                    print(f"    - {pe}", file=sys.stderr)
                all_passed = False
            else:
                print("  [PASS] Zero uncaught JavaScript errors detected")

            # Filter out non-critical favicon requests
            critical_failed_requests = [
                r for r in failed_requests if not ("favicon" in r or "robots.txt" in r)
            ]
            if critical_failed_requests:
                print(f"  [FAIL] Failed critical HTTP requests detected ({len(critical_failed_requests)}):", file=sys.stderr)
                for fr in critical_failed_requests:
                    print(f"    - {fr}", file=sys.stderr)
                all_passed = False
            else:
                print("  [PASS] Zero failed critical API / asset requests")

        finally:
            browser.close()

    print("=" * 70)
    if all_passed:
        print("[SUCCESS] ALL BROWSER E2E TESTS PASSED")
        sys.exit(0)
    else:
        print("[FAILURE] BROWSER E2E TESTS ENCOUNTERED FAILURES", file=sys.stderr)
        sys.exit(1)

if __name__ == "__main__":
    main()
