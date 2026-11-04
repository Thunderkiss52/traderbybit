import test from "node:test";
import assert from "node:assert/strict";
import { emaDefinition } from "../src/strategyBuilder/definition.js";
test("visual builder produces deterministic EMA/RSI DSL without hosted provider", () => {
 const form={id:"user_ema_rsi",version:1,symbol:"BTCUSDT",exchange:"bybit",timeframe:"15",fast:20,slow:50,rsi:65,stop:1.5,take:3,risk:0.5,timeout:48,cooldown:0};
 const d=emaDefinition(form);assert.deepEqual(d,emaDefinition(form));assert.equal(d.entry.BUY.and[0].op,"crosses_above");assert.equal(d.stop_atr,1.5);assert.equal(d.risk_percent,0.5);
 const v=emaDefinition({...form,version:2,fast:21});assert.equal(d.indicators.fast.period,20);assert.equal(v.indicators.fast.period,21);
});
