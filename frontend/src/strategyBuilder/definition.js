export function emaDefinition(form) {
    return {
        schema_version: form.schemaVersion ?? 2,
        id: form.id,
        version: Number(form.version),
        symbols: [form.symbol],
        exchanges: [form.exchange],
        timeframe: form.timeframe,
        indicators: {
            fast: {
                type: "EMA",
                period: Number(form.fast),
                timeframe: form.timeframe,
            },
            slow: {
                type: "EMA",
                period: Number(form.slow),
                timeframe: form.timeframe,
            },
            rsi: { type: "RSI", period: 14, timeframe: form.timeframe },
        },
        entry: {
            ...(form.shortEnabled
                ? {
                      SELL: {
                          and: [
                              {
                                  op: "crosses_below",
                                  left: "fast",
                                  right: "slow",
                              },
                              {
                                  op: "gt",
                                  left: "rsi",
                                  right: 100 - Number(form.rsi),
                              },
                          ],
                      },
                  }
                : {}),
            BUY: {
                and: [
                    { op: "crosses_above", left: "fast", right: "slow" },
                    { op: "lt", left: "rsi", right: Number(form.rsi) },
                ],
            },
        },
        funding: { enabled: form.funding ?? true, interval_hours: 8 },
        stop_atr: Number(form.stop),
        take_atr: Number(form.take),
        risk_percent: Number(form.risk),
        timeout_bars: Number(form.timeout),
        cooldown_bars: Number(form.cooldown),
    };
}

export function describeCondition(node) {
    if (node.and) return `(${node.and.map(describeCondition).join(" AND ")})`;
    if (node.or) return `(${node.or.map(describeCondition).join(" OR ")})`;
    if (node.not) return `NOT ${describeCondition(node.not)}`;
    const names = {
        gt: ">",
        lt: "<",
        gte: "≥",
        lte: "≤",
        crosses_above: "crosses above",
        crosses_below: "crosses below",
        within: "within",
        change_pct: "changes by at least (%)",
    };
    return `${node.left || "?"} ${names[node.op] || "?"} ${Array.isArray(node.right) ? node.right.join(" to ") : (node.right ?? "?")}`;
}
export function definitionDiff(left, right, path = "") {
    if (JSON.stringify(left) === JSON.stringify(right)) return [];
    if (
        left &&
        right &&
        typeof left === "object" &&
        typeof right === "object"
    ) {
        return [...new Set([...Object.keys(left), ...Object.keys(right)])]
            .sort()
            .flatMap((key) =>
                definitionDiff(
                    left[key],
                    right[key],
                    path ? `${path}.${key}` : key,
                ),
            );
    }
    return [{ path, left, right }];
}
