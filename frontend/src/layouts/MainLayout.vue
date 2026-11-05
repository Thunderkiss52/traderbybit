<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useQuasar } from 'quasar';
import { useI18n } from 'vue-i18n';
import { useSettings } from '../stores/settings';
import ResearchBanner from '../components/ResearchBanner.vue';

const drawer = ref(false);
const online = ref(window.navigator.onLine);
const s = useSettings();
const q = useQuasar();
const { locale } = useI18n();

const syncOnline = () => {
  online.value = window.navigator.onLine;
};

watch(() => s.dark, (v) => q.dark.set(v), { immediate: true });
watch(() => s.locale, (v) => { locale.value = v; }, { immediate: true });

onMounted(() => {
  window.addEventListener('online', syncOnline);
  window.addEventListener('offline', syncOnline);
  void s.loadExchanges();
});

onBeforeUnmount(() => {
  window.removeEventListener('online', syncOnline);
  window.removeEventListener('offline', syncOnline);
});

function statusColor(status: string): string {
  switch (status) {
    case 'ACTIVE_PAPER': return 'positive';
    case 'PAPER_ELIGIBLE': return 'info';
    case 'SHADOW_ELIGIBLE': return 'teal';
    case 'RESEARCH_ENABLED': return 'primary';
    case 'CONNECTED_PUBLIC_DATA': return 'secondary';
    case 'OFFLINE_DATA_SOURCE': return 'grey-7';
    case 'BLOCKED': return 'negative';
    default: return 'grey-8';
  }
}

const links = [
  ['/strategies', 'account_tree', 'Strategies'],
  ['/dashboard', 'space_dashboard', 'dashboard'],
  ['/market/BTCUSDT', 'candlestick_chart', 'Market'],
  ['/signals', 'bolt', 'signals'],
  ['/positions', 'account_balance_wallet', 'positions'],
  ['/journal', 'menu_book', 'journal'],
  ['/audit', 'download', 'Audit'],
  ['/exchanges', 'currency_exchange', 'Exchanges'],
  ['/statistics', 'monitoring', 'statistics'],
  ['/research/health', 'health_and_safety', 'Health'],
  ['/research/opportunities', 'troubleshoot', 'Opportunities'],
  ['/research/replay', 'replay', 'Replay'],
  ['/research/calibration', 'tune', 'Calibration'],
  ['/research/intelligence', 'insights', 'Decisions'],
  ['/research/learning', 'psychology', 'Learning'],
  ['/research', 'biotech', 'research'],
  ['/research/patterns', 'hub', 'Patterns'],
  ['/research/profitability', 'payments', 'Доходность'],
  ['/backtests', 'science', 'backtests'],
  ['/risk', 'shield', 'risk'],
  ['/quote-integrity', 'verified_user', 'Quote Integrity'],
  ['/settings', 'settings', 'settings'],
];
</script>

<template>
  <q-layout view="hHh LpR fFf">
    <q-header class="top">
      <q-toolbar>
        <q-btn flat round icon="menu" @click="drawer = !drawer" />
        <q-toolbar-title><b>VECTOR</b> Quant Research</q-toolbar-title>

        <div class="row items-center q-gutter-sm">
          <!-- Dynamic Global Exchange Selector Dropdown -->
          <q-btn-dropdown
            dense
            no-caps
            unelevated
            rounded
            color="grey-9"
            text-color="grey-2"
            icon="currency_exchange"
            :label="s.selectedExchangeName"
            data-testid="global-exchange-dropdown"
          >
            <q-list style="min-width: 240px">
              <q-item-label header class="text-weight-bold">Registered Exchanges</q-item-label>

              <q-item
                v-for="ex in s.availableExchanges"
                :key="ex.id"
                clickable
                v-close-popup
                @click="s.exchange = ex.id"
                :active="s.exchange === ex.id"
                active-class="bg-blue-grey-10"
              >
                <q-item-section avatar style="min-width: 32px">
                  <q-icon v-if="s.exchange === ex.id" name="check" color="primary" />
                </q-item-section>
                <q-item-section>
                  <q-item-label class="text-weight-medium">{{ ex.name }}</q-item-label>
                  <q-item-label caption class="text-grey-5">{{ ex.id }}</q-item-label>
                </q-item-section>
                <q-item-section side>
                  <q-badge :color="statusColor(ex.lifecycle_status)" :label="ex.lifecycle_status" />
                </q-item-section>
              </q-item>

              <q-separator class="q-my-xs" />

              <q-item
                clickable
                v-close-popup
                @click="s.exchange = 'all'"
                :active="s.exchange === 'all'"
                active-class="bg-blue-grey-10"
              >
                <q-item-section avatar style="min-width: 32px">
                  <q-icon v-if="s.exchange === 'all'" name="check" color="primary" />
                  <q-icon v-else name="public" color="grey-5" />
                </q-item-section>
                <q-item-section>
                  <q-item-label>All Exchanges</q-item-label>
                  <q-item-label caption class="text-grey-5">Cross-exchange aggregated context</q-item-label>
                </q-item-section>
              </q-item>

              <q-separator class="q-my-xs" />

              <q-item clickable v-close-popup to="/exchanges">
                <q-item-section avatar style="min-width: 32px">
                  <q-icon name="settings" color="grey-4" />
                </q-item-section>
                <q-item-section>
                  <q-item-label>Manage Exchanges</q-item-label>
                </q-item-section>
              </q-item>
            </q-list>
          </q-btn-dropdown>

          <!-- Current Exchange Status Indicator -->
          <q-badge
            v-if="s.selectedExchangeStatus !== 'ALL'"
            :color="statusColor(s.selectedExchangeStatus)"
            :label="s.selectedExchangeStatus"
            class="q-pa-xs text-bold"
          />

          <!-- Market Data Connectivity Indicator -->
          <q-chip dense :color="online ? 'positive' : 'negative'" text-color="white" icon="wifi">
            {{ online ? 'DATA LIVE' : 'DATA OFFLINE' }}
          </q-chip>
        </div>
      </q-toolbar>
    </q-header>

    <q-drawer v-model="drawer" show-if-above :width="230" bordered>
      <div class="brand">VQ<span>TERMINAL</span></div>
      <q-list>
        <q-item v-for="l in links" :key="l[0]" clickable :to="l[0]" exact>
          <q-item-section avatar><q-icon :name="l[1]" /></q-item-section>
          <q-item-section>{{ $te(l[2]) ? $t(l[2]) : l[2] }}</q-item-section>
        </q-item>
      </q-list>
    </q-drawer>

    <q-page-container>
      <ResearchBanner />
      <router-view />
    </q-page-container>
  </q-layout>
</template>
