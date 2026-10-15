<script setup lang="ts">
import { useSettings } from '../stores/settings';
const settings = useSettings();
</script>

<template>
  <q-page padding>
    <div class="page-title"><h1>Interface Settings</h1></div>
    <q-card flat bordered class="form panel">
      <q-select v-model="settings.locale" :options="['ru','en']" label="Language" />
      <q-toggle v-model="settings.dark" label="Dark theme" />
      <q-select v-model="settings.timezone" :options="['local','UTC']" label="Timezone" />
      <q-select v-model="settings.numberLocale" :options="['system','ru-RU','en-US']" label="Number format" />
      <q-toggle v-model="settings.autoRefresh" label="Auto refresh" />
      <q-input v-model="settings.account" label="Paper account UUID" />
      <q-select v-model="settings.preferredSymbol" :options="['BTCUSDT','ETHUSDT']" label="Preferred symbol" />
      <q-select v-model="settings.preferredInterval" :options="['5','15','60']" label="Preferred timeframe" />
      <q-banner>Strategy, RiskEngine and portfolio state are read-only here.</q-banner>
    </q-card>
  </q-page>
</template>
