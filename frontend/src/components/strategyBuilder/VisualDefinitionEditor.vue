<script setup lang="ts">
import { computed } from "vue";
import ConditionEditor from "./ConditionEditor.vue";
import {
    describeCondition,
    type Definition,
    type Indicator,
    type Condition,
    type ParameterRange,
} from "../../strategyBuilder/definition.js";
const props = defineProps<{ modelValue: Definition }>();
const emit = defineEmits<{ "update:modelValue": [value: Definition] }>();
const names = computed(() => Object.keys(props.modelValue.indicators));
const types = [
    "SMA",
    "EMA",
    "RSI",
    "MACD",
    "MACD_SIGNAL",
    "MACD_HISTOGRAM",
    "BB_UPPER",
    "BB_LOWER",
    "ATR",
    "ADX",
    "DONCHIAN_HIGH",
    "DONCHIAN_LOW",
    "VOLUME_SMA",
    "PRICE",
    "OPEN",
    "HIGH",
    "LOW",
    "VOLUME",
    "TURNOVER",
    "BODY",
    "RANGE",
];
const frames = ["5", "15", "30", "60"];
const sides = ["BUY", "SELL"];
const intervals = [1, 4, 8];
function update(fn: (d: Definition) => void) {
    const d = structuredClone(props.modelValue);
    fn(d);
    emit("update:modelValue", d);
}
function field(key: keyof Definition, value: unknown) {
    update((d) => Object.assign(d, { [key]: value }));
}
function indicator(name: string, key: keyof Indicator, value: unknown) {
    update((d) => Object.assign(d.indicators[name], { [key]: value }));
}
function addIndicator() {
    update((d) => {
        let i = 1;
        while (d.indicators[`indicator${i}`]) i++;
        d.indicators[`indicator${i}`] = {
            type: "EMA",
            period: 20,
            timeframe: d.timeframe,
        };
    });
}
function leaf(): Condition {
    return { op: "gt", left: names.value[0] || "fast", right: 0 };
}
function toggle(group: "entry" | "exit", side: string, enabled: boolean) {
    update((d) => {
        const rules = d[group] || {};
        if (enabled) rules[side] = leaf();
        else delete rules[side];
        if (group === "exit" && !Object.keys(rules).length) delete d.exit;
        else d[group] = rules;
    });
}
function condition(group: "entry" | "exit", side: string, node: Condition) {
    update((d) => {
        d[group] = { ...d[group], [side]: node };
    });
}
function list(key: "symbols" | "exchanges", value: string | number | null) {
    field(
        key,
        String(value || "")
            .split(",")
            .map((s) => s.trim())
            .filter(Boolean),
    );
}
function range(path: string, key: keyof ParameterRange, value: unknown) {
    update((d) => Object.assign(d.parameters![path], { [key]: Number(value) }));
}
function addRange() {
    update((d) => {
        d.parameters = {
            ...d.parameters,
            stop_atr: { default: d.stop_atr, min: 1, max: 2, step: 0.5 },
        };
    });
}
</script>
<template>
    <div>
        <div class="row q-col-gutter-sm">
            <q-input
                class="col-6"
                :model-value="modelValue.id"
                label="Strategy ID (user_ prefix)"
                @update:model-value="field('id', $event)"
            />
            <q-input
                class="col-3"
                :model-value="modelValue.version"
                label="Immutable version"
                type="number"
                @update:model-value="field('version', Number($event))"
            />
            <q-select
                class="col-3"
                :model-value="modelValue.timeframe"
                :options="frames"
                label="Primary timeframe"
                @update:model-value="field('timeframe', $event)"
            />
            <q-input
                class="col-6"
                :model-value="modelValue.symbols.join(',')"
                label="Symbols, comma separated"
                @update:model-value="list('symbols', $event)"
            />
            <q-input
                class="col-6"
                :model-value="modelValue.exchanges.join(',')"
                label="Exchanges, comma separated"
                @update:model-value="list('exchanges', $event)"
            />
        </div>
        <h3 class="text-subtitle1">
            Indicators and higher timeframe confirmations
        </h3>
        <div
            v-for="(item, name) in modelValue.indicators"
            :key="name"
            class="row q-col-gutter-sm items-center"
        >
            <span class="col-2">{{ name }}</span>
            <q-select
                class="col-3"
                :model-value="item.type"
                :options="types"
                label="Indicator"
                @update:model-value="indicator(name, 'type', $event)"
            />
            <q-input
                class="col-2"
                :model-value="item.period"
                label="Period"
                type="number"
                @update:model-value="indicator(name, 'period', Number($event))"
            />
            <q-select
                class="col-2"
                :model-value="item.timeframe"
                :options="frames"
                label="Timeframe"
                @update:model-value="indicator(name, 'timeframe', $event)"
            />
            <q-btn
                class="col-3"
                label="Remove indicator"
                flat
                @click="
                    update((d) => {
                        delete d.indicators[name];
                    })
                "
            />
            <template v-if="item.type.startsWith('MACD')">
                <q-input
                    class="col-3"
                    :model-value="item.slow_period || 26"
                    label="Slow period"
                    type="number"
                    @update:model-value="
                        indicator(name, 'slow_period', Number($event))
                    "
                />
                <q-input
                    class="col-3"
                    :model-value="item.signal_period || 9"
                    label="Signal period"
                    type="number"
                    @update:model-value="
                        indicator(name, 'signal_period', Number($event))
                    "
                />
            </template>
            <q-input
                v-if="item.type.startsWith('BB_')"
                class="col-3"
                :model-value="item.deviation || 2"
                label="Standard deviations"
                type="number"
                @update:model-value="
                    indicator(name, 'deviation', Number($event))
                "
            />
        </div>
        <q-btn label="Add indicator" flat @click="addIndicator" />
        <template v-for="side in sides" :key="side">
            <q-checkbox
                :model-value="!!modelValue.entry[side]"
                :label="side + ' entry'"
                @update:model-value="toggle('entry', side, !!$event)"
            />
            <template v-if="modelValue.entry[side]"
                ><p>{{ describeCondition(modelValue.entry[side]) }}</p>
                <ConditionEditor
                    :model-value="modelValue.entry[side]"
                    :indicators="names"
                    @update:model-value="condition('entry', side, $event)"
            /></template>
            <q-checkbox
                :model-value="!!modelValue.exit?.[side]"
                :label="side + ' rule exit (research)'"
                @update:model-value="toggle('exit', side, !!$event)"
            />
            <ConditionEditor
                v-if="modelValue.exit?.[side]"
                :model-value="modelValue.exit[side]"
                :indicators="names"
                @update:model-value="condition('exit', side, $event)"
            />
        </template>
        <q-checkbox
            :model-value="!!modelValue.regime"
            label="Market regime filter"
            @update:model-value="
                update((d) => {
                    if ($event) d.regime = leaf();
                    else delete d.regime;
                })
            "
        />
        <ConditionEditor
            v-if="modelValue.regime"
            :model-value="modelValue.regime"
            :indicators="names"
            @update:model-value="field('regime', $event)"
        />
        <div class="row q-col-gutter-sm">
            <q-input
                class="col-4"
                :model-value="modelValue.stop_atr"
                label="Stop ATR(14)"
                type="number"
                @update:model-value="field('stop_atr', Number($event))"
            />
            <q-input
                class="col-4"
                :model-value="modelValue.take_atr"
                label="Take profit ATR(14)"
                type="number"
                @update:model-value="field('take_atr', Number($event))"
            />
            <q-input
                class="col-4"
                :model-value="modelValue.risk_percent"
                label="Risk per trade % (maximum 0.5)"
                type="number"
                @update:model-value="field('risk_percent', Number($event))"
            />
            <q-input
                class="col-6"
                :model-value="modelValue.timeout_bars"
                label="Position timeout bars"
                type="number"
                @update:model-value="field('timeout_bars', Number($event))"
            />
            <q-input
                class="col-6"
                :model-value="modelValue.cooldown_bars"
                label="Signal cooldown bars (research)"
                type="number"
                @update:model-value="field('cooldown_bars', Number($event))"
            />
        </div>
        <q-checkbox
            :model-value="!!modelValue.partial_exit"
            label="Partial take profit (research)"
            @update:model-value="
                update((d) => {
                    if ($event) d.partial_exit = { atr: 1, fraction: 0.5 };
                    else delete d.partial_exit;
                })
            "
        />
        <div v-if="modelValue.partial_exit" class="row q-col-gutter-sm">
            <q-input
                class="col-6"
                :model-value="modelValue.partial_exit.atr"
                label="Partial target ATR"
                type="number"
                @update:model-value="
                    field('partial_exit', {
                        ...modelValue.partial_exit,
                        atr: Number($event),
                    })
                "
            />
            <q-input
                class="col-6"
                :model-value="modelValue.partial_exit.fraction"
                label="Fraction (0–1)"
                type="number"
                @update:model-value="
                    field('partial_exit', {
                        ...modelValue.partial_exit,
                        fraction: Number($event),
                    })
                "
            />
        </div>
        <q-checkbox
            :model-value="!!modelValue.funding?.enabled"
            label="Require complete funding rates and marks"
            @update:model-value="
                field('funding', {
                    enabled: !!$event,
                    interval_hours: modelValue.funding?.interval_hours || 8,
                })
            "
        />
        <q-select
            v-if="modelValue.funding?.enabled"
            :model-value="modelValue.funding.interval_hours"
            :options="intervals"
            label="Funding calendar hours"
            @update:model-value="
                field('funding', { enabled: true, interval_hours: $event })
            "
        />
        <h3 class="text-subtitle1">Optimization ranges</h3>
        <p>
            Defaults must equal the definition. Supported paths: indicator
            periods and settings, stop_atr, take_atr, risk_percent,
            timeout_bars, cooldown_bars.
        </p>
        <div
            v-for="(item, path) in modelValue.parameters"
            :key="path"
            class="row q-col-gutter-sm"
        >
            <q-input
                class="col-4"
                :model-value="path"
                label="Parameter path"
                @change="
                    update((d) => {
                        const key = String($event.target.value);
                        if (key !== path) {
                            d.parameters![key] = item;
                            delete d.parameters![path];
                        }
                    })
                "
            />
            <q-input
                v-for="key in ['default', 'min', 'max', 'step'] as const"
                :key="key"
                class="col-2"
                :model-value="item[key]"
                :label="key"
                type="number"
                @update:model-value="range(path, key, $event)"
            />
            <q-btn
                label="Remove range"
                flat
                @click="
                    update((d) => {
                        delete d.parameters![path];
                    })
                "
            />
        </div>
        <q-btn label="Add stop range" flat @click="addRange" />
    </div>
</template>
