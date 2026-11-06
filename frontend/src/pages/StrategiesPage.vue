<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { api, getStrategyMethods, getStrategyMethod, assignStrategyExchange } from "../services/api";
import VisualDefinitionEditor from "../components/strategyBuilder/VisualDefinitionEditor.vue";
import StrategyRunResult from "../components/strategyBuilder/StrategyRunResult.vue";
import {
    emaDefinition,
    definitionDiff,
    type Definition,
} from "../strategyBuilder/definition.js";
import type { TradingMethod, TradingMethodParameter } from "../types";

// Active Tab
const activeTab = ref("library");

// 50 Strategy Method Library
const catalogMethods = ref<TradingMethod[]>([]);
const catalogLoading = ref(false);
const catalogError = ref<string | null>(null);
const catalogSummary = ref<any | null>(null);

const selectedCategory = ref<string>("all");
const selectedType = ref<string>("all");
const searchFilter = ref<string>("");

const detailDialogOpen = ref(false);
const selectedMethod = ref<TradingMethod | null>(null);
const detailLoading = ref(false);

const categoryOptions = [
    { label: "All Families (50 Methods)", value: "all" },
    { label: "Family A: Trading Horizons and Direction", value: "trading_horizons" },
    { label: "Family B: Price and Technical Analysis", value: "price_technical" },
    { label: "Family C: Volatility and Volume", value: "volatility_volume" },
    { label: "Family D: Automation and Relative Value", value: "automation_relative_value" },
    { label: "Family E: Events and Alternative Data", value: "events_alternative_data" },
    { label: "Family F: Trade Management and Risk", value: "trade_management_risk" },
];

const typeOptions = [
    { label: "All Method Types", value: "all" },
    { label: "Trading Style", value: "style" },
    { label: "Signal Generator", value: "signal" },
    { label: "Confirmation Filter", value: "filter" },
    { label: "Market Regime Filter", value: "regime_filter" },
    { label: "Position Sizing Model", value: "position_sizing" },
    { label: "Portfolio Allocation", value: "portfolio_allocation" },
    { label: "Execution Method", value: "execution" },
    { label: "Position Management", value: "position_management" },
    { label: "Alternative Data", value: "alternative_data" },
];

async function loadCatalog() {
    catalogLoading.value = true;
    catalogError.value = null;
    try {
        const params: { category?: string; type?: string } = {};
        if (selectedCategory.value !== "all") params.category = selectedCategory.value;
        if (selectedType.value !== "all") params.type = selectedType.value;
        const res = await getStrategyMethods(params);
        const list = res.data?.data || (Array.isArray(res.data) ? res.data : []);
        catalogMethods.value = list;
        catalogSummary.value = res.data?.summary || res.summary || null;
    } catch (err: any) {
        catalogError.value = err.message || "Failed to load strategy method library.";
    } finally {
        catalogLoading.value = false;
    }
}

const filteredCatalog = computed(() => {
    let list = catalogMethods.value;
    if (selectedCategory.value !== "all") {
        list = list.filter((m) => m.category === selectedCategory.value);
    }
    if (selectedType.value !== "all") {
        list = list.filter((m) => m.type === selectedType.value);
    }
    const q = searchFilter.value.trim().toLowerCase();
    if (q) {
        list = list.filter((m) => {
            const matchId = m.id.toLowerCase().includes(q);
            const matchName = m.name.toLowerCase().includes(q);
            const matchDesc = m.description.toLowerCase().includes(q);
            const matchInd = m.required_indicators?.some((i) => i.toLowerCase().includes(q));
            const matchData = m.required_input_data?.some((d) => d.toLowerCase().includes(q));
            return matchId || matchName || matchDesc || matchInd || matchData;
        });
    }
    return list;
});

function getMethodStatusBadgeColor(status: string) {
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

async function openMethodDetails(method: TradingMethod) {
    selectedMethod.value = method;
    detailDialogOpen.value = true;
    detailLoading.value = true;
    try {
        const res = await getStrategyMethod(method.id);
        if (res.data) {
            selectedMethod.value = res.data;
        }
    } catch {
        // fallback to method
    } finally {
        detailLoading.value = false;
    }
}

function loadMethodIntoBuilder(method: TradingMethod) {
    detailDialogOpen.value = false;
    activeTab.value = "builder";
    if (preview.value) {
        const updated = structuredClone(preview.value);
        (updated as any).method_id = method.id;
        const params: Record<string, any> = {};
        if (Array.isArray(method.parameters)) {
            for (const p of method.parameters) {
                params[p.name] = p.default;
            }
        }
        (updated as any).method_parameters = params;
        edit(updated);
    }
    message.value = `Method '${method.name}' loaded into Visual Strategy Builder.`;
}

onMounted(() => {
    loadCatalog();
});

interface Version {
    id: number;
    strategy_id: string;
    version: number;
    status: string;
    checksum: string;
    definition: string | Definition;
}
interface Draft {
    id: string;
    revision: number;
    checksum: string;
    status: string;
    description: string;
    definition: string | Definition;
}
interface Account {
    id: string;
    exchange_id: string;
    status: string;
    starting_balance: string;
    current_equity: string;
    strategy_version: string;
}
interface Eligibility {
    paper_eligible: boolean;
    owner_authorized: boolean;
    reasons: string[];
    accounts: Account[];
}
interface Experiment {
    id: string;
    status: string;
    results: unknown;
    error: unknown;
}
const token = ref("");
const versions = ref<Version[]>([]);
const drafts = ref<Draft[]>([]);
const builtin = ref<
    Array<{ id: string; version: string; description: string }>
>([]);
const page = ref(1);
const lastPage = ref(1);
const draftPage = ref(1);
const draftLast = ref(1);
const error = ref("");
const busy = ref(false);
const message = ref("");
const selected = ref<Version | null>(null);
const draft = ref<Draft | null>(null);
const draftText = ref("");
const history = ref<unknown>(null);
const description = ref(
    "Create a strategy for BTCUSDT. Enter long when EMA 20 crosses above EMA 50 and RSI below 65. Stop Loss 1.5 ATR. Take Profit 3 ATR. Risk 0.5%. Timeframe 15m.",
);
const text = ref(
    JSON.stringify(
        emaDefinition({
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
        }),
        null,
        2,
    ),
);
const confirmed = ref(false);
const validated = ref(false);
const hosted = ref(false);
const hostedConsent = ref(false);
const hostedEnabled = ref(false);
const from = ref("");
const to = ref("");
const capital = ref("10000");
const exchange = ref("bybit");
const symbol = ref("BTCUSDT");
const datasetPin = ref<string | null>(null);
const datasets = ref<
    Array<{
        id: string;
        exchange: string;
        symbols: string;
        from_at: string;
        to_at: string;
    }>
>([]);
const datasetPage = ref(1);
const datasetLast = ref(1);
async function loadDatasets() {
    const r = await api.get("/strategies/datasets", {
        headers: headers(),
        params: { page: datasetPage.value, exchange: exchange.value },
    });
    datasets.value = r.data.data.data;
    datasetLast.value = r.data.data.last_page;
}
function selectDataset(id: string | null) {
    const row = datasets.value.find((d) => d.id === id);
    if (row) {
        exchange.value = row.exchange;
        symbol.value = JSON.parse(row.symbols)[0];
        from.value = new Date(row.from_at).toISOString();
        to.value = new Date(row.to_at).toISOString();
    }
}
const run = ref<Record<string, unknown> | null>(null);
const runs = ref<Array<Record<string, unknown>>>([]);
const runPage = ref(1);
const runLast = ref(1);
const eligibility = ref<Eligibility | null>(null);
const ownerReference = ref("");
const primaryConsent = ref(false);
const activationConsent = ref(false);
const handover = ref(false);
const allocation = ref("1000");
const fundingConsent = ref(false);
const accountConsent = ref(false);
const experiments = ref<Experiment[]>([]);
const experiment = ref<Record<string, unknown> | null>(null);
const experimentPage = ref(1);
const experimentLast = ref(1);
const shadow = ref<unknown>(null);
const options = ref({
    max_candidates: 8,
    max_windows: 2,
    train_hours: 48,
    validation_hours: 12,
    test_hours: 12,
    step_hours: 24,
    seed: 0,
    start_shadow: false,
});
const compareId = ref<number | null>(null);
const comparison = ref<unknown>(null);
const preview = computed(() => {
    try {
        return JSON.parse(text.value) as Definition;
    } catch {
        return null;
    }
});
const dirtyDraft = computed(
    () =>
        draftText.value !==
        JSON.stringify({
            definition: preview.value,
            description: description.value,
        }),
);
watch(text, () => {
    confirmed.value = false;
    validated.value = false;
    selected.value = null;
    eligibility.value = null;
    run.value = null;
    experiments.value = [];
    experiment.value = null;
    shadow.value = null;
    runs.value = [];
    comparison.value = null;
});
const headers = () => ({ "X-Research-Token": token.value });
async function action(fn: () => Promise<void>) {
    busy.value = true;
    error.value = "";
    message.value = "";
    try {
        await fn();
    } catch (e) {
        error.value = e instanceof Error ? e.message : "Request failed";
    } finally {
        busy.value = false;
    }
}
function json(value: string | Definition): Definition {
    return typeof value === "string" ? JSON.parse(value) : value;
}
function edit(value: Definition) {
    text.value = JSON.stringify(value, null, 2);
}
async function load() {
    const [v, d, s] = await Promise.all([
        api.get("/strategies", {
            headers: headers(),
            params: { page: page.value },
        }),
        api.get("/strategies/drafts", {
            headers: headers(),
            params: { page: draftPage.value },
        }),
        api.get("/strategies/schema", { headers: headers() }),
    ]);
    versions.value = v.data.data.data;
    lastPage.value = v.data.data.last_page;
    builtin.value = v.data.builtin;
    drafts.value = d.data.data.data;
    draftLast.value = d.data.data.last_page;
    hostedEnabled.value = s.data.hosted_enabled;
}
async function parse() {
    await action(async () => {
        const r = await api.post(
            "/strategies/parse",
            {
                description: description.value,
                provider: hosted.value ? "hosted" : "deterministic",
                consent: hostedConsent.value,
            },
            { headers: headers() },
        );
        edit(r.data.data.definition);
        message.value = r.data.data.assumptions.join("; ");
    });
}
async function validate() {
    await action(async () => {
        const r = await api.post(
            "/strategies/validate",
            { definition: preview.value },
            { headers: headers() },
        );
        validated.value = true;
        message.value =
            "Research rules validated. PAPER requires separate parity evidence and owner approval. " +
            r.data.data.limitations.join(", ");
    });
}
async function save() {
    await action(async () => {
        const r = await api.post(
            "/strategies",
            {
                definition: preview.value,
                interpretation_confirmed: confirmed.value,
            },
            { headers: headers() },
        );
        selected.value = r.data.data;
        await load();
        message.value = "Immutable version saved.";
    });
}
async function saveDraft() {
    await action(async () => {
        const r = await api.post(
            "/strategies/drafts",
            {
                id:
                    draft.value?.status === "DRAFT"
                        ? draft.value.id
                        : crypto.randomUUID(),
                revision:
                    draft.value?.status === "DRAFT"
                        ? draft.value.revision
                        : null,
                definition: preview.value,
                description: description.value,
            },
            { headers: headers() },
        );
        draft.value = r.data.data;
        draftText.value = JSON.stringify({
            definition: preview.value,
            description: description.value,
        });
        await load();
        message.value = "Draft saved durably with revision protection.";
    });
}
function loadDraft(row: Draft) {
    draft.value = row;
    description.value = row.description || "";
    edit(json(row.definition));
    draftText.value = JSON.stringify({
        definition: json(row.definition),
        description: description.value,
    });
}
async function publish() {
    await action(async () => {
        if (!draft.value) throw new Error("Save a draft first");
        const r = await api.post(
            `/strategies/drafts/${draft.value.id}/publish`,
            {
                revision: draft.value.revision,
                checksum: draft.value.checksum,
                interpretation_confirmed: confirmed.value,
            },
            { headers: headers() },
        );
        selected.value = r.data.data;
        draft.value = null;
        await load();
        message.value = "Confirmed draft published as an immutable version.";
    });
}
function clone(row: Version) {
    const value = structuredClone(json(row.definition));
    value.version++;
    edit(value);
    draft.value = null;
    message.value =
        "Editing a new version; the saved original remains immutable.";
}
async function inspect(row: Version) {
    await action(async () => {
        edit(json(row.definition));
        await nextTick();
        selected.value = row;
        datasetPin.value = null;
        exchange.value = json(row.definition).exchanges[0];
        symbol.value = json(row.definition).symbols[0];
        history.value = (
            await api.get(`/strategies/${row.id}`, { headers: headers() })
        ).data;
        await refreshEligibility();
    });
}
async function refreshEligibility() {
    const id = selected.value?.id;
    if (!id) return;
    const r = await api.get(`/strategies/${id}/eligibility`, {
        headers: headers(),
    });
    if (selected.value?.id === id) eligibility.value = r.data.data;
}
const scope = () => ({
    exchange: exchange.value,
    symbol: symbol.value,
    from: from.value,
    to: to.value,
    capital: capital.value,
});
async function research() {
    await action(async () => {
        if (!selected.value) throw new Error("Select a saved version first");
        run.value = (
            await api.post(
                `/strategies/${selected.value.id}/backtests`,
                {
                    ...scope(),
                    ...(datasetPin.value
                        ? { dataset_pin_id: datasetPin.value }
                        : {}),
                },
                { headers: headers(), timeout: 180000 },
            )
        ).data.data;
        message.value =
            "Pinned Go backtest completed. Research results are not forward PAPER performance.";
    });
}
async function results() {
    if (!selected.value) return;
    const r = await api.get(`/strategies/${selected.value.id}/backtests`, {
        headers: headers(),
        params: { page: runPage.value },
    });
    runs.value = r.data.data.data;
    runLast.value = r.data.data.last_page;
}
async function govern(
    kind: "approve-paper" | "activate-paper" | "evaluate-paper",
) {
    await action(async () => {
        if (!selected.value) return;
        const payload =
            kind === "evaluate-paper"
                ? { run_id: run.value?.id }
                : {
                      checksum: selected.value.checksum,
                      reference: ownerReference.value,
                      confirm_primary: primaryConsent.value,
                      confirm_activation: activationConsent.value,
                      confirm_version_handover: handover.value,
                  };
        const r = await api.post(
            `/strategies/${selected.value.id}/${kind}`,
            payload,
            { headers: headers(), timeout: 180000 },
        );
        eligibility.value = r.data.data;
        await load();
        message.value =
            kind === "approve-paper"
                ? "Owner PRIMARY approval recorded. New accounts are unfunded and BLOCKED."
                : kind === "activate-paper"
                  ? "Strategy version authorized. Account funding and authorization remain separate."
                  : "Parity evidence recorded for this exchange and instrument.";
    });
}
async function fund(account: Account) {
    await action(async () => {
        await api.post(
            `/strategies/${selected.value!.id}/accounts/${account.id}/fund`,
            {
                amount: allocation.value,
                reference: ownerReference.value,
                confirm_virtual_allocation: fundingConsent.value,
            },
            { headers: headers() },
        );
        await refreshEligibility();
        message.value =
            "Initial virtual allocation recorded; account remains BLOCKED.";
    });
}
async function authorize(account: Account) {
    await action(async () => {
        await api.post(
            `/strategies/${selected.value!.id}/accounts/${account.id}/authorize`,
            {
                reference: ownerReference.value,
                confirm_account_authorization: accountConsent.value,
            },
            { headers: headers() },
        );
        await refreshEligibility();
        message.value = "New account authorization recorded after risk checks.";
    });
}
async function optimizationList() {
    if (!selected.value) return;
    const id = selected.value.id;
    const r = await api.get(`/strategies/${id}/optimizations`, {
        headers: headers(),
        params: { page: experimentPage.value },
    });
    if (selected.value?.id !== id) return;
    experiments.value = r.data.data.data;
    experimentLast.value = r.data.data.last_page;
}
async function optimize() {
    await action(async () => {
        const r = await api.post(
            `/strategies/${selected.value!.id}/optimizations`,
            { ...scope(), options: options.value },
            { headers: headers(), timeout: 180000 },
        );
        message.value = `Optimization ${r.data.data.id} queued. Approved rules remain unchanged.`;
        await optimizationList();
    });
}
async function experimentDetail(id: string) {
    experiment.value = (
        await api.get(`/strategies/optimizations/${id}`, { headers: headers() })
    ).data;
}
async function cancel(id: string) {
    await action(async () => {
        await api.post(
            `/strategies/optimizations/${id}/cancel`,
            {},
            { headers: headers() },
        );
        await optimizationList();
    });
}
let poll: ReturnType<typeof setTimeout> | undefined;
let stopped = false;
async function pollResearch() {
    try {
        if (
            !busy.value &&
            selected.value &&
            experiments.value.some((e) =>
                ["QUEUED", "RUNNING", "CANCEL_REQUESTED"].includes(e.status),
            )
        ) {
            await optimizationList();
        }
    } catch (e) {
        error.value =
            e instanceof Error ? e.message : "Research polling failed";
    } finally {
        if (!stopped) poll = setTimeout(pollResearch, 5000);
    }
}
poll = setTimeout(pollResearch, 5000);
onBeforeUnmount(() => {
    stopped = true;
    clearTimeout(poll);
});
async function setShadow(enabled: boolean) {
    await action(async () => {
        await api.post(
            `/strategies/${selected.value!.id}/shadow`,
            { enabled },
            { headers: headers() },
        );
        await shadowResults();
    });
}
async function shadowResults() {
    shadow.value = (
        await api.get(`/strategies/${selected.value!.id}/shadow`, {
            headers: headers(),
        })
    ).data;
}
async function compare() {
    if (!selected.value || !compareId.value) return;
    const row = (
        await api.get(`/strategies/${compareId.value}`, { headers: headers() })
    ).data.data;
    comparison.value = definitionDiff(
        json(selected.value.definition),
        json(row.definition),
    );
}
async function lifecycle(status: string) {
    await action(async () => {
        await api.post(
            `/strategies/${selected.value!.id}/lifecycle`,
            { status, reference: ownerReference.value },
            { headers: headers() },
        );
        await load();
        await refreshEligibility();
        message.value = `Lifecycle changed to ${status}.`;
    });
}
</script>
<template>
    <q-page padding>
        <h1 class="text-h4">Strategy management</h1>
        <q-banner class="bg-warning text-black q-mb-md"
            >RESEARCH / PAPER MODE ONLY — NOT FINANCIAL ADVICE. All executions
            use virtual capital.</q-banner
        >
        <q-input
            v-model="token"
            label="Research owner token"
            type="password"
            autocomplete="off"
        />
        <q-btn label="Load strategies" :loading="busy" @click="action(load)" />
        <q-banner
            v-if="error"
            class="bg-negative text-white q-my-md"
            role="alert"
            >{{ error }}</q-banner
        >
        <q-banner v-if="message" class="bg-info q-my-md" role="status">{{
            message
        }}</q-banner>

        <!-- Navigation Tabs -->
        <q-tabs
            v-model="activeTab"
            dense
            class="bg-grey-10 text-primary q-mb-md rounded-borders"
            active-color="primary"
            indicator-color="primary"
            align="left"
        >
            <q-tab name="library" icon="menu_book" label="Strategy Method Library (50 Catalog)" />
            <q-tab name="builder" icon="tune" label="Visual Strategy Builder" />
            <q-tab name="custom" icon="history_edu" label="Drafts & Versions" />
            <q-tab name="authoring" icon="translate" label="Language Authoring" />
            <q-tab name="research" icon="analytics" label="Runs & Research" />
        </q-tabs>

        <q-tab-panels v-model="activeTab" animated class="bg-transparent">
            <!-- TAB 1: Strategy Method Library (50-Method Catalog) -->
            <q-tab-panel name="library" class="q-pa-none">
                <div class="row items-center justify-between q-mb-md">
                    <div>
                        <div class="text-h5 text-weight-bold">Quantitative Trading Method Library</div>
                        <div class="text-caption text-grey-5">
                            Comprehensive catalog of 50 quantitative methods across Family A through F with formal parameter schemas and data dependencies.
                        </div>
                    </div>
                    <div class="row q-gutter-sm items-center">
                        <q-badge outline color="positive" :label="`${catalogSummary?.total_methods || 50} Methods Catalogued`" class="q-py-xs q-px-sm" />
                        <q-btn outline color="primary" icon="refresh" label="Refresh" :loading="catalogLoading" @click="loadCatalog" />
                    </div>
                </div>

                <!-- Filter Toolbar -->
                <div class="row q-col-gutter-sm items-center q-mb-md">
                    <q-select
                        class="col-12 col-md-4"
                        v-model="selectedCategory"
                        :options="categoryOptions"
                        emit-value
                        map-options
                        dense
                        outlined
                        label="Family Category"
                        @update:model-value="loadCatalog"
                    />
                    <q-select
                        class="col-12 col-md-3"
                        v-model="selectedType"
                        :options="typeOptions"
                        emit-value
                        map-options
                        dense
                        outlined
                        label="Method Type"
                        @update:model-value="loadCatalog"
                    />
                    <q-input
                        class="col-12 col-md-5"
                        v-model="searchFilter"
                        dense
                        outlined
                        clearable
                        placeholder="Search by ID, name, description, indicators, input data..."
                    >
                        <template #prepend><q-icon name="search" size="18px" /></template>
                    </q-input>
                </div>

                <!-- Loading & Error states -->
                <div v-if="catalogLoading" class="row justify-center q-pa-xl">
                    <q-spinner color="primary" size="48px" />
                </div>
                <q-banner v-else-if="catalogError" dense rounded class="bg-negative text-white q-mb-md">
                    {{ catalogError }}
                </q-banner>

                <!-- Method Cards Grid -->
                <div v-else-if="filteredCatalog.length" class="row q-col-gutter-md">
                    <div
                        v-for="method in filteredCatalog"
                        :key="method.id"
                        class="col-12 col-md-6 col-lg-4"
                    >
                        <q-card
                            bordered
                            flat
                            class="method-card full-height column justify-between"
                        >
                            <q-card-section>
                                <div class="row items-center justify-between no-wrap q-mb-xs">
                                    <div class="text-subtitle1 text-weight-bold ellipsis text-white">
                                        {{ method.name }}
                                    </div>
                                    <q-badge
                                        :color="getMethodStatusBadgeColor(method.status)"
                                        :label="method.status_display || method.status"
                                        class="q-px-xs"
                                    />
                                </div>
                                <div class="text-caption text-grey-5 q-mb-sm">
                                    <code>{{ method.id }}</code>
                                </div>

                                <div class="row q-gutter-xs q-mb-sm">
                                    <q-badge outline color="secondary" :label="method.category_display" />
                                    <q-badge outline color="info" :label="method.type_display" />
                                    <q-badge outline color="teal" :label="`${method.parameters?.length || 0} params`" />
                                </div>

                                <p class="text-caption text-grey-4 q-mb-sm ellipsis-3-lines">
                                    {{ method.description }}
                                </p>

                                <!-- Blocker Callout if any -->
                                <q-banner
                                    v-if="method.blocker_reason"
                                    dense
                                    rounded
                                    class="bg-orange-10 text-white q-mt-xs text-caption"
                                >
                                    <template #avatar><q-icon name="warning" color="amber" size="16px" /></template>
                                    <strong>Blocked by data:</strong> {{ method.blocker_reason }}
                                </q-banner>

                                <!-- Supported Markets Tags -->
                                <div class="row q-gutter-xs q-mt-xs items-center">
                                    <span class="text-caption text-grey-6">Markets:</span>
                                    <q-badge
                                        v-for="mkt in method.supported_market_types"
                                        :key="mkt"
                                        color="blue-grey-8"
                                        :label="String(mkt).toUpperCase()"
                                        dense
                                    />
                                </div>
                            </q-card-section>

                            <q-separator />

                            <q-card-actions align="between" class="bg-grey-10 q-px-sm">
                                <q-btn
                                    flat
                                    dense
                                    size="sm"
                                    color="primary"
                                    icon="visibility"
                                    label="Details & Schemas"
                                    @click="openMethodDetails(method)"
                                />
                                <q-btn
                                    flat
                                    dense
                                    size="sm"
                                    color="secondary"
                                    icon="tune"
                                    label="Configure in Builder"
                                    @click="loadMethodIntoBuilder(method)"
                                />
                            </q-card-actions>
                        </q-card>
                    </div>
                </div>
                <div v-else class="text-center text-grey-5 q-pa-xl">
                    <q-icon name="filter_list_off" size="48px" class="q-mb-sm" />
                    <div class="text-h6">No trading methods match your current filters.</div>
                    <div class="text-caption">Try clearing search or switching Family / Type dropdowns.</div>
                </div>
            </q-tab-panel>

            <!-- TAB 3: Drafts & Custom Versions -->
            <q-tab-panel name="custom" class="q-pa-none">
        <q-expansion-item
            label="Built-in strategies (existing research UI preserved)"
        >
            <q-list
                ><q-item v-for="s in builtin" :key="s.id"
                    ><q-item-section
                        >{{ s.id }} · {{ s.version }} —
                        {{ s.description }}</q-item-section
                    ></q-item
                ></q-list
            ><q-btn to="/research" label="Existing strategy research" flat />
        </q-expansion-item>
        <h2 class="text-h6">Durable authoring drafts</h2>
        <q-list
            ><q-item v-for="d in drafts" :key="d.id"
                ><q-item-section
                    >{{ json(d.definition).id }} · revision {{ d.revision }} ·
                    {{ d.status }}</q-item-section
                ><q-btn label="Resume draft" @click="loadDraft(d)" /></q-item
        ></q-list>
        <q-pagination
            v-model="draftPage"
            :max="draftLast"
            @update:model-value="action(load)"
        />
        <h2 class="text-h6">Immutable custom versions</h2>
        <q-markup-table
            ><thead>
                <tr>
                    <th>Strategy</th>
                    <th>Version</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="s in versions" :key="s.id">
                    <td>{{ s.strategy_id }}</td>
                    <td>{{ s.version }}</td>
                    <td>{{ s.status }}</td>
                    <td>
                        <q-btn
                            label="Clone / new version"
                            flat
                            @click="clone(s)"
                        /><q-btn
                            label="Inspect / select"
                            flat
                            @click="inspect(s)"
                        />
                    </td>
                </tr></tbody
        ></q-markup-table>
        <q-pagination
            v-model="page"
            :max="lastPage"
            @update:model-value="action(load)"
        />
            </q-tab-panel>

            <!-- TAB 2: Visual Strategy Builder -->
            <q-tab-panel name="builder" class="q-pa-none">
                <div class="row items-center justify-between q-mb-md">
                    <div>
                        <div class="text-h5 text-weight-bold">Visual Strategy Builder</div>
                        <div class="text-caption text-grey-5">
                            Compose rules, configure method parameter schemas, and assign target exchanges explicitly.
                        </div>
                    </div>
                </div>

                <VisualDefinitionEditor
                    v-if="preview"
                    :model-value="preview"
                    @update:model-value="edit"
                />

                <q-expansion-item label="Advanced versioned JSON" class="q-mt-md"
                    ><q-input
                        v-model="text"
                        type="textarea"
                        autogrow
                        label="Versioned strategy JSON"
                        input-class="text-monospace"
                /></q-expansion-item>
                <div class="row q-gutter-sm items-center q-mt-md">
                    <q-btn
                        label="Save durable draft"
                        color="secondary"
                        outline
                        :disable="busy || !preview"
                        @click="saveDraft"
                    />
                    <q-checkbox
                        v-model="confirmed"
                        label="I confirm these interpreted rules and assumptions"
                    />
                    <q-btn label="Validate" color="info" outline :disable="busy || !preview" @click="validate" />
                    <q-btn
                        label="Publish confirmed draft"
                        color="primary"
                        outline
                        :disable="
                            busy ||
                            !draft ||
                            dirtyDraft ||
                            draft.status !== 'DRAFT' ||
                            !confirmed ||
                            !validated
                        "
                        @click="publish"
                    />
                    <q-btn
                        label="Save immutable version"
                        color="positive"
                        unelevated
                        :disable="busy || !confirmed || !validated"
                        @click="save"
                    />
                </div>
            </q-tab-panel>

            <!-- TAB 4: Language Authoring -->
            <q-tab-panel name="authoring" class="q-pa-none">
                <h2 class="text-h6">English / Russian authoring</h2>
                <q-input
                    v-model="description"
                    type="textarea"
                    label="Supported grammar description"
                />
                <p class="text-caption text-grey-5 q-mt-xs">
                    Deterministic grammar supports EMA crossovers, an RSI threshold,
                    optional 1h EMA trend confirmation, ATR stop/target and explicit
                    risk. Unrecognized text fails validation.
                </p>
                <q-checkbox
                    v-model="hosted"
                    :disable="!hostedEnabled"
                    label="Use configured hosted authoring provider"
                />
                <q-checkbox
                    v-if="hosted"
                    v-model="hostedConsent"
                    label="I consent to send this description to the configured provider. Do not include secrets or private trading information."
                />
                <div class="q-mt-sm">
                    <q-btn
                        label="Interpret description"
                        color="primary"
                        :disable="busy || (hosted && !hostedConsent)"
                        @click="parse"
                    />
                </div>
            </q-tab-panel>

            <!-- TAB 5: Runs & Research -->
            <q-tab-panel name="research" class="q-pa-none">
        <template v-if="selected">
            <h2 class="text-h6">
                Selected: {{ selected.strategy_id }} v{{ selected.version }}
            </h2>
            <q-select
                v-model="compareId"
                :options="
                    versions.map((v) => ({
                        label: v.strategy_id + ' v' + v.version,
                        value: v.id,
                    }))
                "
                emit-value
                map-options
                label="Compare version"
            /><q-btn label="Compare rules" @click="action(compare)" />
            <pre v-if="comparison">{{
                JSON.stringify(comparison, null, 2)
            }}</pre>
            <h2 class="text-h6">Historical research</h2>
            <q-select
                v-model="exchange"
                :options="json(selected.definition).exchanges"
                label="Backtest exchange"
            /><q-select
                v-model="symbol"
                :options="json(selected.definition).symbols"
                label="Backtest instrument"
            />
            <q-btn
                label="Load pinned datasets"
                @click="action(loadDatasets)"
            /><q-select
                v-model="datasetPin"
                clearable
                :options="
                    datasets.map((d) => ({
                        label:
                            d.exchange +
                            ' ' +
                            d.from_at +
                            ' → ' +
                            d.to_at +
                            ' ' +
                            d.id,
                        value: d.id,
                    }))
                "
                emit-value
                map-options
                label="Dataset pin (empty creates a strict pin)"
                @update:model-value="selectDataset"
            /><q-pagination
                v-model="datasetPage"
                :max="datasetLast"
                @update:model-value="action(loadDatasets)"
            />
            <q-input v-model="from" label="From UTC (ISO 8601)" /><q-input
                v-model="to"
                label="To UTC (ISO 8601), maximum 7 days"
            /><q-input v-model="capital" label="Virtual starting capital" />
            <p>
                Requires complete closed candles, indicator warmup, explicit
                exchange costs and the declared funding calendar. Each run pins
                its dataset and Go executable.
            </p>
            <q-btn
                label="Run pinned Go backtest"
                :disable="busy"
                @click="research"
            /><q-btn
                label="Historical results"
                :disable="busy"
                @click="action(results)"
            />
            <q-list
                ><q-item v-for="r in runs" :key="String(r.id)"
                    ><q-item-section
                        >{{ r.created_at }} · {{ r.id }}</q-item-section
                    ><q-btn label="View result" @click="run = r" /></q-item
            ></q-list>
            <q-pagination
                v-model="runPage"
                :max="runLast"
                @update:model-value="action(results)"
            />
            <StrategyRunResult v-if="run" :run="run" />
            <h2 class="text-h6">Bounded autonomous optimization</h2>
            <div class="row q-col-gutter-sm">
                <q-input
                    v-for="key in [
                        'max_candidates',
                        'max_windows',
                        'train_hours',
                        'validation_hours',
                        'test_hours',
                        'step_hours',
                        'seed',
                    ] as const"
                    :key="key"
                    v-model.number="options[key]"
                    class="col-4"
                    :label="key"
                    type="number"
                />
            </div>
            <q-checkbox
                v-model="options.start_shadow"
                label="Request shadow of selected candidates if existing autonomy policy permits"
            />
            <q-btn
                label="Request optimization"
                :disable="busy"
                @click="optimize"
            /><q-btn
                label="Refresh experiments"
                @click="action(optimizationList)"
            />
            <p>
                Train / validation select candidates; held-out test evaluates
                the selected candidate only. Candidates are separate immutable
                versions. Existing autonomy controls and kill switch apply.
            </p>
            <q-list
                ><q-item v-for="e in experiments" :key="e.id"
                    ><q-item-section
                        >{{ e.id }} · {{ e.status }}
                        {{ e.error }}</q-item-section
                    ><q-btn
                        label="Inspect experiment"
                        @click="action(() => experimentDetail(e.id))" /><q-btn
                        v-if="['QUEUED', 'RUNNING'].includes(e.status)"
                        label="Cancel experiment"
                        @click="cancel(e.id)" /></q-item
            ></q-list>
            <q-pagination
                v-model="experimentPage"
                :max="experimentLast"
                @update:model-value="action(optimizationList)"
            />
            <pre v-if="experiment">{{
                JSON.stringify(experiment, null, 2)
            }}</pre>
            <q-btn
                label="Start forward shadow"
                @click="setShadow(true)"
            /><q-btn label="Stop shadow" @click="setShadow(false)" /><q-btn
                label="Shadow results"
                @click="action(shadowResults)"
            />
            <pre v-if="shadow">{{ JSON.stringify(shadow, null, 2) }}</pre>
            <h2 class="text-h6">
                PAPER eligibility and explicit owner approval
            </h2>
            <q-btn
                label="Refresh eligibility"
                @click="action(refreshEligibility)"
            /><q-btn
                label="Verify Go / PAPER parity for this run"
                :disable="busy || !run"
                @click="govern('evaluate-paper')"
            />
            <p>
                Technical eligibility:
                {{ eligibility?.paper_eligible ? "VERIFIED" : "INCOMPLETE" }}.
                Owner PRIMARY approval:
                {{ eligibility?.owner_authorized ? "RECORDED" : "REQUIRED" }}.
            </p>
            <ul>
                <li v-for="reason in eligibility?.reasons" :key="reason">
                    {{ reason }}
                </li>
            </ul>
            <p>
                Certify every declared exchange and instrument. PAPER currently
                supports v2 rules on 15m with complete 8h funding, no custom
                rule exits, partial targets or cooldown.
            </p>
            <q-input
                v-model="ownerReference"
                label="Owner audit reference (8–160 characters)"
            />
            <q-checkbox
                v-model="primaryConsent"
                label="I approve this exact immutable version as PRIMARY and provision unfunded BLOCKED accounts"
            />
            <q-btn
                label="Submit for PAPER approval"
                :disable="
                    busy || !primaryConsent || !eligibility?.paper_eligible
                "
                @click="govern('approve-paper')"
            />
            <q-checkbox
                v-model="activationConsent"
                label="I authorize this approved version to generate forward PAPER decisions"
            />
            <q-checkbox
                v-model="handover"
                label="Explicitly hand over existing flat custom accounts to this version"
            />
            <q-btn
                label="Authorize PAPER version"
                :disable="
                    busy || !activationConsent || !eligibility?.owner_authorized
                "
                @click="govern('activate-paper')"
            />
            <h3 class="text-subtitle1">
                Associated independent PAPER accounts
            </h3>
            <p>
                New accounts remain BLOCKED until separately funded and
                authorized. Existing risk-blocked accounts cannot be resumed
                here. Additional allocations never expand the original global
                risk budget.
            </p>
            <q-input
                v-model="allocation"
                label="Initial virtual allocation for a fresh account"
            />
            <q-checkbox
                v-model="fundingConsent"
                label="I authorize this initial virtual allocation"
            /><q-checkbox
                v-model="accountConsent"
                label="I authorize this fresh funded account under current risk policy"
            />
            <q-list
                ><q-item v-for="a in eligibility?.accounts" :key="a.id"
                    ><q-item-section
                        >{{ a.exchange_id }} · {{ a.status }} · equity
                        {{ a.current_equity }} · v{{
                            a.strategy_version
                        }}</q-item-section
                    ><q-btn
                        label="Fund fresh account"
                        :disable="
                            busy || !fundingConsent || a.status !== 'BLOCKED'
                        "
                        @click="fund(a)" /><q-btn
                        label="Authorize fresh account"
                        :disable="
                            busy || !accountConsent || a.status !== 'BLOCKED'
                        "
                        @click="authorize(a)" /></q-item
            ></q-list>
            <q-btn
                label="Enable research"
                @click="lifecycle('RESEARCH_ENABLED')"
            /><q-btn
                label="Reject version"
                @click="lifecycle('REJECTED')"
            /><q-btn label="Archive version" @click="lifecycle('ARCHIVED')" />
            <q-expansion-item label="Lifecycle audit">
                <pre>{{ JSON.stringify(history, null, 2) }}</pre>
            </q-expansion-item>
        </template>
        <div v-else class="text-center text-grey-5 q-pa-xl">
            <q-icon name="info" size="40px" class="q-mb-sm" />
            <div>Select an immutable custom version in the 'Drafts & Versions' tab to inspect historical runs, backtests and eligibility.</div>
        </div>
            </q-tab-panel>
        </q-tab-panels>

        <!-- Method Details Dialog -->
        <q-dialog v-model="detailDialogOpen" full-width>
            <q-card class="bg-dark text-white" v-if="selectedMethod">
                <q-card-section class="row items-center justify-between">
                    <div class="row items-center q-gutter-sm">
                        <q-avatar icon="menu_book" color="primary" text-color="black" />
                        <div>
                            <div class="text-h6 text-weight-bold">
                                {{ selectedMethod.name }} (<code>{{ selectedMethod.id }}</code>)
                            </div>
                            <div class="text-caption text-grey-5">
                                {{ selectedMethod.category_display }} &bull; {{ selectedMethod.type_display }}
                            </div>
                        </div>
                    </div>
                    <q-btn flat round dense icon="close" v-close-popup />
                </q-card-section>

                <!-- Mandatory Paper Mode Disclaimer -->
                <q-banner dense rounded class="bg-blue-grey-10 text-white q-mx-md q-mb-sm text-caption">
                    <template #avatar><q-icon name="security" color="primary" size="18px" /></template>
                    <strong>RESEARCH & VIRTUAL PAPER MODE:</strong> Method specifications define algorithmic logic for simulation and backtesting.
                    Zero live exchange orders or credentials are used.
                </q-banner>

                <q-card-section class="q-px-md q-pt-none">
                    <!-- Blocker Banner if exists -->
                    <q-banner
                        v-if="selectedMethod.blocker_reason"
                        dense
                        rounded
                        class="bg-orange-10 text-white q-mb-md text-caption"
                    >
                        <template #avatar><q-icon name="warning" color="amber" size="20px" /></template>
                        <div class="text-weight-bold">Data Dependency Diagnostic / Blocker:</div>
                        <div>{{ selectedMethod.blocker_reason }}</div>
                    </q-banner>

                    <!-- Status and Eligibility Badges -->
                    <div class="row q-gutter-sm items-center q-mb-md">
                        <q-badge
                            :color="getMethodStatusBadgeColor(selectedMethod.status)"
                            :label="`Status: ${selectedMethod.status_display || selectedMethod.status}`"
                            class="q-pa-xs"
                        />
                        <q-badge
                            :color="selectedMethod.is_executable ? 'positive' : 'grey-7'"
                            :label="selectedMethod.is_executable ? 'Executable in Algorithm' : 'Specification Catalogued'"
                            class="q-pa-xs"
                        />
                        <q-badge
                            :color="selectedMethod.research_eligible ? 'positive' : 'grey-7'"
                            :label="`Research Eligible: ${selectedMethod.research_eligible ? 'YES' : 'NO'}`"
                            class="q-pa-xs"
                        />
                        <q-badge
                            :color="selectedMethod.shadow_eligible ? 'cyan-8' : 'grey-7'"
                            :label="`Shadow Eligible: ${selectedMethod.shadow_eligible ? 'YES' : 'NO'}`"
                            class="q-pa-xs"
                        />
                        <q-badge
                            :color="selectedMethod.paper_eligible ? 'positive' : 'grey-7'"
                            :label="`PAPER Eligible: ${selectedMethod.paper_eligible ? 'YES' : 'NO'}`"
                            class="q-pa-xs"
                        />
                    </div>

                    <p class="text-body2 text-grey-3 q-mb-md">
                        {{ selectedMethod.description }}
                    </p>

                    <!-- Input & Indicator Requirements -->
                    <div class="row q-col-gutter-md q-mb-md">
                        <div class="col-12 col-md-4">
                            <div class="text-caption text-weight-bold text-grey-4 q-mb-xs">Required Input Feeds:</div>
                            <div class="row q-gutter-xs">
                                <q-badge
                                    v-for="inp in selectedMethod.required_input_data"
                                    :key="inp"
                                    outline
                                    color="info"
                                    :label="inp"
                                />
                                <span v-if="!selectedMethod.required_input_data?.length" class="text-caption text-grey-6">None</span>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="text-caption text-weight-bold text-grey-4 q-mb-xs">Required Indicators:</div>
                            <div class="row q-gutter-xs">
                                <q-badge
                                    v-for="ind in selectedMethod.required_indicators"
                                    :key="ind"
                                    outline
                                    color="secondary"
                                    :label="ind"
                                />
                                <span v-if="!selectedMethod.required_indicators?.length" class="text-caption text-grey-6">None</span>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="text-caption text-weight-bold text-grey-4 q-mb-xs">Supported Market Venues:</div>
                            <div class="row q-gutter-xs">
                                <q-badge
                                    v-for="mkt in selectedMethod.supported_market_types"
                                    :key="mkt"
                                    outline
                                    color="teal"
                                    :label="String(mkt).toUpperCase()"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Parameter Schemas Table -->
                    <div class="text-subtitle2 text-weight-bold text-primary q-mb-xs">Parameter Schemas:</div>
                    <div v-if="selectedMethod.parameters && selectedMethod.parameters.length">
                        <q-markup-table dense flat bordered class="bg-grey-10 text-caption">
                            <thead>
                                <tr>
                                    <th class="text-left">Parameter</th>
                                    <th class="text-center">Type</th>
                                    <th class="text-center">Default</th>
                                    <th class="text-center">Min</th>
                                    <th class="text-center">Max</th>
                                    <th class="text-center">Step</th>
                                    <th class="text-left">Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in selectedMethod.parameters" :key="p.name">
                                    <td class="text-weight-bold text-teal">{{ p.name }}</td>
                                    <td class="text-center"><code>{{ p.type }}</code></td>
                                    <td class="text-center">{{ p.default ?? '—' }}</td>
                                    <td class="text-center">{{ p.min ?? '—' }}</td>
                                    <td class="text-center">{{ p.max ?? '—' }}</td>
                                    <td class="text-center">{{ p.step ?? '—' }}</td>
                                    <td class="text-left">{{ p.description }} {{ p.options ? `[${p.options.join(', ')}]` : '' }}</td>
                                </tr>
                            </tbody>
                        </q-markup-table>
                    </div>
                    <div v-else class="text-caption text-grey-5">
                        No configurable scalar parameters defined for this method.
                    </div>
                </q-card-section>

                <q-separator />
                <q-card-actions align="between">
                    <q-btn flat label="Close" color="grey-5" v-close-popup />
                    <q-btn
                        unelevated
                        color="primary"
                        icon="tune"
                        label="Configure in Visual Builder"
                        @click="loadMethodIntoBuilder(selectedMethod)"
                    />
                </q-card-actions>
            </q-card>
        </q-dialog>
    </q-page>
</template>
<style scoped>
pre {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    max-height: 600px;
    overflow: auto;
}
.method-card {
    background: #181c24;
    border-color: #2a313d;
    transition: all 0.2s ease-in-out;
}
.method-card:hover {
    border-color: #3f4b5e;
}
</style>
