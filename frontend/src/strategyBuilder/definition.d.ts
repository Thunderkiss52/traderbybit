export interface BuilderForm {
    id: string;
    version: number;
    symbol: string;
    exchange: string;
    timeframe: string;
    fast: number;
    slow: number;
    rsi: number;
    stop: number;
    take: number;
    risk: number;
    timeout: number;
    cooldown: number;
    schemaVersion?: number;
    funding?: boolean;
    shortEnabled?: boolean;
}
export type Comparison =
    | "gt"
    | "lt"
    | "gte"
    | "lte"
    | "crosses_above"
    | "crosses_below"
    | "within"
    | "change_pct";
export interface Condition {
    and?: Condition[];
    or?: Condition[];
    not?: Condition;
    op?: Comparison;
    left?: string;
    right?: string | number | [number, number];
}
export interface Indicator {
    type: string;
    period: number;
    timeframe: string;
    slow_period?: number;
    signal_period?: number;
    deviation?: number;
}
export interface ParameterRange {
    default: number;
    min: number;
    max: number;
    step: number;
}
export interface Definition {
    schema_version: number;
    id: string;
    version: number;
    symbols: string[];
    exchanges: string[];
    timeframe: string;
    indicators: Record<string, Indicator>;
    entry: Record<string, Condition>;
    exit?: Record<string, Condition>;
    regime?: Condition;
    stop_atr: number;
    take_atr: number;
    risk_percent: number;
    timeout_bars: number;
    cooldown_bars: number;
    partial_exit?: { atr: number; fraction: number };
    funding?: { enabled: boolean; interval_hours: number };
    parameters?: Record<string, ParameterRange>;
}
export function emaDefinition(form: BuilderForm): Definition;
export function describeCondition(condition: Condition): string;
export function definitionDiff(
    left: unknown,
    right: unknown,
): Array<{ path: string; left: unknown; right: unknown }>;
