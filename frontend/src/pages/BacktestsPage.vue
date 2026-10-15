<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { get, startBacktest } from '../services/api';

const rows = ref<any[]>([]);
const form = ref({
  symbols: ['BTCUSDT'], from: '', to: '', mode: 'split',
  starting_balance: '', strategy_version: '', execution_model_version: '',
});
const token = ref('');
const message = ref('');
const loading = ref(false);

const load = async () => {
  const response: any = await get('/backtests');
  rows.value = response.data;
};
const submit = async () => {
  loading.value = true;
  try {
    await startBacktest(form.value, token.value);
    message.value = 'Research job queued';
    await load();
  } catch (exception: any) {
    message.value = exception.message;
  } finally {
    loading.value = false;
  }
};
onMounted(async () => {
  const dashboard: any = await get('/dashboard');
  Object.assign(form.value, dashboard.research_settings || {});
  await load();
});
</script>

<template>
  <q-page padding>
    <div class="page-title"><div><span class="eyebrow">HISTORICAL LAB</span><h1>Backtests</h1></div></div>
    <q-card flat bordered class="panel">
      <h2>New research run</h2>
      <div class="form">
        <q-select v-model="form.symbols" multiple :options="['BTCUSDT','ETHUSDT']" label="Symbols" />
        <q-input v-model="form.from" type="date" label="From" />
        <q-input v-model="form.to" type="date" label="To" />
        <q-select v-model="form.mode" :options="['split','walk_forward']" label="Mode" />
        <q-input v-model="form.starting_balance" readonly label="Starting balance (USDT)" />
        <q-input v-model="form.strategy_version" readonly label="Strategy version" />
        <q-input v-model="form.execution_model_version" readonly label="Execution model" />
        <q-input v-model="token" type="password" label="Local research token" autocomplete="off" />
        <q-btn color="primary" label="Queue research backtest" :loading="loading" @click="submit" />
      </div>
      <p>{{ message }}</p>
    </q-card>
    <q-table flat bordered :rows="rows" row-key="id" @row-click="(_, row) => $router.push(`/backtests/${row.id}`)" />
  </q-page>
</template>
