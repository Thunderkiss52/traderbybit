<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, shallowRef, computed, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { get } from '../services/api';
import { usePolling } from '../composables/usePolling';
import { useSettings } from '../stores/settings';
import { formatMoney, formatPrice, formatPercent, formatSpread } from '../utils';
import MetricCard from '../components/MetricCard.vue';
import SignalCard from '../components/SignalCard.vue';
import EmptyState from '../components/EmptyState.vue';
import DailyPerformance from '../components/DailyPerformance.vue';

const data = shallowRef<any>({ market: [], signals: [], positions: [], portfolio: null, performance: null, recent_decisions: [], skip_reasons_24h: {}, strategy_observations_24h: [], strategy_portfolios: [] });
const error = ref('');
const exchangeComparison = shallowRef<any>(null);
const exchangeError = ref('');
const settings = useSettings();
const selectedExchange = computed({
  get: () => settings.exchange,
  set: (val: string) => settings.exchange = val,
});
const selectedAccounts = computed(() => {
  const accounts = exchangeComparison.value?.accounts || [];
  if (selectedExchange.value === 'all') {
    return accounts;
  }
  return accounts.filter((row: any) => row.exchange_id === selectedExchange.value);
});

const accountsOverviewText = computed(() => {
  const accounts = exchangeComparison.value?.accounts || data.value?.accounts || [];
  const count = accounts.length || (data.value?.portfolio?.accounts_count ?? 26);
  if (selectedExchange.value === 'all') {
    if (count === 26 || accounts.length === 0) {
      return '26 Independent PAPER Accounts (13 Bybit + 13 Binance)';
    }
    const bybitCount = accounts.filter((a: any) => a.exchange_id === 'bybit').length || 13;
    const binanceCount = accounts.filter((a: any) => a.exchange_id === 'binance').length || 13;
    const otherCount = count - bybitCount - binanceCount;
    if (otherCount > 0) {
      return `${count} Independent PAPER Accounts (${bybitCount} Bybit + ${binanceCount} Binance + ${otherCount} other)`;
    }
    return `${count} Independent PAPER Accounts (${bybitCount} Bybit + ${binanceCount} Binance)`;
  }
  const exName = settings.selectedExchangeName || selectedExchange.value.toUpperCase();
  const currentCount = selectedAccounts.value.length || (data.value?.portfolio?.accounts_count ?? 13);
  return `${currentCount} Independent PAPER Accounts (${exName})`;
});

const comparisonSubtitle = computed(() => {
  const accounts = exchangeComparison.value?.accounts || [];
  const uniqueStrategies = new Set(accounts.map((a: any) => a.strategy_id || a.primary_strategy_id)).size || (data.value?.strategy_portfolios?.length ?? 13);
  return `${uniqueStrategies} PRIMARY strategy families with isolated accounts per exchange. Never merged automatically. Binance paper execution remains blocked until separately verified.`;
});

const exchangeToggleOptions = computed(() => {
  const options = [{ label: 'All Exchanges', value: 'all' }];
  for (const ex of settings.availableExchanges) {
    options.push({ label: ex.name, value: ex.id });
  }
  return options;
});
const crossExchangeSummary = computed(() => {
  const accounts = exchangeComparison.value?.accounts || [];
  let starting = 0;
  let equity = 0;
  let realized = 0;
  let unrealized = 0;
  for (const acc of accounts) {
    if (acc.starting_balance) starting += Number(acc.starting_balance) || 0;
    if (acc.current_equity) equity += Number(acc.current_equity) || 0;
    if (acc.realized_pnl) realized += Number(acc.realized_pnl) || 0;
    if (acc.unrealized_pnl) unrealized += Number(acc.unrealized_pnl) || 0;
  }
  return {
    accountsCount: accounts.length,
    startingBalance: starting.toFixed(2),
    currentEquity: equity.toFixed(2),
    realizedPnl: (realized >= 0 ? '+' : '') + realized.toFixed(2),
    unrealizedPnl: (unrealized >= 0 ? '+' : '') + unrealized.toFixed(2),
  };
});
async function loadExchangeComparison() {
  try {
    exchangeComparison.value = await get<any>('/exchanges/comparison');
    exchangeError.value = '';
  } catch {
    exchangeError.value = 'Exchange comparison unavailable';
  }
}
onMounted(loadExchangeComparison);

const selectedAccount = ref<string>(settings.account || 'all');
const accountOptions = computed(() => {
  const exLabel = selectedExchange.value === 'all' ? 'ALL EXCHANGES' : selectedExchange.value.toUpperCase();
  const list = data.value?.accounts || selectedAccounts.value || [];
  return [
    { label: `All Accounts (Aggregate Portfolio · ${exLabel})`, value: 'all' },
    ...list.map((a: any) => ({
      label: `${a.name || a.strategy_id} (${a.strategy_id || a.primary_strategy_id}) [${a.status || 'ACTIVE'}]${selectedExchange.value === 'all' && a.exchange_id ? ' · ' + a.exchange_id.toUpperCase() : ''}`,
      value: a.id || a.account_id,
    })),
  ];
});

let reqVersion = 0;
let dashboardController: AbortController | undefined;

async function loadDashboard() {
  dashboardController?.abort();
  dashboardController = new AbortController();
  const current = ++reqVersion;
  try {
    const params = selectedAccount.value && selectedAccount.value !== 'all'
      ? { account: selectedAccount.value }
      : { account: 'all' };
    const res = await get<any>('/dashboard', params, dashboardController.signal);
    if (current === reqVersion && res) {
      data.value = res;
      error.value = '';
    }
  } catch (exception: any) {
    if (current === reqVersion && exception.name !== 'CanceledError') {
      error.value = exception.message;
    }
  }
}

watch(selectedAccount, (val: string) => {
  settings.account = val === 'all' ? '' : val;
  loadDashboard();
});

watch(() => settings.account, (val: string) => {
  if (val && val !== selectedAccount.value) {
    selectedAccount.value = val;
  } else if (!val && selectedAccount.value !== 'all') {
    selectedAccount.value = 'all';
  }
});

watch(() => settings.exchange, () => {
  selectedAccount.value = 'all';
  loadDashboard();
  loadExchangeComparison();
});

const { autoRefresh } = storeToRefs(useSettings());
const { loading, connected } = usePolling(loadDashboard, 5000, autoRefresh);
onBeforeUnmount(() => dashboardController?.abort());

const evidence = (value: unknown) => value === null || value === undefined ? '—' : `${value}/100 evidence`;

const systemStatus = computed(() => {
  if (!connected.value) return { color: 'warning', label: 'RECONNECTING' };
  const health = data.value?.paper_execution_health;
  if (!health) return { color: 'positive', label: 'CONNECTED' };
  if (health.system_status === 'HEALTHY') return { color: 'positive', label: 'OPERATIONAL' };
  if (health.system_status === 'DEGRADED') return { color: 'warning', label: 'DEGRADED' };
  if (health.system_status === 'SCHEDULER_STALLED') return { color: 'negative', label: 'SCHEDULER STALLED' };
  if (health.system_status === 'MARKET_DATA_STALLED') return { color: 'warning', label: 'MARKET DATA STALLED' };
  if (health.system_status === 'AWAITING_ACTIVATION') return { color: 'info', label: 'AWAITING ACTIVATION' };
  if (health.system_status === 'AWAITING_FIRST_CYCLE') return { color: 'info', label: 'AWAITING FIRST CYCLE' };
  if (health.system_status === 'TOPOLOGY_INCOMPLETE') return { color: 'negative', label: 'ACCOUNTS INCOMPLETE' };
  return { color: 'positive', label: 'CONNECTED' };
});
</script>

<template>
  <q-page padding>
    <div class="page-title">
      <div><span class="eyebrow">FORWARD PAPER TRADING</span><h1>Market & Virtual Portfolio</h1></div>
      <q-badge :color="systemStatus.color">{{ systemStatus.label }}</q-badge>
    </div>
    <div class="row items-center justify-between q-mb-md q-col-gutter-sm">
      <div class="col-12 col-sm-auto">
        <q-select
          v-model="selectedAccount"
          :options="accountOptions"
          dense
          outlined
          emit-value
          map-options
          label="Paper Account / Portfolio"
          style="min-width: 340px"
        />
      </div>
      <div class="col-12 col-sm-auto text-caption text-grey">
        {{ accountsOverviewText }}
      </div>
    </div>
    <q-banner v-if="error" class="bg-negative text-white">{{ error }}</q-banner>
    <q-inner-loading :showing="loading && !data.market.length" />
    <section>
      <h2>Market Overview</h2>
      <div class="grid">
        <q-card v-for="market in data.market" :key="market.symbol" flat bordered>
          <b>{{ market.symbol }}</b><h3>{{ formatPrice(market.ask) }}</h3>
          <div class="muted">Spread {{ formatSpread(market.ask, market.bid) }} · Funding {{ market.funding_rate ?? '—' }}</div>
          <router-link :to="`/market/${market.symbol}`">Open analysis →</router-link>
        </q-card>
        <EmptyState v-if="!data.market.length" />
      </div>
    </section>
    <section>
      <div class="row items-center justify-between q-mb-xs">
        <h2>Paper Portfolio · Simulated USDT</h2>
        <q-badge v-if="data.portfolio?.is_aggregate" color="primary" class="q-pa-xs text-subtitle2">
          AGGREGATE ({{ data.portfolio?.accounts_count || selectedAccounts.length || '—' }} Accounts · {{ selectedExchange.toUpperCase() }})
        </q-badge>
        <q-badge v-else :color="data.portfolio?.status === 'ACTIVE' ? 'positive' : (data.portfolio?.status === 'BLOCKED' ? 'warning' : 'negative')" class="q-pa-xs text-subtitle2">
          ACCOUNT: {{ data.account?.name || data.portfolio?.account_name || 'Selected' }} ({{ selectedExchange.toUpperCase() }}) · {{ data.portfolio?.status }}
        </q-badge>
      </div>
      <q-banner class="bg-grey-9 text-white q-mb-md">Estimated paper results, not actual exchange profits. Backtest performance is reported separately.</q-banner>
      <div v-if="data.portfolio" class="metrics">
        <MetricCard label="Starting Balance (USDT)" :value="formatMoney(data.portfolio.starting_balance)" />
        <MetricCard label="Current Balance (USDT)" :value="formatMoney(data.portfolio.current_balance)" />
        <MetricCard label="Current Equity (USDT)" :value="formatMoney(data.portfolio.current_equity)" />
        <MetricCard label="Total Return (%)" :value="formatPercent(data.portfolio.total_return_percent)" />
        <MetricCard label="Total Net PnL (USDT)" :value="formatMoney(data.portfolio.total_net_pnl)" />
        <MetricCard label="Realized PnL (USDT)" :value="formatMoney(data.portfolio.realized_pnl)" />
        <MetricCard label="Unrealized PnL (USDT)" :value="formatMoney(data.portfolio.unrealized_pnl)" />
        <MetricCard label="Available Capital (USDT)" :value="formatMoney(data.portfolio.available_virtual_capital)" />
        <MetricCard label="Reserved Risk (USDT)" :value="formatMoney(data.portfolio.reserved_risk)" />
        <MetricCard label="Max Drawdown (%)" :value="formatPercent(data.portfolio.maximum_drawdown_percent)" />
        <MetricCard label="Open Positions" :value="data.portfolio.open_positions" />
        <MetricCard label="Pending Virtual Entries" :value="data.portfolio.pending_entry_signals" />
      </div>
      <EmptyState v-else />
    </section>
    <section>
      <h2>Independent Strategy Paper Portfolios</h2>
      <div class="muted q-mb-sm">Each enabled strategy has isolated forward-only virtual accounting. No historical replay is mixed into these balances.</div>
      <q-markup-table flat bordered>
        <thead><tr><th class="text-left">Strategy</th><th>Account</th><th>Evaluation</th><th>ML Model</th><th>Equity</th><th>Realized PnL</th><th>Unrealized PnL</th></tr></thead>
        <tbody><tr v-for="portfolio in data.strategy_portfolios || []" :key="portfolio.id">
          <td class="text-left">
            <b>{{ portfolio.strategy_id || 'baseline' }}</b>
            <div v-if="portfolio.strategy_version" class="muted">v{{ portfolio.strategy_version }} · {{ portfolio.mode || 'FORWARD_PAPER' }}</div>
          </td>
          <td class="text-center"><q-badge :color="portfolio.status === 'ACTIVE' ? 'positive' : (portfolio.status === 'BLOCKED' ? 'warning' : 'negative')">{{ portfolio.status || 'UNPROVISIONED' }}</q-badge></td>
          <td class="text-center"><q-badge color="primary">{{ portfolio.evaluation_status || 'STRATEGY_EVALUATING' }}</q-badge></td>
          <td class="text-center"><q-badge color="grey-7">{{ portfolio.ml_status || 'ML_NOT_TRAINED' }}</q-badge></td>
          <td class="text-right">{{ formatMoney(portfolio.current_equity) }}</td>
          <td class="text-right">{{ formatMoney(portfolio.realized_pnl) }}</td><td class="text-right">{{ formatMoney(portfolio.unrealized_pnl) }}</td>
        </tr></tbody>
      </q-markup-table>
      <EmptyState v-if="!(data.strategy_portfolios || []).length" />
      <div class="muted q-mt-sm">Execution failures (24h): {{ data.paper_execution_health?.execution_failures_24h ?? 0 }} · Latest evaluation: {{ data.paper_execution_health?.latest_strategy_evaluation_at ?? '—' }}</div>
    </section>
    <section>
      <div class="row items-center justify-between q-mb-sm">
        <h2>Bybit / Binance · Independent Paper Results</h2>
        <a href="/api/v1/exchanges/comparison.csv" download>Export both exchanges (CSV)</a>
      </div>
      <p class="muted">{{ comparisonSubtitle }}</p>
      <div class="row items-center q-gutter-sm q-mb-sm">
        <q-btn-toggle v-model="selectedExchange" :options="exchangeToggleOptions" unelevated toggle-color="primary" />
        <q-btn flat dense label="Refresh" @click="loadExchangeComparison" />
      </div>
      <div v-if="selectedExchange === 'all' && exchangeComparison" class="row q-col-gutter-sm q-mb-md">
        <div class="col-12 col-md-3">
          <MetricCard label="Total Paper Accounts" :value="crossExchangeSummary.accountsCount" />
        </div>
        <div class="col-12 col-md-3">
          <MetricCard label="Aggregate Starting (USDT)" :value="formatMoney(crossExchangeSummary.startingBalance)" />
        </div>
        <div class="col-12 col-md-3">
          <MetricCard label="Aggregate Equity (USDT)" :value="formatMoney(crossExchangeSummary.currentEquity)" />
        </div>
        <div class="col-12 col-md-3">
          <MetricCard label="Aggregate Realized (USDT)" :value="formatMoney(crossExchangeSummary.realizedPnl)" />
        </div>
      </div>
      <q-banner v-if="exchangeError" class="bg-warning text-black">{{ exchangeError }}</q-banner>
      <q-markup-table v-if="exchangeComparison" flat bordered class="q-mt-md">
        <thead>
          <tr>
            <th class="text-left">Strategy</th>
            <th v-if="selectedExchange === 'all'" class="text-center">Exchange</th>
            <th>Status</th>
            <th>Evaluation</th>
            <th>Balance (USDT)</th>
            <th>Equity (USDT)</th>
            <th>Realized PnL</th>
            <th>Unrealized PnL</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="account in selectedAccounts" :key="`${account.exchange_id}:${account.strategy_id}`">
            <td class="text-left">{{ account.strategy_id }}</td>
            <td v-if="selectedExchange === 'all'" class="text-center">
              <q-badge color="grey-8">{{ account.exchange_id?.toUpperCase() }}</q-badge>
            </td>
            <td class="text-center">
              <q-badge :color="account.status === 'ACTIVE' ? 'positive' : (account.status === 'BLOCKED' ? 'warning' : 'negative')">{{ account.status }}</q-badge>
            </td>
            <td class="text-center">
              <q-badge :color="account.evaluation_status === 'STRATEGY_EVALUATING' ? 'primary' : 'grey-7'">{{ account.evaluation_status }}</q-badge>
            </td>
            <td class="text-right">{{ formatMoney(account.starting_balance) }}</td>
            <td class="text-right">{{ formatMoney(account.current_equity) }}</td>
            <td class="text-right">{{ formatMoney(account.realized_pnl) }}</td>
            <td class="text-right">{{ formatMoney(account.unrealized_pnl) }}</td>
          </tr>
        </tbody>
      </q-markup-table>
    </section>
    <section>
      <h2>All Strategy Decisions · Last 24 Hours</h2>
      <q-banner class="bg-grey-9 text-white q-mb-md">All enabled strategies independently evaluate BTC and ETH every closed 15m candle. Decision counts are observations, not profitability; validated experiment and isolated forward-paper results remain on the Research page.</q-banner>
      <q-markup-table flat bordered wrap-cells>
        <thead><tr><th class="text-left">Strategy</th><th v-for="symbol in ['BTCUSDT', 'ETHUSDT']" :key="symbol" class="text-left">{{ symbol }}</th><th>Combined B / S / K</th><th class="text-left">Top SKIP reasons</th></tr></thead>
        <tbody>
          <tr v-for="strategy in data.strategy_observations_24h || []" :key="strategy.strategy_id">
            <td class="text-left">
              <b>{{ strategy.strategy_id }}</b>
              <div v-if="strategy.strategy_version" class="muted">v{{ strategy.strategy_version }} · 1h / 30m / 15m / 5m</div>
            </td>
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
      <EmptyState v-if="!(data.strategy_observations_24h || []).length" />
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
