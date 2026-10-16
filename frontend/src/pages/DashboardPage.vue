<script setup lang="ts">
import { onBeforeUnmount, ref, shallowRef } from 'vue';
import { storeToRefs } from 'pinia';
import { get } from '../services/api';
import { usePolling } from '../composables/usePolling';
import { useSettings } from '../stores/settings';
import MetricCard from '../components/MetricCard.vue';
import SignalCard from '../components/SignalCard.vue';
import EmptyState from '../components/EmptyState.vue';
import DailyPerformance from '../components/DailyPerformance.vue';

const data = shallowRef<any>({ market: [], signals: [], positions: [], portfolio: null, performance: null, recent_decisions: [], skip_reasons_24h: {}, strategy_observations_24h: [] });
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

const evidence = (value: unknown) => value === null || value === undefined ? '—' : `${value}/100 evidence`;
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
      <h2>Independent Strategy Paper Portfolios</h2>
      <div class="muted q-mb-sm">Each enabled strategy has isolated forward-only virtual accounting. No historical replay is mixed into these balances.</div>
      <q-markup-table flat bordered>
        <thead><tr><th class="text-left">Strategy</th><th>Status</th><th>Equity</th><th>Realized PnL</th><th>Unrealized PnL</th></tr></thead>
        <tbody><tr v-for="portfolio in data.strategy_portfolios || []" :key="portfolio.id">
          <td class="text-left"><b>{{ portfolio.strategy_id }}</b><div class="muted">v{{ portfolio.strategy_version }} · {{ portfolio.mode }}</div></td>
          <td class="text-center">{{ portfolio.status }}</td><td class="text-right">{{ portfolio.current_equity }}</td>
          <td class="text-right">{{ portfolio.realized_pnl }}</td><td class="text-right">{{ portfolio.unrealized_pnl }}</td>
        </tr></tbody>
      </q-markup-table>
      <div class="muted q-mt-sm">Execution failures (24h): {{ data.paper_execution_health?.execution_failures_24h ?? 0 }} · Latest evaluation: {{ data.paper_execution_health?.latest_strategy_evaluation_at ?? '—' }}</div>
    </section>
    <section>
      <h2>All Strategy Decisions · Last 24 Hours</h2>
      <q-banner class="bg-grey-9 text-white q-mb-md">All enabled strategies independently evaluate BTC and ETH every closed 15m candle. Decision counts are observations, not profitability; validated experiment and isolated forward-paper results remain on the Research page.</q-banner>
      <q-markup-table flat bordered wrap-cells>
        <thead><tr><th class="text-left">Strategy</th><th v-for="symbol in ['BTCUSDT', 'ETHUSDT']" :key="symbol" class="text-left">{{ symbol }}</th><th>Combined B / S / K</th><th class="text-left">Top SKIP reasons</th></tr></thead>
        <tbody>
          <tr v-for="strategy in data.strategy_observations_24h" :key="strategy.strategy_id">
            <td class="text-left"><b>{{ strategy.strategy_id }}</b><div class="muted">v{{ strategy.strategy_version }} · 1h / 30m / 15m / 5m</div></td>
            <td v-for="market in strategy.symbols" :key="market.symbol" class="text-left">
              <div><span class="text-positive">B {{ market.buy }}</span> · <span class="text-negative">S {{ market.sell }}</span> · K {{ market.skip }}</div>
              <template v-if="market.latest">
                <b>{{ market.latest.side }}</b><div class="muted">{{ market.latest.decision_time }}</div>
                <div v-if="market.latest.analysis?.level_interaction" class="muted">
                  Momentum {{ market.latest.analysis.level_interaction.momentum_side }} ·
                  {{ market.latest.analysis.level_interaction.selected_event || 'between levels' }}
                  {{ evidence(market.latest.analysis.level_interaction.selected_evidence_score) }}
                </div>
                <div v-if="market.latest.analysis?.level_interaction?.historical_forecast" class="muted">
                  Probability {{ market.latest.analysis.level_interaction.historical_forecast.validation_status }} · N={{ market.latest.analysis.level_interaction.historical_forecast.sample_size }}
                </div>
              </template>
              <span v-else class="muted">Waiting for next closed 15m candle</span>
            </td>
            <td class="text-center">{{ strategy.buy }} / {{ strategy.sell }} / {{ strategy.skip }}<div class="muted">{{ strategy.total }} total</div></td>
            <td class="text-left">{{ Object.entries(strategy.top_skip_reasons || {}).map(([reason, count]) => `${reason} (${count})`).join(', ') || '—' }}</td>
          </tr>
        </tbody>
      </q-markup-table>
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
            <q-item-label v-if="decision.level_interaction" caption class="q-mt-xs">
              Support {{ decision.level_interaction.support ?? '—' }} · Resistance {{ decision.level_interaction.resistance ?? '—' }} ·
              Directional evidence: bullish {{ evidence(decision.level_interaction.bullish_directional_evidence_score) }}, bearish {{ evidence(decision.level_interaction.bearish_directional_evidence_score) }}
            </q-item-label>
            <q-item-label v-if="decision.level_interaction" caption>
              Momentum {{ decision.level_interaction.momentum_side }} ({{ decision.level_interaction.momentum_strength }}) · Selected {{ decision.level_interaction.selected_event || 'no confirmed interaction' }}
            </q-item-label>
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
    <DailyPerformance />
  </q-page>
</template>
