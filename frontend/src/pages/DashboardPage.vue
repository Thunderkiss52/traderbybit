<script setup lang="ts">
import { onBeforeUnmount, ref, shallowRef } from 'vue';
import { storeToRefs } from 'pinia';
import { get } from '../services/api';
import { usePolling } from '../composables/usePolling';
import { useSettings } from '../stores/settings';
import MetricCard from '../components/MetricCard.vue';
import SignalCard from '../components/SignalCard.vue';
import EmptyState from '../components/EmptyState.vue';

const data = shallowRef<any>({ market: [], signals: [], positions: [], portfolio: null, performance: null, recent_decisions: [], skip_reasons_24h: {} });
const error = ref('');
const controller = new AbortController();
const { autoRefresh } = storeToRefs(useSettings());
const { loading, connected } = usePolling(async () => {
  try {
    data.value = await get('/dashboard', undefined, controller.signal);
    error.value = '';
  } catch (exception: any) {
    error.value = exception.message;
    throw exception;
  }
}, 5000, autoRefresh);
onBeforeUnmount(() => controller.abort());
</script>

<template>
  <q-page padding>
    <div class="page-title">
      <div><span class="eyebrow">FORWARD PAPER TRADING</span><h1>Market & Virtual Portfolio</h1></div>
      <q-badge :color="connected ? 'positive' : 'warning'">{{ connected ? 'SYNCED' : 'RECONNECTING' }}</q-badge>
    </div>
    <q-banner v-if="error" class="bg-negative text-white">{{ error }}</q-banner>
    <q-inner-loading :showing="loading && !data.market.length" />
    <section>
      <h2>Market Overview</h2>
      <div class="grid">
        <q-card v-for="market in data.market" :key="market.symbol" flat bordered>
          <b>{{ market.symbol }}</b><h3>{{ market.ask }}</h3>
          <div class="muted">Spread {{ Number(market.ask) - Number(market.bid) }} · Funding {{ market.funding_rate ?? '—' }}</div>
          <router-link :to="`/market/${market.symbol}`">Open analysis →</router-link>
        </q-card>
        <EmptyState v-if="!data.market.length" />
      </div>
    </section>
    <section>
      <h2>Paper Portfolio · Simulated USDT</h2>
      <q-banner class="bg-grey-9 text-white q-mb-md">Estimated paper results, not actual exchange profits. Backtest performance is reported separately.</q-banner>
      <div v-if="data.portfolio" class="metrics">
        <MetricCard label="Starting Balance (USDT)" :value="data.portfolio.starting_balance" />
        <MetricCard label="Current Balance (USDT)" :value="data.portfolio.current_balance" />
        <MetricCard label="Current Equity (USDT)" :value="data.portfolio.current_equity" />
        <MetricCard label="Total Return (%)" :value="data.portfolio.total_return_percent" />
        <MetricCard label="Total Net PnL (USDT)" :value="data.portfolio.total_net_pnl" />
        <MetricCard label="Realized PnL (USDT)" :value="data.portfolio.realized_pnl" />
        <MetricCard label="Unrealized PnL (USDT)" :value="data.portfolio.unrealized_pnl" />
        <MetricCard label="Available Capital (USDT)" :value="data.portfolio.available_virtual_capital" />
        <MetricCard label="Reserved Risk (USDT)" :value="data.portfolio.reserved_risk" />
        <MetricCard label="Max Drawdown (%)" :value="data.portfolio.maximum_drawdown_percent" />
        <MetricCard label="Open Positions" :value="data.portfolio.open_positions" />
        <MetricCard label="Pending Virtual Entries" :value="data.portfolio.pending_entry_signals" />
      </div>
      <EmptyState v-else />
    </section>
    <section>
      <h2>Latest BUY / SELL Signals</h2>
      <div class="grid">
        <SignalCard v-for="signal in data.signals" :key="signal.id" :signal="signal" />
        <EmptyState v-if="!data.signals.length" />
      </div>
    </section>
    <section>
      <h2>Recent Strategy Decisions — Including SKIP</h2>
      <q-list bordered separator v-if="data.recent_decisions?.length">
        <q-item v-for="decision in data.recent_decisions" :key="decision.id">
          <q-item-section side>
            <q-badge :color="decision.side === 'BUY' ? 'positive' : decision.side === 'SELL' ? 'negative' : 'grey'">{{ decision.side }}</q-badge>
          </q-item-section>
          <q-item-section>
            <q-item-label>{{ decision.symbol }} · {{ decision.decision_time }}</q-item-label>
            <q-item-label caption>{{ decision.reasons?.join(', ') || 'No reason recorded' }}</q-item-label>
          </q-item-section>
        </q-item>
      </q-list>
      <EmptyState v-else />
      <h3>Why were signals skipped in the last 24 hours?</h3>
      <q-list v-if="Object.keys(data.skip_reasons_24h || {}).length" dense>
        <q-item v-for="(count, reason) in data.skip_reasons_24h" :key="reason">
          <q-item-section>{{ reason }}</q-item-section><q-item-section side>{{ count }}</q-item-section>
        </q-item>
      </q-list>
      <div v-else class="muted">No SKIP decisions recorded in the last 24 hours.</div>
    </section>
    <section>
      <h2>Forward Paper Performance</h2>
      <div class="metrics">
        <MetricCard label="BUY Decisions" :value="data.performance?.buy_signals" />
        <MetricCard label="SELL Decisions" :value="data.performance?.sell_signals" />
        <MetricCard label="SKIP Decisions" :value="data.performance?.skip_signals" />
        <MetricCard label="Win Rate (%)" :value="data.performance?.win_rate" />
        <MetricCard label="Profit Factor" :value="data.performance?.profit_factor" />
        <MetricCard label="Average R" :value="data.performance?.average_r" />
        <MetricCard label="Closed-Trade Net PnL (USDT)" :value="data.performance?.net_pnl" />
        <MetricCard label="Closed Trades" :value="data.performance?.closed_positions" />
      </div>
      <div class="muted q-mt-sm">Closed-trade PnL and total portfolio PnL differ while positions are open or partially closed.</div>
    </section>
  </q-page>
</template>
