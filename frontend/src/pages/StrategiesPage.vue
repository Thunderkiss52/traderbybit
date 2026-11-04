<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { api } from "../services/api";
import { emaDefinition, type BuilderForm, type Definition } from "../strategyBuilder/definition.js";
interface Version { id: number; strategy_id: string; version: number; status: string; checksum: string; definition: string | Definition }
const token = ref("");
const timeframeOptions = ["5", "15", "30", "60"];
const versions = ref<Version[]>([]);
const builtin = ref<Array<{id:string;version:string;description:string}>>([]);
const page = ref(1); const lastPage = ref(1);
const error = ref(""); const busy = ref(false); const message = ref("");
const selected = ref<number | null>(null); const history = ref<unknown>(null);
const description = ref("Create a strategy for BTCUSDT. Enter long when EMA 20 crosses above EMA 50 and RSI below 65. Stop Loss 1.5 ATR. Take Profit 3 ATR. Risk 0.5%. Timeframe 15m.");
const form = ref<BuilderForm>({id:"user_ema_rsi",version:1,symbol:"BTCUSDT",exchange:"bybit",timeframe:"15",fast:20,slow:50,rsi:65,stop:1.5,take:3,risk:0.5,timeout:48,cooldown:0});
const text = ref(JSON.stringify(emaDefinition(form.value),null,2));
const confirmed = ref(false); const validated = ref(false);
const from = ref(""); const to = ref(""); const capital = ref("10000");
const preview = computed(() => {try {return JSON.parse(text.value) as Definition;}catch{return null;}});
watch(text, () => {confirmed.value=false;validated.value=false;selected.value=null;});
watch(form, () => {text.value=JSON.stringify(emaDefinition(form.value),null,2);}, {deep:true});
const headers = () => ({"X-Research-Token":token.value});
async function action(fn:()=>Promise<void>) {busy.value=true;error.value="";message.value="";try{await fn();}catch(e){error.value=e instanceof Error?e.message:"Request failed";}finally{busy.value=false;}}
async function load() {const r=await api.get("/strategies",{headers:headers(),params:{page:page.value}});versions.value=r.data.data.data;lastPage.value=r.data.data.last_page;builtin.value=r.data.builtin;}
async function parse() {await action(async()=>{const r=await api.post("/strategies/parse",{description:description.value},{headers:headers()});text.value=JSON.stringify(r.data.data.definition,null,2);message.value=r.data.data.assumptions.join("; ");});}
async function validate() {await action(async()=>{await api.post("/strategies/validate",{definition:preview.value},{headers:headers()});validated.value=true;message.value="Definition validated for research. PAPER parity remains unverified.";});}
async function save() {await action(async()=>{const r=await api.post("/strategies",{definition:preview.value,interpretation_confirmed:confirmed.value},{headers:headers()});selected.value=r.data.data.id;await load();message.value="Immutable version saved.";});}
function edit(row:Version) {text.value=JSON.stringify(typeof row.definition==="string"?JSON.parse(row.definition):row.definition,null,2);history.value=null;}
async function inspect(row:Version) {await action(async()=>{const r=await api.get(`/strategies/${row.id}`,{headers:headers()});history.value=r.data;selected.value=row.id;});}
async function research() {await action(async()=>{const d=preview.value;if(!d||!selected.value)throw new Error("Select a saved version first");const r=await api.post(`/strategies/${selected.value}/backtests`,{exchange:d.exchanges[0],symbol:d.symbols[0],from:from.value,to:to.value,capital:capital.value},{headers:headers()});history.value=r.data;message.value="Research result. Funding is not modeled; PAPER approval remains blocked.";});}
async function approval() {await action(async()=>{await api.post(`/strategies/${selected.value}/approve-paper`,{},{headers:headers()});});}
async function results() {await action(async()=>{history.value=(await api.get(`/strategies/${selected.value}/backtests`,{headers:headers()})).data;});}
</script>

<template>
  <q-page padding>
    <h1 class="text-h4">Strategy management</h1>
    <q-banner class="bg-warning text-black q-mb-md">RESEARCH / PAPER MODE ONLY — NOT FINANCIAL ADVICE. Real exchange ordering disabled. Custom DSL PAPER activation is blocked pending execution parity.</q-banner>
    <q-input v-model="token" label="Research owner token" type="password" autocomplete="off" />
    <q-btn label="Load strategies" :loading="busy" @click="action(load)" />
    <q-banner v-if="error" class="bg-negative text-white q-my-md" role="alert">{{ error }}</q-banner>
    <q-banner v-if="message" class="bg-info q-my-md">{{ message }}</q-banner>
    <q-expansion-item label="Built-in strategies (existing research UI preserved)">
      <q-list><q-item v-for="s in builtin" :key="s.id"><q-item-section>{{ s.id }} · {{ s.version }} — {{ s.description }}</q-item-section></q-item></q-list>
      <q-btn to="/research" label="Existing strategy research" flat />
    </q-expansion-item>
    <h2 class="text-h6">Immutable custom versions</h2>
    <p v-if="!versions.length && !busy">No custom versions loaded.</p>
    <q-markup-table v-else><thead><tr><th>Strategy</th><th>Version</th><th>Status</th><th>Actions</th></tr></thead><tbody><tr v-for="s in versions" :key="s.id"><td>{{ s.strategy_id }}</td><td>{{ s.version }}</td><td>{{ s.status }}</td><td><q-btn label="Edit / clone" flat @click="edit(s)" /><q-btn label="Inspect / select" flat @click="inspect(s)" /></td></tr></tbody></q-markup-table>
    <q-pagination v-model="page" :max="lastPage" @update:model-value="action(load)" />
    <h2 class="text-h6">Visual EMA / RSI builder</h2>
    <div class="row q-col-gutter-sm">
      <q-input v-model="form.id" class="col-6" label="Strategy ID (user_ prefix)" /><q-input v-model.number="form.version" class="col-6" type="number" label="New immutable version" />
      <q-input v-model="form.symbol" class="col-4" label="Symbol" /><q-input v-model="form.exchange" class="col-4" label="Exchange" /><q-select v-model="form.timeframe" class="col-4" :options="timeframeOptions" label="Timeframe minutes" />
      <q-input v-model.number="form.fast" class="col-4" type="number" label="Fast EMA" /><q-input v-model.number="form.slow" class="col-4" type="number" label="Slow EMA" /><q-input v-model.number="form.rsi" class="col-4" type="number" label="RSI(14) below" />
      <q-input v-model.number="form.stop" class="col-4" type="number" label="Stop ATR(14)" /><q-input v-model.number="form.take" class="col-4" type="number" label="Target ATR(14)" /><q-input v-model.number="form.risk" class="col-4" type="number" label="Risk % (max 0.5)" />
      <q-input v-model.number="form.timeout" class="col-6" type="number" label="Timeout bars" /><q-input v-model.number="form.cooldown" class="col-6" type="number" label="Signal cooldown bars" />
    </div>
    <p>BUY when the fast EMA crosses above the slow EMA and RSI(14) is below the threshold. Stops and targets use ATR(14) at decision time. Signals fill on the next bar.</p>
    <h2 class="text-h6">English / Russian authoring</h2>
    <q-input v-model="description" type="textarea" label="Supported grammar description" /><q-btn label="Interpret description" :disable="busy" @click="parse" />
    <h2 class="text-h6">Interpreted rules / advanced DSL</h2>
    <q-input v-model="text" type="textarea" autogrow label="Versioned strategy JSON" input-class="text-monospace" />
    <q-checkbox v-model="confirmed" label="I confirm these interpreted rules and assumptions" />
    <q-btn label="Validate" :disable="busy || !preview" @click="validate" /><q-btn label="Save immutable version" :disable="busy || !confirmed || !validated" @click="save" />
    <h2 class="text-h6">Historical research (saved version required)</h2>
    <q-input v-model="from" label="From UTC (ISO 8601)" /><q-input v-model="to" label="To UTC (ISO 8601), maximum 7 days" /><q-input v-model="capital" label="Virtual starting capital" />
    <q-btn label="Run pinned Go backtest" :disable="busy || !selected" @click="research" /><q-btn label="Historical results" :disable="busy || !selected" @click="results" /><q-btn label="Submit for PAPER approval" :disable="busy || !selected" @click="approval" />
    <p>Complete four-timeframe coverage and warmup are required. Funding and partial exits are unsupported. Autonomous optimization is not yet connected for custom DSL versions.</p>
    <pre v-if="history" class="result">{{ JSON.stringify(history,null,2) }}</pre>
  </q-page>
</template>
<style scoped>.result {white-space:pre-wrap;overflow-wrap:anywhere;max-height:600px;overflow:auto;}</style>
