<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { api } from '../services/api';
import { useSettings } from '../stores/settings';

const s = useSettings();

type AuditStatus = {
  id: string; period_days: number; from_at: string; to_at: string;
  status: string; progress_percent: number; archive_bytes: number | null;
  download_url: string | null; error_code: string | null;
};

const active = ref<AuditStatus | null>(null);
const history = ref<AuditStatus[]>([]);
const funnel = ref<any[]>([]);
const loading = ref(false);
const error = ref('');
let disposed = false;
let automaticDownloadId = '';
let polling: ReturnType<typeof setTimeout> | undefined;
const options = [
  { days: 1, label: '24 hours' },
  { days: 7, label: '7 days' },
  { days: 14, label: '14 days' },
  { days: 30, label: '30 days' },
];

function stopPolling() {
  if (polling) clearTimeout(polling);
  polling = undefined;
}

async function refreshFunnel() {
  try {
    const response = await api.get('/audit/strategy-funnel', { params: { days: 7 } });
    funnel.value = response.data.data.strategies || [];
  } catch (exception: any) { error.value = exception.message; }
}

async function refreshHistory() {
  try {
    const response = await api.get('/audit/exports', { params: { per_page: 10 } });
    history.value = response.data.data.data;
  } catch (exception: any) { error.value = exception.message; }
}

async function checkJob(id: string) {
  if (disposed) return;
  try {
    const response = await api.get(`/audit/exports/${id}`);
    if (disposed || active.value?.id !== id) return;
    active.value = response.data.data;
    if (active.value?.status === 'READY' || active.value?.status === 'FAILED' || active.value?.status === 'EXPIRED') {
      await refreshHistory();
      if (active.value?.status === 'READY' && active.value.download_url && automaticDownloadId !== id) {
        automaticDownloadId = id;
        window.location.assign(active.value.download_url);
      }
      return;
    }
    polling = setTimeout(() => checkJob(id), 5000);
  } catch (exception: any) {
    error.value = exception.message;
  }
}

async function start(days: number) {
  stopPolling();
  automaticDownloadId = '';
  loading.value = true;
  error.value = '';
  try {
    const response = await api.post('/audit/exports/quick', { days });
    active.value = response.data.data;
    if (active.value?.status === 'READY' && active.value.download_url) {
      automaticDownloadId = active.value.id;
      // Signed link is short-lived and downloads directly, without buffering the ZIP in JS.
      window.location.assign(active.value.download_url);
    } else if (active.value) {
      polling = setTimeout(() => checkJob(active.value!.id), 3000);
    }
    await refreshHistory();
  } catch (exception: any) {
    error.value = exception.message;
  } finally { loading.value = false; }
}

function download(job: AuditStatus) {
  if (job.download_url) window.location.assign(job.download_url);
}

onMounted(() => {
  refreshFunnel();
  refreshHistory();
});

onBeforeUnmount(() => { disposed = true; stopPolling(); });
</script>

<template>
  <q-page padding>
    <div class="page-title"><div><span class="eyebrow">PAPER / RESEARCH MODE</span><h1>Trading Audit</h1></div></div>
    <p class="text-caption">Generate a private, complete ZIP of CSV files for independent audit. Never includes real orders or credentials.</p>
    <q-banner v-if="error" class="bg-negative text-white q-mb-md">{{ error }}</q-banner>
    <div class="panel q-pa-md q-mb-md">
      <div class="row q-col-gutter-sm">
        <div v-for="option in options" :key="option.days" class="col-6 col-md-3">
          <q-btn class="full-width" no-caps color="primary" icon="download" :label="option.label"
            :disable="loading" :loading="loading" @click="start(option.days)" />
        </div>
      </div>
      <div v-if="active" class="q-mt-md">
        <div class="row items-center justify-between">
          <strong>{{ active.period_days }}-day audit: {{ active.status }}</strong>
          <span>{{ active.progress_percent }}%</span>
        </div>
        <q-linear-progress :value="active.progress_percent / 100" size="8px" class="q-my-sm" />
        <div class="text-caption">UTC: {{ active.from_at }} — {{ active.to_at }}</div>
        <q-banner v-if="active.error_code" class="bg-warning text-dark">{{ active.error_code }}</q-banner>
        <q-btn v-if="active.status === 'READY' && active.download_url" icon="download" color="positive"
          label="Download ZIP" class="q-mt-sm" @click="download(active)" />
      </div>
    </div>
    <div class="panel q-pa-md q-mb-md">
      <div class="row items-center justify-between q-mb-sm"><h2>7-day strategy funnel ({{ s.exchange.toUpperCase() }})</h2>
        <q-btn flat icon="refresh" label="Analyze no-trade strategies" :loading="loading" @click="refreshFunnel" /></div>
      <q-table flat bordered :rows="funnel" row-key="strategy_id" :columns="[
        {name:'strategy',label:'Strategy',field:'strategy_id',align:'left'},
        {name:'buy',label:'BUY',field:'buy'}, {name:'sell',label:'SELL',field:'sell'},
        {name:'mode',label:'Execution mode',field:'execution_mode'},
        {name:'skip',label:'SKIP',field:'skip'}, {name:'approved',label:'Risk OK',field:'risk_approved'},
        {name:'opened',label:'Opened',field:'opened'}, {name:'closed',label:'Closed',field:'closed'},
        {name:'reasons',label:'Top SKIP blockers',field: row => Object.keys(row.primary_skip_blockers || {}).join(', '),align:'left'}
      ]" :pagination="{ rowsPerPage: 10 }" />
    </div>
    <div class="panel q-pa-md">
      <div class="row items-center justify-between q-mb-sm"><h2>Recent exports</h2>
        <q-btn flat icon="refresh" label="Refresh" :loading="loading" @click="refreshHistory" /></div>
      <q-table flat bordered row-key="id" :rows="history" :columns="[
        {name:'period', label:'Period', field:'period_days', align:'left'},
        {name:'status', label:'Status', field:'status', align:'left'},
        {name:'created', label:'From (UTC)', field:'from_at', align:'left'},
        {name:'download', label:'Download', field:'download_url', align:'left'}
      ]" :pagination="{ rowsPerPage: 10 }">
        <template #body-cell-download="props">
          <q-td :props="props"><q-btn dense flat icon="download" label="ZIP" :disable="!props.row.download_url"
            @click="download(props.row)" /></q-td>
        </template>
      </q-table>
    </div>
  </q-page>
</template>
