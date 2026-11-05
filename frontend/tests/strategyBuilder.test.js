import test from "node:test";
import assert from "node:assert/strict";
import { emaDefinition } from "../src/strategyBuilder/definition.js";
test("visual builder produces deterministic EMA/RSI DSL without hosted provider", () => {
    const form = {
        id: "user_ema_rsi",
        version: 1,
        symbol: "BTCUSDT",
        exchange: "bybit",
        timeframe: "15",
        fast: 20,
        slow: 50,
        rsi: 65,
        stop: 1.5,
        take: 3,
        risk: 0.5,
        timeout: 48,
        cooldown: 0,
    };
    const d = emaDefinition(form);
    assert.deepEqual(d, emaDefinition(form));
    assert.equal(d.entry.BUY.and[0].op, "crosses_above");
    assert.equal(d.stop_atr, 1.5);
    assert.equal(d.risk_percent, 0.5);
    const v = emaDefinition({ ...form, version: 2, fast: 21 });
    assert.equal(d.indicators.fast.period, 20);
    assert.equal(v.indicators.fast.period, 21);
});

import {
    describeCondition,
    definitionDiff,
} from "../src/strategyBuilder/definition.js";
test("nested visual rules have readable previews and precise version differences", () => {
    const rule = {
        and: [
            { op: "crosses_above", left: "fast", right: "slow" },
            { not: { op: "within", left: "rsi", right: [65, 100] } },
        ],
    };
    assert.match(describeCondition(rule), /fast/);
    assert.match(describeCondition(rule), /NOT/);
    assert.deepEqual(
        definitionDiff(
            { entry: rule, stop_atr: 1 },
            { entry: rule, stop_atr: 1.5 },
        ),
        [{ path: "stop_atr", left: 1, right: 1.5 }],
    );
});
