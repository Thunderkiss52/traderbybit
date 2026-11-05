<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from "vue";
import { api } from "../services/api";
import VisualDefinitionEditor from "../components/strategyBuilder/VisualDefinitionEditor.vue";
import StrategyRunResult from "../components/strategyBuilder/StrategyRunResult.vue";
import {
    emaDefinition,
    definitionDiff,
    type Definition,
} from "../strategyBuilder/definition.js";
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
        <h2 class="text-h6">Visual rule builder</h2>
        <VisualDefinitionEditor
            v-if="preview"
            :model-value="preview"
            @update:model-value="edit"
        />
        <h2 class="text-h6">English / Russian authoring</h2>
        <q-input
            v-model="description"
            type="textarea"
            label="Supported grammar description"
        />
        <p>
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
        <q-btn
            label="Interpret description"
            :disable="busy || (hosted && !hostedConsent)"
            @click="parse"
        />
        <q-expansion-item label="Advanced versioned JSON"
            ><q-input
                v-model="text"
                type="textarea"
                autogrow
                label="Versioned strategy JSON"
                input-class="text-monospace"
        /></q-expansion-item>
        <q-btn
            label="Save durable draft"
            :disable="busy || !preview"
            @click="saveDraft"
        />
        <q-checkbox
            v-model="confirmed"
            label="I confirm these interpreted rules and assumptions"
        />
        <q-btn label="Validate" :disable="busy || !preview" @click="validate" />
        <q-btn
            label="Publish confirmed draft"
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
            :disable="busy || !confirmed || !validated"
            @click="save"
        />
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
    </q-page>
</template>
<style scoped>
pre {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    max-height: 600px;
    overflow: auto;
}
</style>
