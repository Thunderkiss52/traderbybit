const { chromium } = await import(
    process.env.PLAYWRIGHT_MODULE || "playwright-core"
);
import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
const registeredExchanges = JSON.parse(execFileSync(process.env.VECTOR_BROWSER_PHP || "php", [
    "scripts/browser/seed_strategy_builder.php", "registered-exchanges",
], { encoding: "utf8" }));
const browser = await chromium.launch({
    executablePath: "/usr/bin/google-chrome",
    headless: true,
    args: ["--no-sandbox"],
});
let page;
try {
    page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    await page.route("**/api/v1/**", async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        url.host = new URL(process.env.VECTOR_BROWSER_API_URL || "http://127.0.0.1:8000").host;
        const response = await route.fetch({
            url: url.toString(),
            timeout: 180000,
        });
        await route.fulfill({ response });
    });
    const button = (name) => page.getByRole("button", { name, exact: true });
    const response = (suffix, method = "POST") =>
        page.waitForResponse(
            (r) => r.url().endsWith(suffix) && r.request().method() === method,
            { timeout: 180000 },
        );
    const accept = async (pending) => {
        const r = await pending;
        assert.ok(r.ok(), `${r.status()} ${await r.text()}`);
        return (await r.json()).data;
    };
    const decode = (value) =>
        typeof value === "string" ? JSON.parse(value) : value;
    await page.goto(
        (process.env.VECTOR_BROWSER_BASE_URL || "http://127.0.0.1:18125") +
            "/strategies",
    );
    await page
        .getByLabel("Research owner token", { exact: true })
        .fill(process.env.VECTOR_BROWSER_TOKEN || "builder-browser-test");
    let pending = response("/strategies?page=1", "GET");
    await button("Load strategies").click();
    await accept(pending);
    await page.getByRole("tab", { name: "Language Authoring", exact: true }).click();
    await button("Interpret description").click();
    await page.getByText("RSI period 14", { exact: false }).first().waitFor();
    await page.getByRole("tab", { name: "Visual Strategy Builder", exact: true }).click();
    await page
        .getByLabel("Strategy ID (user_ prefix)", { exact: true })
        .fill("user_browser_" + Date.now());
    await page.getByLabel("Position timeout bars", { exact: true }).fill("2");
    await button("Add stop range").click();
    pending = response("/strategies/drafts");
    await button("Save durable draft").click();
    const draft = await accept(pending);
    assert.equal(draft.revision, 1);
    await page.reload();
    await page
        .getByLabel("Research owner token", { exact: true })
        .fill("builder-browser-test");
    pending = response("/strategies?page=1", "GET");
    await button("Load strategies").click();
    await accept(pending);
    await page.getByRole("tab", { name: "Drafts & Versions", exact: true }).click();
    await page
        .getByRole("listitem")
        .filter({ hasText: decode(draft.definition).id })
        .getByRole("button", { name: "Resume draft" })
        .click();
    await page.getByRole("tab", { name: "Visual Strategy Builder", exact: true }).click();
    assert.equal(
        await page
            .getByLabel("Position timeout bars", { exact: true })
            .inputValue(),
        "2",
    );
    await page
        .getByLabel("I confirm these interpreted rules and assumptions", {
            exact: true,
        })
        .check();
    pending = response("/strategies/validate");
    await button("Validate").click();
    await accept(pending);
    pending = response(`/drafts/${draft.id}/publish`);
    await button("Publish confirmed draft").click();
    const version = await accept(pending);
    await page
        .getByText("Confirmed draft published as an immutable version.", {
            exact: true,
        })
        .waitFor();
    await page.getByRole("tab", { name: "Runs & Research", exact: true }).click();
    await page
        .getByLabel("From UTC (ISO 8601)", { exact: true })
        .fill("2026-01-15T00:00:00Z");
    await page
        .getByLabel("To UTC (ISO 8601), maximum 7 days", { exact: true })
        .fill("2026-01-16T00:00:00Z");
    pending = response("/backtests");
    await button("Run pinned Go backtest").click();
    const run = await accept(pending);
    const metadata = decode(run.metadata);
    const result = decode(run.result);
    assert.ok(result.report.total_trades > 0);
    assert.ok(metadata.dataset_pin.checksum);
    assert.equal(metadata.funding_complete, true);
    pending = response("/backtests");
    await button("Run pinned Go backtest").click();
    assert.equal((await accept(pending)).id, run.id);
    pending = response("/evaluate-paper");
    await button("Verify Go / PAPER parity for this run").click();
    const eligible = await accept(pending);
    assert.equal(eligible.paper_eligible, true);
    await page
        .getByLabel("Owner audit reference (8–160 characters)", { exact: true })
        .fill("BROWSER-OWNER-TEST");
    await page
        .getByLabel(
            "I approve this exact immutable version as PRIMARY and provision unfunded BLOCKED accounts",
            { exact: true },
        )
        .check();
    pending = response("/approve-paper");
    await button("Submit for PAPER approval").click();
    const approval = await accept(pending);
    assert.equal(approval.owner_authorized, true);
    assert.deepEqual(approval.accounts.map((account) => account.exchange_id).sort(), registeredExchanges.sort());
    for (const account of approval.accounts) {
        assert.equal(account.status, "BLOCKED");
        assert.equal(Number(account.starting_balance), 0);
        assert.equal(Number(account.current_equity), 0);
    }
    for (const [key, value] of Object.entries({
        max_candidates: 3,
        max_windows: 1,
        train_hours: 6,
        validation_hours: 3,
        test_hours: 3,
        step_hours: 6,
    })) {
        await page.getByLabel(key, { exact: true }).fill(String(value));
    }
    pending = response("/optimizations");
    await button("Request optimization").click();
    const experiment = await accept(pending);
    assert.equal(experiment.status, "QUEUED");
    execFileSync(process.env.VECTOR_BROWSER_PHP || "php", [
        "artisan", "queue:work", "database", "--queue=research",
        "--stop-when-empty", "--tries=2",
    ], { timeout: 180000, stdio: "pipe" });
    let done;
    for (let attempt = 0; attempt < 30; attempt++) {
        pending = response("/optimizations?page=1", "GET");
        await button("Refresh experiments").click();
        const experiments = await accept(pending);
        done = experiments.data.find((e) => e.id === experiment.id);
        if (["COMPLETED", "REJECTED", "FAILED"].includes(done.status)) break;
        await page.waitForTimeout(1000);
    }
    assert.ok(
        ["COMPLETED", "REJECTED"].includes(done.status),
        JSON.stringify(done),
    );
    pending = response(`/optimizations/${experiment.id}`, "GET");
    await button("Inspect experiment").first().click();
    const detailResponse = await pending;
    const detail = await detailResponse.json();
    assert.equal(detail.candidates.length, 3);
    assert.ok(decode(detail.data.results).original_unchanged);
    assert.equal(Number(version.version), 1);
    assert.deepEqual(errors, []);
    await page.screenshot({
        path:
            process.env.VECTOR_BROWSER_SCREENSHOT ||
            "/tmp/vector-builder-strategies-v2.png",
        fullPage: true,
    });
    console.log(
        "PASS: real Chromium visual edits, durable draft reload, explicit publication, funded pinned Go replay, deterministic repeat, parity certification, owner approval with BLOCKED zero-capital account, and queued walk-forward optimization; zero page errors",
    );
} finally {
    if (page) await page.unrouteAll({ behavior: "ignoreErrors" });
    await browser.close();
}
