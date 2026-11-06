<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useQuasar } from "quasar";
import ConditionEditor from "./ConditionEditor.vue";
import {
    describeCondition,
    type Definition,
    type Indicator,
    type Condition,
    type ParameterRange,
} from "../../strategyBuilder/definition.js";
import { getStrategyMethods, assignStrategyExchange } from "../../services/api";
import type { TradingMethod } from "../../types";

const $q = useQuasar();
const props = defineProps<{ modelValue: Definition }>();
const emit = defineEmits<{ "update:modelValue": [value: Definition] }>();
const names = computed(() => Object.keys(props.modelValue.indicators));

// Target Exchanges & Markets in VECTOR
const targetExchanges = [
    { label: "Bybit (linear USDT-perpetual / spot)", value: "bybit" },
    { label: "Binance (linear USDT-perpetual / spot)", value: "binance" },
    { label: "Coinbase Exchange (spot)", value: "coinbase" },
    { label: "OKX Global (perpetual / spot / futures / options)", value: "okx" },
    { label: "Bitget Global (perpetual / spot / futures)", value: "bitget" },
    { label: "Gate.io (perpetual / spot / futures / options)", value: "gate" },
    { label: "KuCoin (perpetual / spot / futures)", value: "kucoin" },
    { label: "Kraken (perpetual / spot / futures)", value: "kraken" },
    { label: "HTX Huobi (perpetual / spot / futures)", value: "htx" },
    { label: "Upbit Korea (spot)", value: "upbit" },
];

const marketTypes = [
    { label: "USDT Perpetual (Linear)", value: "perpetual" },
    { label: "Spot", value: "spot" },
    { label: "Dated Futures", value: "futures" },
    { label: "European Options", value: "options" },
];

const selectedTargetExchange = ref(props.modelValue.exchanges?.[0] || "bybit");
const selectedMarketType = ref((props.modelValue as any).market_type || "perpetual");
const assigningExchange = ref(false);
const assignmentNotice = ref<string | null>(null);

// Strategy Method Selection & Parameter Configuration
const availableMethods = ref<TradingMethod[]>([]);
const methodsLoading = ref(false);
const selectedMethodId = ref<string>((props.modelValue as any).method_id || "");
const configuredParams = ref<Record<string, any>>((props.modelValue as any).method_parameters || {});

const selectedMethod = computed(() => {
    return availableMethods.value.find((m) => m.id === selectedMethodId.value) || null;
});

async function loadAvailableMethods() {
    methodsLoading.value = true;
    try {
        const res = await getStrategyMethods();
        const list = res.data?.data || (Array.isArray(res.data) ? res.data : []);
        if (Array.isArray(list) && list.length > 0) {
            availableMethods.value = list;
        }
    } catch {
        // standalone test fallback
    } finally {
        methodsLoading.value = false;
    }
}

onMounted(() => {
    loadAvailableMethods();
});

watch(
    () => (props.modelValue as any).method_id,
    (newId) => {
        if (newId && newId !== selectedMethodId.value) {
            selectedMethodId.value = newId;
        }
    }
);

function getMethodStatusColor(status: string) {
    switch (status) {
        case "RESEARCH_READY":
        case "PAPER_READY":
            return "positive";
        case "SHADOW_READY":
            return "cyan";
        case "IMPLEMENTED":
            return "teal";
        case "BLOCKED_BY_DATA":
            return "warning";
        case "UNSUPPORTED":
            return "negative";
        case "CATALOGUED":
        default:
            return "grey-7";
    }
}

function onSelectMethodId(methodId: string | null) {
    if (!methodId) {
        selectedMethodId.value = "";
        update((d) => {
            delete (d as any).method_id;
            delete (d as any).method_parameters;
        });
        return;
    }
    const found = availableMethods.value.find((m) => m.id === methodId);
    if (!found) return;

    selectedMethodId.value = found.id;
    const params: Record<string, any> = {};
    if (Array.isArray(found.parameters)) {
        for (const p of found.parameters) {
            params[p.name] = p.default;
        }
    }
    configuredParams.value = params;

    update((d) => {
        (d as any).method_id = found.id;
        (d as any).method_parameters = params;
    });
}

function updateMethodParam(name: string, value: any) {
    configuredParams.value[name] = value;
    update((d) => {
        (d as any).method_parameters = { ...configuredParams.value };
    });
}

async function handleAssignExchange() {
    assigningExchange.value = true;
    assignmentNotice.value = null;
    try {
        const ex = selectedTargetExchange.value;
        const mt = selectedMarketType.value;
        update((d) => {
            d.exchanges = [ex];
            (d as any).market_type = mt;
        });

        const numId = typeof props.modelValue.id === "number"
            ? props.modelValue.id
            : parseInt(String(props.modelValue.id).replace(/\D/g, ""), 10);
        if (!isNaN(numId) && numId > 0) {
            await assignStrategyExchange(numId, ex, mt);
        }

        assignmentNotice.value = `Explicitly assigned to ${ex.toUpperCase()} [${mt}]. Virtual PAPER mode only — real exchange orders strictly disabled.`;
        $q.notify({
            type: "positive",
            message: `Strategy assigned to ${ex.toUpperCase()} (${mt}).`,
        });
    } catch (err: any) {
        assignmentNotice.value = `Assignment updated locally. Backend notice: ${err.message || "Saved in draft."}`;
    } finally {
        assigningExchange.value = false;
    }
}

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
        <!-- Explicit Exchange Assignment Card -->
        <q-card bordered flat class="q-mb-md bg-grey-10">
            <q-card-section>
                <div class="row items-center justify-between q-mb-xs">
                    <div class="text-subtitle2 text-weight-bold row items-center">
                        <q-icon name="hub" class="q-mr-xs text-primary" size="18px" />
                        Explicit Exchange Assignment (10 Target Venues)
                    </div>
                    <q-badge color="positive" outline label="Virtual PAPER Mode Only" />
                </div>
                <p class="text-caption text-grey-5 q-mb-sm">
                    Bind this strategy definition to an explicit target exchange and market type.
                    Real exchange trading is strictly disabled.
                </p>
                <div class="row q-col-gutter-sm items-center">
                    <q-select
                        class="col-12 col-md-5"
                        v-model="selectedTargetExchange"
                        :options="targetExchanges"
                        emit-value
                        map-options
                        dense
                        outlined
                        label="Target Exchange"
                        @update:model-value="update((d) => { d.exchanges = [$event]; })"
                    />
                    <q-select
                        class="col-12 col-md-4"
                        v-model="selectedMarketType"
                        :options="marketTypes"
                        emit-value
                        map-options
                        dense
                        outlined
                        label="Market Type"
                        @update:model-value="update((d) => { (d as any).market_type = $event; })"
                    />
                    <div class="col-12 col-md-3">
                        <q-btn
                            unelevated
                            color="primary"
                            icon="assignment_turned_in"
                            label="Assign Exchange"
                            class="full-width"
                            :loading="assigningExchange"
                            @click="handleAssignExchange"
                        />
                    </div>
                </div>
                <q-banner
                    v-if="assignmentNotice"
                    dense
                    rounded
                    class="bg-blue-grey-9 text-white q-mt-sm text-caption"
                >
                    <template #avatar><q-icon name="verified_user" color="teal" size="16px" /></template>
                    {{ assignmentNotice }}
                </q-banner>
            </q-card-section>
        </q-card>

        <!-- Strategy Method Selection & Parameter Configuration Card -->
        <q-card bordered flat class="q-mb-md bg-grey-10">
            <q-card-section>
                <div class="row items-center justify-between q-mb-xs">
                    <div class="text-subtitle2 text-weight-bold row items-center">
                        <q-icon name="category" class="q-mr-xs text-secondary" size="18px" />
                        Method Specification & Parameter Configuration
                    </div>
                    <q-badge
                        v-if="selectedMethod"
                        :color="getMethodStatusColor(selectedMethod.status)"
                        :label="selectedMethod.status_display || selectedMethod.status"
                    />
                </div>
                <div class="row q-col-gutter-sm items-center q-mb-sm">
                    <q-select
                        class="col-12 col-md-7"
                        :model-value="selectedMethodId"
                        :options="availableMethods"
                        option-value="id"
                        option-label="name"
                        emit-value
                        map-options
                        dense
                        outlined
                        clearable
                        label="Select Method from 50 Catalog"
                        :loading="methodsLoading"
                        @update:model-value="onSelectMethodId"
                    >
                        <template #option="scope">
                            <q-item v-bind="scope.itemProps">
                                <q-item-section>
                                    <q-item-label>{{ scope.opt.name }}</q-item-label>
                                    <q-item-label caption>
                                        {{ scope.opt.category_display }} &bull; {{ scope.opt.type_display }}
                                    </q-item-label>
                                </q-item-section>
                                <q-item-section side>
                                    <q-badge
                                        :color="getMethodStatusColor(scope.opt.status)"
                                        :label="scope.opt.status"
                                    />
                                </q-item-section>
                            </q-item>
                        </template>
                    </q-select>
                    <div class="col-12 col-md-5 text-caption text-grey-5">
                        {{ selectedMethod ? selectedMethod.description : "Select a method to configure schema parameters and required data feeds." }}
                    </div>
                </div>

                <div v-if="selectedMethod" class="q-mt-sm">
                    <div class="row q-gutter-xs q-mb-sm text-caption">
                        <q-badge outline color="secondary" :label="selectedMethod.category_display" />
                        <q-badge outline color="info" :label="selectedMethod.type_display" />
                        <q-badge outline color="teal" :label="`${selectedMethod.parameters?.length || 0} Parameters`" />
                        <q-badge v-if="selectedMethod.is_executable" color="positive" label="Executable Code" />
                        <q-badge v-else color="grey-7" label="Specification Catalog" />
                    </div>

                    <q-banner
                        v-if="selectedMethod.blocker_reason"
                        dense
                        rounded
                        class="bg-orange-10 text-white q-mb-sm text-caption"
                    >
                        <template #avatar><q-icon name="warning" color="amber" size="18px" /></template>
                        <strong>Data Dependency Blocker:</strong> {{ selectedMethod.blocker_reason }}
                    </q-banner>

                    <!-- Parameter Configuration Form -->
                    <div v-if="selectedMethod.parameters && selectedMethod.parameters.length" class="q-mt-sm">
                        <div class="text-caption text-weight-bold text-grey-4 q-mb-xs">
                            Configured Method Parameters:
                        </div>
                        <div class="row q-col-gutter-sm">
                            <div
                                v-for="p in selectedMethod.parameters"
                                :key="p.name"
                                class="col-12 col-sm-6 col-md-4"
                            >
                                <q-input
                                    v-if="p.type === 'integer' || p.type === 'float' || p.type === 'number'"
                                    :model-value="configuredParams[p.name] ?? p.default"
                                    :label="p.name"
                                    type="number"
                                    :min="p.min"
                                    :max="p.max"
                                    :step="p.step || (p.type === 'float' ? 0.1 : 1)"
                                    dense
                                    outlined
                                    :hint="p.description"
                                    @update:model-value="updateMethodParam(p.name, Number($event))"
                                />
                                <q-select
                                    v-else-if="p.options && p.options.length"
                                    :model-value="configuredParams[p.name] ?? p.default"
                                    :options="p.options"
                                    :label="p.name"
                                    dense
                                    outlined
                                    :hint="p.description"
                                    @update:model-value="updateMethodParam(p.name, $event)"
                                />
                                <q-input
                                    v-else
                                    :model-value="configuredParams[p.name] ?? p.default"
                                    :label="p.name"
                                    dense
                                    outlined
                                    :hint="p.description"
                                    @update:model-value="updateMethodParam(p.name, $event)"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </q-card-section>
        </q-card>

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
