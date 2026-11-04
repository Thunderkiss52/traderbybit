export function emaDefinition(form) {
  return {
    schema_version: 1, id: form.id, version: Number(form.version), symbols: [form.symbol], exchanges: [form.exchange], timeframe: form.timeframe,
    indicators: { fast: { type: "EMA", period: Number(form.fast), timeframe: form.timeframe }, slow: { type: "EMA", period: Number(form.slow), timeframe: form.timeframe }, rsi: { type: "RSI", period: 14, timeframe: form.timeframe } },
    entry: { BUY: { and: [{ op: "crosses_above", left: "fast", right: "slow" }, { op: "lt", left: "rsi", right: Number(form.rsi) }] } },
    stop_atr: Number(form.stop), take_atr: Number(form.take), risk_percent: Number(form.risk), timeout_bars: Number(form.timeout), cooldown_bars: Number(form.cooldown),
  };
}
