<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { api, get } from '../services/api';
import { requirePayload } from '../profitabilityContract';
import { counterfactualView, formatMetric } from '../researchSemantics';
import { useSettings } from '../stores/settings';

const s = useSettings();
const activeTab = ref('finance');
const loading = ref(false);
const error = ref('');
const summaryData = ref<any>(null);
const registryData = ref<any>(null);
const slippageData = ref<any>(null);
const showRegistryDialog = ref(false);
let loadVersion = 0;
let traceVersion = 0;
const availability = computed(() => loading.value ? 'LOADING' : error.value ? 'ERROR' : summaryData.value?.accounting_scopes?.point_in_time?.availability ?? 'UNAVAILABLE');

const counterfactual = computed(() => counterfactualView(slippageData.value?.counterfactual));
const slippageTotalDrag = computed(() => (
  slippageData.value?.summary?.total_slippage_drag_usdt
  ?? summaryData.value?.accounting_scopes?.execution_slippage_diagnostic?.total_attributable_dollar_drag
  ?? null
));

// Symbol breakdown from lifecycle economics
const symbolBreakdown = computed(() => {
  return summaryData.value?.accounting_scopes?.full_lifecycle_economics?.breakdown_by_symbol || {};
});

// Exchange breakdown from lifecycle economics
const exchangeBreakdown = computed(() => {
  return summaryData.value?.accounting_scopes?.full_lifecycle_economics?.breakdown_by_exchange || {};
});

// Position Trace Dialog State
const showTraceDialog = ref(false);
const traceLoading = ref(false);
const traceError = ref('');
const tracePositionId = ref('');
const tracePosition = ref<any>(null);
const traceAttribution = ref<any>(null);
const traceFills = ref<any[]>([]);

// Signal Quality Analytics & Multi-Timeframe Attribution State
const signalTimeframe = ref('all');
const signalStrategy = ref('all');
const signalDirection = ref('all');
const signalQuality = ref('all');
const signalRisk = ref('all');
const signalGateDecision = ref('all');
const signalExecutionStatus = ref('all');
const signalOutcome = ref('all');
const signalDateFrom = ref('');
const signalDateTo = ref('');

const signalAnalyticsLoading = ref(false);
const signalAnalyticsData = ref<any>(null);
const signalAnalyticsError = ref('');

// Signal Explorer Paginated Table State
const explorerLoading = ref(false);
const explorerSignals = ref<any[]>([]);
const explorerPagination = ref({
  page: 1,
  rowsPerPage: 20,
  rowsNumber: 0,
  sortBy: 'decision_time',
  descending: true,
});

// Signal Detail Dialog State
const showSignalDetailDialog = ref(false);
const signalDetailLoading = ref(false);
const signalDetailData = ref<any>(null);
const signalDetailError = ref('');

const primaryStrategiesList = [
  { label: 'Все стратегии', value: 'all' },
  { label: 'Baseline', value: 'baseline' },
  { label: 'Trend Pullback', value: 'trend_pullback' },
  { label: 'Bollinger Mean Reversion', value: 'bollinger_mean_reversion' },
  { label: 'Donchian ATR Breakout', value: 'donchian_atr_breakout' },
  { label: 'Regime Switching', value: 'regime_switching' },
  { label: 'Momentum', value: 'momentum' },
  { label: 'Support Resistance Bounce', value: 'support_resistance_bounce' },
  { label: 'Support Resistance Breakout', value: 'support_resistance_breakout' },
  { label: 'Funding OI Squeeze', value: 'funding_open_interest_squeeze' },
  { label: 'Liquidity Sweep Reversal', value: 'liquidity_sweep_reversal' },
  { label: 'Volatility Compression Breakout', value: 'volatility_compression_breakout' },
  { label: 'BTC/ETH Lead Lag', value: 'btc_eth_lead_lag' },
  { label: 'Multi-Timeframe Trend Following', value: 'multi_timeframe_trend_following' },
];

function getSignalFilters() {
  const params: Record<string, any> = {};
  if (s.exchange && s.exchange !== 'all') params.exchange = s.exchange;
  if (s.instrument && s.instrument !== 'all' && s.resolvedSymbol) params.symbol = s.resolvedSymbol;
  if (signalTimeframe.value !== 'all') params.timeframe = signalTimeframe.value;
  if (signalStrategy.value !== 'all') params.strategy = signalStrategy.value;
  if (signalDirection.value !== 'all') params.side = signalDirection.value;
  if (signalQuality.value !== 'all') params.signal_quality = signalQuality.value;
  if (signalRisk.value !== 'all') params.trade_risk = signalRisk.value;
  if (signalGateDecision.value !== 'all') params.gate_decision = signalGateDecision.value;
  if (signalExecutionStatus.value !== 'all') params.execution_status = signalExecutionStatus.value;
  if (signalOutcome.value !== 'all') params.outcome = signalOutcome.value;
  if (signalDateFrom.value) params.from = signalDateFrom.value;
  if (signalDateTo.value) params.to = signalDateTo.value;
  return params;
}

async function loadSignalAnalytics() {
  signalAnalyticsLoading.value = true;
  signalAnalyticsError.value = '';
  try {
    const params = getSignalFilters();
    const res = await api.get('/research/profitability/signal-analytics', { params });
    signalAnalyticsData.value = res.data?.data || null;
  } catch (err: any) {
    signalAnalyticsError.value = err?.message || 'Ошибка загрузки аналитики сигналов';
  } finally {
    signalAnalyticsLoading.value = false;
  }
}

async function loadSignalExplorer(props?: any) {
  explorerLoading.value = true;
  try {
    const pagination = props?.pagination || explorerPagination.value;
    const params: Record<string, any> = {
      ...getSignalFilters(),
      page: pagination.page,
      per_page: pagination.rowsPerPage,
      sort_by: pagination.sortBy,
      sort_dir: pagination.descending ? 'desc' : 'asc',
    };
    const res = await api.get('/research/profitability/signal-explorer', { params });
    const payload = res.data;
    explorerSignals.value = payload.data || [];
    explorerPagination.value = {
      page: payload.current_page || pagination.page,
      rowsPerPage: payload.per_page || pagination.rowsPerPage,
      rowsNumber: payload.total || 0,
      sortBy: pagination.sortBy,
      descending: pagination.descending,
    };
  } catch {
    //
  } finally {
    explorerLoading.value = false;
  }
}

async function openSignalDetail(signalId: string) {
  if (!signalId) return;
  showSignalDetailDialog.value = true;
  signalDetailLoading.value = true;
  signalDetailData.value = null;
  signalDetailError.value = '';
  try {
    const res = await api.get(`/research/profitability/signal-explorer/${signalId}`);
    signalDetailData.value = res.data?.data || null;
  } catch (err: any) {
    signalDetailError.value = err?.message || `Ошибка загрузки сигнала ${signalId}`;
  } finally {
    signalDetailLoading.value = false;
  }
}

function exportSignalExplorerCsv() {
  const params = getSignalFilters();
  const query = new URLSearchParams(params).toString();
  window.open(`/api/v1/research/profitability/signal-explorer/export?${query}`, '_blank');
}

function resetSignalFilters() {
  signalTimeframe.value = 'all';
  signalStrategy.value = 'all';
  signalDirection.value = 'all';
  signalQuality.value = 'all';
  signalRisk.value = 'all';
  signalGateDecision.value = 'all';
  signalExecutionStatus.value = 'all';
  signalOutcome.value = 'all';
  signalDateFrom.value = '';
  signalDateTo.value = '';
  void loadSignalAnalytics();
  void loadSignalExplorer();
}

async function loadData() {
  const version = ++loadVersion;
  loading.value = true;
  summaryData.value = registryData.value = slippageData.value = null;
  error.value = '';
  try {
    const params: Record<string, string> = {};
    if (s.exchange) params.exchange = s.exchange;
    if (s.instrument && s.instrument !== 'all') {
      params.instrument = s.instrument;
      if (s.resolvedSymbol) params.symbol = s.resolvedSymbol;
    }
    const [summaryRes, registryRes, slippageRes] = await Promise.all([
      get('/research/profitability/summary', params),
      get('/research/profitability/metric-registry', params),
      get('/research/profitability/slippage-attribution', params),
    ]);
    if (version !== loadVersion) return;
    const summary = requirePayload(summaryRes, ['accounting_scopes', 'as_of']);
    const registry = requirePayload(registryRes, ['catalog']);
    const slippage = requirePayload(slippageRes, ['status']);
    summaryData.value = summary;
    registryData.value = registry.catalog;
    slippageData.value = slippage;
  } catch (err: any) {
    if (version !== loadVersion) return;
    error.value = err?.message || 'Ошибка загрузки аналитических данных доходности.';
  } finally {
    if (version === loadVersion) loading.value = false;
  }
}

watch(() => s.exchange, () => {
  void loadData();
  void loadSignalAnalytics();
  void loadSignalExplorer();
});
watch(() => s.instrument, () => {
  void loadData();
  void loadSignalAnalytics();
  void loadSignalExplorer();
});

watch(activeTab, (tab) => {
  if (tab === 'signal_analytics' && !signalAnalyticsData.value) {
    void loadSignalAnalytics();
    void loadSignalExplorer();
  }
});

async function openPositionTrace(positionId: string) {
  if (!positionId) return;
  const version = ++traceVersion;
  tracePositionId.value = positionId;
  showTraceDialog.value = true;
  traceLoading.value = true;
  traceError.value = '';
  tracePosition.value = null;
  traceAttribution.value = null;
  traceFills.value = [];

  try {
    const res = requirePayload(await get(`/research/profitability/slippage-attribution/positions/${positionId}`, { generation_id: slippageData.value?.generation_id }), ['position', 'fills']);
    if (version !== traceVersion) return;
    tracePosition.value = res?.position || null;
    traceAttribution.value = res?.slippage_attribution || null;
    traceFills.value = res?.fills || [];
  } catch (err: any) {
    if (version !== traceVersion) return;
    traceError.value = err?.message || `Ошибка загрузки трассировки позиции ${positionId}`;
  } finally {
    if (version === traceVersion) traceLoading.value = false;
  }
}

onMounted(() => {
  void loadData();
  void loadSignalAnalytics();
  void loadSignalExplorer();
});
</script>

<template>
  <q-page padding class="profitability-page">
    <div class="row items-center justify-between q-mb-md">
      <div>
        <div class="text-caption text-primary text-bold text-uppercase tracking-wider">
          VECTOR · ЭКОНОМИЧЕСКИЙ КОНТУР (G1 FOUNDATION + P-1 SLIPPAGE)
        </div>
        <h1 class="text-h5 text-bold q-my-none">Исследование доходности и издержек</h1>
      </div>
      <div class="row q-gutter-sm items-center">
        <q-btn
          outline
          color="primary"
          icon="menu_book"
          label="Реестр метрик"
          no-caps
          @click="showRegistryDialog = true"
        />
        <q-btn
          flat
          icon="refresh"
          label="Обновить"
          no-caps
          :loading="loading"
          @click="loadData"
        />
      </div>
    </div>

    <!-- Mandatory Disclaimers Banner -->
    <q-banner class="bg-amber-10 text-black q-mb-md rounded-borders" dense>
      <template #avatar>
        <q-icon name="warning" color="black" />
      </template>
      <div class="text-body2 text-bold">
        Ограничение исследовательского режима Bybit Paper Trading:
      </div>
      <div class="text-caption">
        Система работает в виртуальном исследовательском режиме без доступа к реальным торгам. Виртуальная прибыль не является гарантией будущей доходности на реальном рынке. Все финансовые метрики строго разделены по контурам учета (FULL_LIFECYCLE vs WINDOW_FLOW) во избежание искажений.
      </div>
    </q-banner>

    <q-banner v-if="error" class="bg-negative text-white q-mb-md rounded-borders">
      {{ error }}
    </q-banner>

    <q-banner v-if="availability !== 'AVAILABLE'" dense class="q-mb-md">{{ availability }}</q-banner>

    <!-- Active Scope Toolbar -->
    <div class="row items-center q-gutter-sm q-mb-md bg-grey-10 q-pa-sm rounded-borders">
      <div class="text-caption text-bold text-grey-4">ОБЛАСТЬ АНАЛИЗА:</div>
      <q-chip
        dense
        color="primary"
        text-color="white"
        icon="currency_exchange"
      >
        Биржа: {{ s.selectedExchangeName }}
      </q-chip>
      <q-chip
        dense
        :color="s.instrument !== 'all' ? 'indigo-7' : 'grey-8'"
        text-color="white"
        icon="toll"
      >
        Инструмент: {{ s.selectedInstrumentName }}
      </q-chip>
      <q-space />
      <div class="text-caption text-grey-5">
        {{ s.exchange === 'all' ? 'Кросс-биржевой агрегат' : s.selectedExchangeName }} · {{ s.instrument === 'all' ? 'Все инструменты (BTC + ETH)' : s.resolvedSymbol }}
      </div>
    </div>

    <!-- Top KPI Cards -->
    <div class="row q-col-gutter-md q-mb-md">
      <div class="col-12 col-md-3">
        <q-card flat bordered class="q-pa-sm">
          <div class="text-caption text-grey-7">Стартовый капитал (13 счетов)</div>
          <div class="text-h6 text-bold">
            {{ summaryData?.accounting_scopes?.point_in_time?.total_starting_balance !== null && summaryData?.accounting_scopes?.point_in_time?.total_starting_balance !== undefined ? summaryData?.accounting_scopes?.point_in_time?.total_starting_balance + ' USDT' : '—' }}
          </div>
          <div class="text-caption text-grey-6">Изолированный баланс 13 стратегий</div>
        </q-card>
      </div>

      <div class="col-12 col-md-3">
        <q-card flat bordered class="q-pa-sm">
          <div class="text-caption text-grey-7">Текущее эквити портфеля</div>
          <div class="text-h6 text-bold text-primary">
            {{ summaryData?.accounting_scopes?.point_in_time?.total_current_equity !== null && summaryData?.accounting_scopes?.point_in_time?.total_current_equity !== undefined ? summaryData?.accounting_scopes?.point_in_time?.total_current_equity + ' USDT' : '—' }}
          </div>
          <div class="text-caption text-grey-6">Срез POINT_IN_TIME на {{ summaryData?.as_of ? new Date(summaryData.as_of).toLocaleTimeString() : '—' }}</div>
        </q-card>
      </div>

      <div class="col-12 col-md-3">
        <q-card flat bordered class="q-pa-sm">
          <div class="text-caption text-grey-7">Реализованный Net PnL (Lifecycle)</div>
          <div class="text-h6 text-bold" :class="summaryData?.accounting_scopes?.full_lifecycle_economics?.net_pnl !== null && summaryData?.accounting_scopes?.full_lifecycle_economics?.net_pnl !== undefined && Number(summaryData?.accounting_scopes?.full_lifecycle_economics?.net_pnl) >= 0 ? 'text-positive' : (summaryData?.accounting_scopes?.full_lifecycle_economics?.net_pnl !== null && summaryData?.accounting_scopes?.full_lifecycle_economics?.net_pnl !== undefined && Number(summaryData?.accounting_scopes?.full_lifecycle_economics?.net_pnl) < 0 ? 'text-negative' : '')">
            {{ summaryData?.accounting_scopes?.full_lifecycle_economics?.net_pnl !== null && summaryData?.accounting_scopes?.full_lifecycle_economics?.net_pnl !== undefined ? summaryData?.accounting_scopes?.full_lifecycle_economics?.net_pnl + ' USDT' : '—' }}
          </div>
          <div class="text-caption text-grey-6">Контур FULL_LIFECYCLE (Gross - Fees + Funding)</div>
        </q-card>
      </div>

      <div class="col-12 col-md-3">
        <q-card flat bordered class="q-pa-sm">
          <div class="text-caption text-grey-7">Атрибуция проскальзывания (P-1)</div>
          <div class="text-h6 text-bold text-warning">
            {{ formatMetric(slippageTotalDrag) }} USDT
          </div>
          <div class="text-caption text-grey-6">
            {{ slippageData?.summary?.weighted_slippage_bps ? `${slippageData.summary.weighted_slippage_bps} bps (взвешенное)` : 'Диагностика (НЕ вычитается из Net)' }}
          </div>
        </q-card>
      </div>
    </div>

    <!-- Navigation Tabs -->
    <q-tabs
      v-model="activeTab"
      dense
      class="text-grey-7 q-mb-md"
      active-color="primary"
      indicator-color="primary"
      align="left"
      narrow-indicator
    >
      <q-tab name="finance" label="Финансы и Издержки" icon="account_balance_wallet" no-caps />
      <q-tab name="signal_analytics" label="Качество сигналов и Таймфреймы" icon="insights" no-caps />
      <q-tab name="slippage" label="Атрибуция проскальзывания (P-1)" icon="trending_down" no-caps />
      <q-tab name="risk" label="Риск и Счета" icon="shield" no-caps />
      <q-tab name="sample_quality" label="Дорожная карта (P-1..P-6)" icon="alt_route" no-caps />
    </q-tabs>

    <q-tab-panels v-model="activeTab" animated class="bg-transparent">
      <!-- Tab 1: Finance and Cost Breakdown -->
      <q-tab-panel name="finance" class="q-pa-none">
        <div class="row q-col-gutter-md q-mb-md">
          <div class="col-12 col-md-6">
            <q-card flat bordered class="q-pa-md">
              <div class="text-subtitle1 text-bold q-mb-sm">Водопад PnL (FULL_LIFECYCLE)</div>
              <q-list dense separator>
                <q-item>
                  <q-item-section>Валовая прибыль (Gross PnL):</q-item-section>
                  <q-item-section side class="text-bold">
                    {{ summaryData?.accounting_scopes?.full_lifecycle_economics?.gross_pnl ?? '—' }} USDT
                  </q-item-section>
                </q-item>
                <q-item>
                  <q-item-section>Комиссии (Taker 0.055%):</q-item-section>
                  <q-item-section side class="text-negative text-bold">
                    -{{ summaryData?.accounting_scopes?.full_lifecycle_economics?.fees ?? '—' }} USDT
                  </q-item-section>
                </q-item>
                <q-item>
                  <q-item-section>Знаковый фандинг (Funding):</q-item-section>
                  <q-item-section side class="text-bold" :class="Number(summaryData?.accounting_scopes?.full_lifecycle_economics?.signed_funding) >= 0 ? 'text-positive' : 'text-negative'">
                    {{ summaryData?.accounting_scopes?.full_lifecycle_economics?.signed_funding ?? '—' }} USDT
                  </q-item-section>
                </q-item>
                <q-separator />
                <q-item class="bg-grey-10 text-white rounded-borders">
                  <q-item-section class="text-bold">Итоговый Net Realized PnL:</q-item-section>
                  <q-item-section side class="text-bold" :class="Number(summaryData?.accounting_scopes?.full_lifecycle_economics?.net_pnl) >= 0 ? 'text-positive' : 'text-negative'">
                    {{ summaryData?.accounting_scopes?.full_lifecycle_economics?.net_pnl ?? '—' }} USDT
                  </q-item-section>
                </q-item>
              </q-list>
              <div class="text-caption text-grey-5 q-mt-sm">
                Тождество Net = Gross - Fees + Funding соблюдено:
                <q-badge :color="summaryData?.accounting_scopes?.full_lifecycle_economics?.accounting_identity_holds ? 'positive' : 'negative'">
                  {{ summaryData?.accounting_scopes?.full_lifecycle_economics?.accounting_identity_holds == null ? 'UNAVAILABLE' : summaryData.accounting_scopes.full_lifecycle_economics.accounting_identity_holds ? 'СХОДИТСЯ' : 'РАСХОЖДЕНИЕ' }}
                </q-badge>
              </div>
            </q-card>
          </div>

          <div class="col-12 col-md-6">
            <q-card flat bordered class="q-pa-md">
              <div class="text-subtitle1 text-bold q-mb-sm">Статистика исполнения и Проскальзывание</div>
              <div class="text-body2 q-mb-sm">
                Все сделки симулируются как <b>100% Taker</b> (комиссия 0.055% Bybit VIP0 baseline).
              </div>
              <q-banner dense class="bg-blue-grey-9 text-white rounded-borders q-mb-sm">
                <div class="text-caption">
                  <b>Семантика virtual_executions.slippage:</b> абсолютный сдвиг цены исполнения (|fill - ref|) в котируемой валюте (USDT за контракт). Не является процентным пунктом или суммарным долларовым убытком.
                </div>
              </q-banner>
              <div class="row q-col-gutter-sm">
                <div class="col-6">
                  <div class="text-caption text-grey-7">Исполнений учтено:</div>
                  <div class="text-h6">{{ summaryData?.accounting_scopes?.execution_slippage_diagnostic?.execution_count ?? '—' }}</div>
                </div>
                <div class="col-6">
                  <div class="text-caption text-grey-7">Единица измерения в БД:</div>
                  <div class="text-subtitle2">{{ summaryData?.accounting_scopes?.execution_slippage_diagnostic?.stored_slippage_unit || 'USDT_PER_CONTRACT_PRICE_DELTA' }}</div>
                </div>
              </div>
            </q-card>
          </div>

          <!-- Instrument Attribution: BTC vs ETH -->
          <div class="col-12 col-md-6">
            <q-card flat bordered class="q-pa-md">
              <div class="row items-center justify-between q-mb-sm">
                <div class="text-subtitle1 text-bold">Атрибуция по инструментам (BTC vs ETH)</div>
                <q-badge color="indigo-7" :label="s.instrument === 'all' ? 'Все инструменты' : s.resolvedSymbol" />
              </div>
              <div v-if="Object.keys(symbolBreakdown).length === 0" class="text-caption text-grey-6">
                Нет данных по сделкам для выбранного диапазона.
              </div>
              <q-list v-else dense separator>
                <q-item v-for="(symData, symKey) in symbolBreakdown" :key="symKey">
                  <q-item-section>
                    <q-item-label class="text-bold">{{ symKey }}</q-item-label>
                    <q-item-label caption class="text-grey-5">
                      Сделок: {{ symData.total_positions || symData.trades || 0 }} · Винрейт: {{ symData.win_rate != null ? (Number(symData.win_rate) * 100).toFixed(1) + '%' : '—' }}
                    </q-item-label>
                  </q-item-section>
                  <q-item-section side>
                    <div class="text-bold" :class="Number(symData.net_pnl) >= 0 ? 'text-positive' : 'text-negative'">
                      {{ Number(symData.net_pnl).toFixed(2) }} USDT
                    </div>
                    <div class="text-caption text-grey-5">
                      Fees: {{ Number(symData.fees || 0).toFixed(2) }}
                    </div>
                  </q-item-section>
                </q-item>
              </q-list>
            </q-card>
          </div>

          <!-- Exchange Attribution -->
          <div class="col-12 col-md-6">
            <q-card flat bordered class="q-pa-md">
              <div class="row items-center justify-between q-mb-sm">
                <div class="text-subtitle1 text-bold">Атрибуция по биржам (Exchange Isolation)</div>
                <q-badge color="primary" :label="s.selectedExchangeName" />
              </div>
              <div v-if="Object.keys(exchangeBreakdown).length === 0" class="text-caption text-grey-6">
                {{ s.selectedExchangeName }}: позиции в изолированном контуре учета.
              </div>
              <q-list v-else dense separator>
                <q-item v-for="(exData, exKey) in exchangeBreakdown" :key="exKey">
                  <q-item-section>
                    <q-item-label class="text-bold text-uppercase">{{ exKey }}</q-item-label>
                    <q-item-label caption class="text-grey-5">
                      Позиций: {{ exData.total_positions }} · WinRate: {{ exData.win_rate != null ? (Number(exData.win_rate) * 100).toFixed(1) + '%' : '—' }}
                    </q-item-label>
                  </q-item-section>
                  <q-item-section side>
                    <div class="text-bold" :class="Number(exData.net_pnl) >= 0 ? 'text-positive' : 'text-negative'">
                      {{ Number(exData.net_pnl).toFixed(2) }} USDT
                    </div>
                    <div class="text-caption text-grey-5">
                      Fees: {{ Number(exData.fees || 0).toFixed(2) }}
                    </div>
                  </q-item-section>
                </q-item>
              </q-list>
            </q-card>
          </div>
        </div>
      </q-tab-panel>

      <!-- Tab 2: P-1 Slippage Attribution -->
      <q-tab-panel name="slippage" class="q-pa-none">
        <!-- P-1 Header and Status -->
        <div class="row items-center justify-between q-mb-md">
          <div class="row items-center q-gutter-sm">
            <q-badge color="positive" class="text-bold q-py-xs q-px-sm">
              PROFIT-1 P-1: IMPLEMENTED_VERIFIED
            </q-badge>
            <span class="text-caption text-grey-6">
              Генерация: {{ slippageData?.generation_id || '—' }} (v{{ slippageData?.algorithm_version || '1.0.0' }})
            </span>
          </div>
          <div class="text-caption text-grey-6 text-mono" v-if="slippageData?.output_hash">
            SHA-256: {{ slippageData.output_hash.slice(0, 16) }}...
          </div>
        </div>

        <!-- Slippage Breakdown KPI Cards -->
        <div class="row q-col-gutter-md q-mb-md">
          <div class="col-12 col-md-3">
            <q-card flat bordered class="q-pa-sm">
              <div class="text-caption text-grey-7">Совокупный Drag (Сумма потерь)</div>
              <div class="text-h6 text-bold text-warning">
                {{ formatMetric(slippageData?.summary?.total_slippage_drag_usdt) }} USDT
              </div>
              <div class="text-caption text-grey-6">
                Взвешенное: {{ slippageData?.summary?.weighted_slippage_bps != null ? `${slippageData.summary.weighted_slippage_bps} bps` : '—' }}
              </div>
            </q-card>
          </div>

          <div class="col-12 col-md-3">
            <q-card flat bordered class="q-pa-sm">
              <div class="text-caption text-grey-7">Проскальзывание на входе (Entry)</div>
              <div class="text-h6 text-bold">
                {{ formatMetric(slippageData?.summary?.entry_slippage_drag_usdt) }} USDT
              </div>
              <div class="text-caption text-grey-6">Открытие позиций</div>
            </q-card>
          </div>

          <div class="col-12 col-md-3">
            <q-card flat bordered class="q-pa-sm">
              <div class="text-caption text-grey-7">Частичный выход (TP1)</div>
              <div class="text-h6 text-bold">
                {{ formatMetric(slippageData?.summary?.partial_exit_slippage_drag_usdt) }} USDT
              </div>
              <div class="text-caption text-grey-6">Фиксация части объёма</div>
            </q-card>
          </div>

          <div class="col-12 col-md-3">
            <q-card flat bordered class="q-pa-sm">
              <div class="text-caption text-grey-7">Финальный выход (SL / TP2 / Close)</div>
              <div class="text-h6 text-bold">
                {{ formatMetric(slippageData?.summary?.final_exit_slippage_drag_usdt) }} USDT
              </div>
              <div class="text-caption text-grey-6">Полное закрытие позиций</div>
            </q-card>
          </div>
        </div>

        <!-- Counterfactual Zero-Slippage Calibration Card -->
        <q-card flat bordered class="q-pa-md q-mb-md">
          <div class="row items-center justify-between q-mb-sm">
            <div class="row items-center q-gutter-sm">
              <q-badge color="warning" text-color="black" class="text-bold q-py-xs q-px-sm">
                HYPOTHETICAL
              </q-badge>
              <div class="text-subtitle1 text-bold">
                Гипотетический сценарий нулевого проскальзывания (Counterfactual Calibration)
              </div>
            </div>
            <div class="row items-center q-gutter-sm">
              <q-badge outline color="grey-7">
                Контур: {{ slippageData?.counterfactual?.scope || 'FULL_LIFECYCLE' }}
              </q-badge>
              <q-badge outline :color="counterfactual.verified ? 'positive' : 'negative'">
                Статус: {{ counterfactual.status }}
              </q-badge>
              <q-badge outline :color="counterfactual.realizedScopeStatus === 'ESTABLISHED' ? 'positive' : 'warning'">
                Реализованный контур: {{ counterfactual.realizedScopeStatus }}
              </q-badge>
            </div>
          </div>

          <q-banner
            v-if="counterfactual.showUnavailable"
            dense
            class="bg-negative text-white rounded-borders q-mb-md"
          >
            <template #avatar>
              <q-icon name="block" color="white" />
            </template>
            <div class="text-caption">
              <b>НЕДОСТУПНО / НЕ ВЕРИФИЦИРОВАНО</b> ({{ counterfactual.status }}):
              {{ counterfactual.unavailableReason }}
            </div>
          </q-banner>

          <q-banner
            v-if="slippageData?.counterfactual?.raw_counterfactual_status && slippageData.counterfactual.raw_counterfactual_status !== 'COMPUTED'"
            dense
            class="bg-orange-10 text-black rounded-borders q-mb-md"
          >
            <template #avatar>
              <q-icon name="warning" color="orange" />
            </template>
            <div class="text-caption">
              <b>{{ slippageData.counterfactual.raw_counterfactual_status }}</b>:
              {{ slippageData?.counterfactual?.reconciliation_note }}
            </div>
          </q-banner>

          <q-banner dense class="bg-blue-grey-10 text-white rounded-borders q-mb-md">
            <template #avatar>
              <q-icon name="info" color="amber" />
            </template>
            <div class="text-caption">
              <b>{{ slippageData?.counterfactual?.disclaimer || 'Гипотетический контрфактический расчет предназначен исключительно для исследовательской калибровки. Комиссии и фандинг зафиксированы без изменений. В реальном рынке проскальзывание и ликвидное трение устранить невозможно. Двойное списание проскальзывания строго запрещено.' }}</b>
            </div>
          </q-banner>

          <div class="row q-col-gutter-md">
            <div class="col-12 col-md-4">
              <q-card flat class="bg-grey-10 text-white q-pa-sm rounded-borders">
                <div class="text-caption text-grey-4">Реализованный Net PnL (закрытый контур)</div>
                <div class="text-h6 text-bold" :class="counterfactual.realizedScopePositive === false ? 'text-negative' : (counterfactual.realizedScopePositive ? 'text-positive' : 'text-grey-5')">
                  {{ counterfactual.realizedScopeFormatted }} USDT
                </div>
                <div class="text-caption text-grey-5" v-if="counterfactual.realizedScopeAvailable">Только PnL реализованного объёма</div>
                <div class="text-caption text-negative" v-else>Недоступно: контур реализованного PnL не установлен</div>
                <div class="text-caption text-grey-6 q-mt-xs">
                  Справочно, все позиции (другой контур): {{ counterfactual.baselineAllPositionsFormatted }} USDT
                </div>
              </q-card>
            </div>

            <div class="col-12 col-md-4">
              <q-card flat class="bg-grey-10 text-white q-pa-sm rounded-borders">
                <div class="text-caption text-grey-4">Атрибутированный Drag проскальзывания</div>
                <div class="text-h6 text-bold text-warning">
                  <template v-if="counterfactual.attributableDragAvailable">+{{ counterfactual.attributableDragFormatted }}</template>
                  <template v-else>—</template>
                  USDT
                </div>
                <div class="text-caption text-grey-5">Потери на неоптимальной цене исполнения</div>
                <div class="text-caption text-grey-5">
                  Не распределено по открытым позициям: {{ counterfactual.openUnallocatedDragFormatted }} USDT
                </div>
              </q-card>
            </div>

            <div class="col-12 col-md-4">
              <q-card flat class="bg-grey-10 text-white q-pa-sm rounded-borders">
                <div class="text-caption text-grey-4">Гипотетический Net PnL (Zero Slippage)</div>
                <div class="text-h6 text-bold" :class="counterfactual.hypotheticalPositive === false ? 'text-negative' : (counterfactual.hypotheticalPositive ? 'text-positive' : 'text-grey-5')">
                  {{ counterfactual.hypotheticalFormatted }} USDT
                </div>
                <div class="text-caption text-grey-5" v-if="counterfactual.hypotheticalAvailable">Реализованный Net + атрибутированный Drag</div>
                <div class="text-caption text-negative" v-else>Недоступно / не верифицировано — значение не подставляется нулём</div>
              </q-card>
            </div>
          </div>
        </q-card>

        <!-- Strategy Slippage Breakdown Table -->
        <q-card flat bordered class="q-pa-md q-mb-md">
          <div class="text-subtitle1 text-bold q-mb-sm">Распределение проскальзывания по стратегиям</div>
          <q-table
            flat
            dense
            :rows="Object.values(slippageData?.by_strategy || {})"
            row-key="strategy_id"
            :columns="[
              { name: 'strategy_id', label: 'Стратегия', field: 'strategy_id', align: 'left', sortable: true },
              { name: 'position_count', label: 'Позиций', field: 'position_count', align: 'right' },
              { name: 'total_slippage_drag_usdt', label: 'Drag проскальзывания (USDT)', field: 'total_slippage_drag_usdt', align: 'right' },
              { name: 'total_reference_notional', label: 'Референсный объём (USDT)', field: 'total_reference_notional', align: 'right' },
              { name: 'weighted_slippage_bps', label: 'Взвешенное (bps)', field: 'weighted_slippage_bps', align: 'right' },
            ]"
            :pagination="{ rowsPerPage: 10 }"
          />
        </q-card>
      </q-tab-panel>

      <!-- Tab 3: Primary Accounts & Risk -->
      <q-tab-panel name="risk" class="q-pa-none">
        <q-card flat bordered>
          <q-table
            flat
            dense
            :rows="summaryData?.accounting_scopes?.point_in_time?.accounts || []"
            row-key="account_id"
            :columns="[
              { name: 'strategy_id', label: 'Стратегия', field: 'strategy_id', align: 'left', sortable: true },
              { name: 'name', label: 'Имя счета', field: 'name', align: 'left' },
              { name: 'starting_balance', label: 'Старт (USDT)', field: 'starting_balance', align: 'right' },
              { name: 'current_equity', label: 'Текущее эквити', field: 'current_equity', align: 'right' },
              { name: 'realized_pnl', label: 'Реализованный PnL', field: 'realized_pnl', align: 'right' },
              { name: 'current_drawdown_percent', label: 'Просадка %', field: 'current_drawdown_percent', align: 'right' },
              { name: 'reconciliation', label: 'Сверка (Reconciliation)', field: (row: any) => row.reconciliation?.status, align: 'center' }
            ]"
            :pagination="{ rowsPerPage: 10 }"
          >
            <template #body-cell-reconciliation="props">
              <q-td :props="props">
                <q-chip
                  dense
                  size="sm"
                  :color="props.row.reconciliation?.status === 'PASSED' ? 'positive' : 'warning'"
                  text-color="white"
                >
                  {{ props.row.reconciliation?.status || 'NO_RUN' }}
                </q-chip>
              </q-td>
            </template>
          </q-table>
        </q-card>
      </q-tab-panel>

      <!-- Tab 4: Roadmap and Gated Modules -->
      <q-tab-panel name="sample_quality" class="q-pa-none">
        <q-card flat bordered class="q-pa-md q-mb-md">
          <div class="text-subtitle1 text-bold q-mb-sm">Статус аналитических пакетов (PROFIT-1 Roadmap)</div>
          <div class="text-caption text-grey-7 q-mb-md">
            Ниже приведены пакеты углубленного квантового анализа. P-1 реализован и верифицирован. Все пакеты P-2, P-4, P-5, P-6 имеют статус NOT_IMPLEMENTED (P-3: BLOCKED_PROHIBITED) и активируются только после отдельного утверждения оператором.
          </div>
          <q-list separator bordered class="rounded-borders">
            <q-item v-for="(mod, key) in summaryData?.gated_modules || {}" :key="key">
              <q-item-section avatar>
                <q-chip
                  dense
                  :color="mod.status === 'IMPLEMENTED_VERIFIED' ? 'positive' : (mod.status === 'BLOCKED_PROHIBITED' ? 'negative' : 'grey-8')"
                  text-color="white"
                >
                  {{ key }}
                </q-chip>
              </q-item-section>
              <q-item-section>
                <q-item-label class="text-bold">{{ mod.name }}</q-item-label>
                <q-item-label caption>
                  {{ mod.status === 'IMPLEMENTED_VERIFIED' ? 'Реализован, верифицирован на эталонной выборке и покрыт регрессионными тестами' : (mod.status === 'BLOCKED_PROHIBITED' ? 'СТРОГО ЗАПРЕЩЕНО СПЕЦИФИКАЦИЕЙ (P-3 REPLAY)' : 'Ожидает утверждения оператора') }}
                </q-item-label>
              </q-item-section>
              <q-item-section side>
                <q-badge :color="mod.status === 'IMPLEMENTED_VERIFIED' ? 'positive' : (mod.status === 'BLOCKED_PROHIBITED' ? 'negative' : 'grey-7')">
                  {{ mod.status }}
                </q-badge>
              </q-item-section>
            </q-item>
          </q-list>
        </q-card>

        <q-card flat bordered class="q-pa-md">
          <div class="text-subtitle1 text-bold q-mb-sm">Статус эталонных архивов аудита</div>
          <q-list dense separator>
            <q-item v-for="(arch, aKey) in summaryData?.archives_status || {}" :key="aKey">
              <q-item-section>
                <q-item-label class="text-bold">{{ arch.filename }}</q-item-label>
                <q-item-label caption class="text-mono">SHA-256: {{ arch.expected_sha256 }}</q-item-label>
              </q-item-section>
              <q-item-section side>
                <q-badge :color="arch.status === 'PENDING_OPERATOR_CONFIRMATION' ? 'warning' : 'info'">
                  {{ arch.status }}
                </q-badge>
              </q-item-section>
            </q-item>
          </q-list>
        </q-card>
      </q-tab-panel>

      <!-- Tab: Signal Quality Analytics & Multi-Timeframe Attribution -->
      <q-tab-panel name="signal_analytics" class="q-pa-none">
        <!-- Global Filter Bar -->
        <q-card flat bordered class="q-pa-md q-mb-md">
          <div class="row items-center justify-between q-mb-sm">
            <div class="text-subtitle1 text-bold">Фильтры аналитики сигналов и атрибуции</div>
            <div class="row q-gutter-sm">
              <q-btn outline dense color="primary" icon="refresh" label="Применить" no-caps @click="loadSignalAnalytics(); loadSignalExplorer();" :loading="signalAnalyticsLoading || explorerLoading" />
              <q-btn flat dense color="grey" icon="restart_alt" label="Сбросить" no-caps @click="resetSignalFilters" />
              <q-btn outline dense color="secondary" icon="download" label="Экспорт CSV" no-caps @click="exportSignalExplorerCsv" />
            </div>
          </div>

          <div class="row q-col-gutter-sm">
            <div class="col-12 col-sm-6 col-md-2">
              <q-select
                v-model="signalTimeframe"
                dense
                outlined
                label="Таймфрейм"
                :options="['all', '1m', '3m', '5m', '15m', '30m', '60m']"
                @update:model-value="loadSignalAnalytics(); loadSignalExplorer();"
              />
            </div>
            <div class="col-12 col-sm-6 col-md-2">
              <q-select
                v-model="signalStrategy"
                dense
                outlined
                label="Стратегия"
                emit-value
                map-options
                :options="primaryStrategiesList"
                @update:model-value="loadSignalAnalytics(); loadSignalExplorer();"
              />
            </div>
            <div class="col-12 col-sm-6 col-md-2">
              <q-select
                v-model="signalDirection"
                dense
                outlined
                label="Направление"
                :options="['all', 'BUY', 'SELL']"
                @update:model-value="loadSignalAnalytics(); loadSignalExplorer();"
              />
            </div>
            <div class="col-12 col-sm-6 col-md-2">
              <q-select
                v-model="signalQuality"
                dense
                outlined
                label="Качество (Quality)"
                :options="['all', 'HIGH', 'MEDIUM', 'LOW', 'UNKNOWN']"
                @update:model-value="loadSignalAnalytics(); loadSignalExplorer();"
              />
            </div>
            <div class="col-12 col-sm-6 col-md-2">
              <q-select
                v-model="signalRisk"
                dense
                outlined
                label="Риск сделки (Risk)"
                :options="['all', 'LOW', 'MEDIUM', 'HIGH', 'UNKNOWN']"
                @update:model-value="loadSignalAnalytics(); loadSignalExplorer();"
              />
            </div>
            <div class="col-12 col-sm-6 col-md-2">
              <q-select
                v-model="signalOutcome"
                dense
                outlined
                label="Исход (Outcome)"
                :options="['all', 'GOOD', 'BAD', 'UNPROVEN']"
                @update:model-value="loadSignalAnalytics(); loadSignalExplorer();"
              />
            </div>
          </div>
        </q-card>

        <!-- Diagnostics Banner -->
        <div v-if="signalAnalyticsData?.diagnostics?.length" class="q-mb-md">
          <q-banner
            v-for="(diag, dIdx) in signalAnalyticsData.diagnostics"
            :key="dIdx"
            dense
            rounded
            class="q-mb-xs"
            :class="diag.severity === 'WARNING' ? 'bg-amber-9 text-black' : 'bg-blue-9 text-white'"
          >
            <template #avatar>
              <q-icon :name="diag.severity === 'WARNING' ? 'warning' : 'info'" />
            </template>
            <div class="text-caption text-bold">{{ diag.type }}: {{ diag.message }}</div>
          </q-banner>
        </div>

        <!-- Section A: Performance Overview -->
        <div class="row q-col-gutter-md q-mb-md">
          <div class="col-12 col-md-3">
            <q-card flat bordered class="q-pa-sm">
              <div class="text-caption text-grey-7">Net PnL (Сигналы & Позиции)</div>
              <div class="text-h6 text-bold" :class="Number(signalAnalyticsData?.overview?.overall_net_pnl) >= 0 ? 'text-positive' : 'text-negative'">
                {{ signalAnalyticsData?.overview?.overall_net_pnl ?? '0.00' }} USDT
              </div>
              <div class="text-caption text-grey-6">Gross: {{ signalAnalyticsData?.overview?.overall_gross_pnl ?? '0.00' }} · Fees: {{ signalAnalyticsData?.overview?.overall_fees ?? '0.00' }}</div>
            </q-card>
          </div>

          <div class="col-12 col-md-3">
            <q-card flat bordered class="q-pa-sm">
              <div class="text-caption text-grey-7">Win Rate & Profit Factor</div>
              <div class="text-h6 text-bold text-primary">
                {{ signalAnalyticsData?.overview?.win_rate ?? 0 }}%
              </div>
              <div class="text-caption text-grey-6">PF: {{ signalAnalyticsData?.overview?.profit_factor ?? 0 }} · Max DD: {{ signalAnalyticsData?.overview?.max_drawdown ?? 0 }}%</div>
            </q-card>
          </div>

          <div class="col-12 col-md-3">
            <q-card flat bordered class="q-pa-sm">
              <div class="text-caption text-grey-7">Оцененные сигналы</div>
              <div class="text-h6 text-bold">
                {{ signalAnalyticsData?.overview?.total_signals ?? 0 }}
              </div>
              <div class="text-caption text-grey-6">BUY: {{ signalAnalyticsData?.overview?.buy_signals ?? 0 }} · SELL: {{ signalAnalyticsData?.overview?.sell_signals ?? 0 }} · Сделок: {{ signalAnalyticsData?.overview?.executed_trades ?? 0 }}</div>
            </q-card>
          </div>

          <div class="col-12 col-md-3">
            <q-card flat bordered class="q-pa-sm">
              <div class="text-caption text-grey-7">Отклонено / Observation-Only</div>
              <div class="text-h6 text-bold text-warning">
                {{ signalAnalyticsData?.overview?.rejected_signals ?? 0 }} / {{ signalAnalyticsData?.overview?.observation_only_signals ?? 0 }}
              </div>
              <div class="text-caption text-grey-6">Закрыто сделок: {{ signalAnalyticsData?.overview?.closed_trades ?? 0 }}</div>
            </q-card>
          </div>
        </div>

        <!-- Section A Attribution Cards & Distribution Chips -->
        <div class="row q-col-gutter-md q-mb-md">
          <div class="col-12 col-md-6">
            <q-card flat bordered class="q-pa-sm">
              <div class="text-caption text-bold text-grey-5 q-mb-xs">ЛУЧШИЕ И ХУДШИЕ СЕГМЕНТЫ (ATTRIBUTION):</div>
              <div class="row q-col-gutter-sm text-caption">
                <div class="col-6">
                  <div>Лучшая биржа: <strong class="text-positive">{{ signalAnalyticsData?.overview?.best_exchange ?? '—' }}</strong></div>
                  <div>Лучшая стратегия: <strong class="text-positive">{{ signalAnalyticsData?.overview?.best_strategy ?? '—' }}</strong></div>
                  <div>Лучший инструмент: <strong class="text-positive">{{ signalAnalyticsData?.overview?.best_instrument ?? '—' }}</strong></div>
                </div>
                <div class="col-6">
                  <div>Худшая биржа: <strong class="text-negative">{{ signalAnalyticsData?.overview?.worst_exchange ?? '—' }}</strong></div>
                  <div>Худшая стратегия: <strong class="text-negative">{{ signalAnalyticsData?.overview?.worst_strategy ?? '—' }}</strong></div>
                  <div>Лучший ТФ: <strong class="text-primary">{{ signalAnalyticsData?.overview?.best_timeframe ?? '—' }}</strong> (Худший: {{ signalAnalyticsData?.overview?.worst_timeframe ?? '—' }})</div>
                </div>
              </div>
            </q-card>
          </div>

          <div class="col-12 col-md-6">
            <q-card flat bordered class="q-pa-sm">
              <div class="text-caption text-bold text-grey-5 q-mb-xs">РАСПРЕДЕЛЕНИЕ ИСХОДОВ И КАЧЕСТВА:</div>
              <div class="q-gutter-xs">
                <q-chip dense color="positive" text-color="white" icon="check_circle">
                  GOOD: {{ signalAnalyticsData?.overview?.outcome_distribution?.GOOD ?? 0 }} ({{ signalAnalyticsData?.overview?.outcome_distribution?.good_percent ?? 0 }}%)
                </q-chip>
                <q-chip dense color="negative" text-color="white" icon="cancel">
                  BAD: {{ signalAnalyticsData?.overview?.outcome_distribution?.BAD ?? 0 }}
                </q-chip>
                <q-chip dense color="grey-7" text-color="white" icon="help">
                  UNPROVEN: {{ signalAnalyticsData?.overview?.outcome_distribution?.UNPROVEN ?? 0 }}
                </q-chip>
                <q-chip dense color="indigo" text-color="white">
                  HIGH Quality: {{ signalAnalyticsData?.overview?.quality_distribution?.HIGH ?? 0 }}
                </q-chip>
                <q-chip dense color="blue-7" text-color="white">
                  MED Quality: {{ signalAnalyticsData?.overview?.quality_distribution?.MEDIUM ?? 0 }}
                </q-chip>
                <q-chip dense color="grey-8" text-color="white">
                  LOW Quality: {{ signalAnalyticsData?.overview?.quality_distribution?.LOW ?? 0 }}
                </q-chip>
              </div>
            </q-card>
          </div>
        </div>

        <!-- Section B: Exchange Comparison Table -->
        <q-card flat bordered class="q-pa-md q-mb-md">
          <div class="text-subtitle1 text-bold q-mb-sm">Секция B: Сравнение бирж (Exchange Comparison)</div>
          <q-table
            flat
            dense
            bordered
            :rows="signalAnalyticsData?.exchange_comparison || []"
            row-key="exchange"
            :columns="[
              { name: 'exchange', label: 'Биржа', field: 'exchange', align: 'left', sortable: true },
              { name: 'signals', label: 'Сигналы', field: 'signals', align: 'right', sortable: true },
              { name: 'executed', label: 'Исполнено', field: 'executed', align: 'right', sortable: true },
              { name: 'good_percent', label: 'GOOD %', field: 'good_percent', align: 'right', sortable: true, format: (v: any) => `${v}%` },
              { name: 'win_rate', label: 'Win Rate', field: 'win_rate', align: 'right', sortable: true, format: (v: any) => `${v}%` },
              { name: 'net_pnl', label: 'Net PnL (USDT)', field: 'net_pnl', align: 'right', sortable: true },
              { name: 'expectancy', label: 'Ожидание/сигнал', field: 'expectancy', align: 'right', sortable: true },
              { name: 'max_drawdown', label: 'Max DD', field: 'max_drawdown', align: 'right', sortable: true, format: (v: any) => `${v}%` },
            ]"
            :pagination="{ rowsPerPage: 10 }"
          />
        </q-card>

        <!-- Section C: Strategy Comparison Table -->
        <q-card flat bordered class="q-pa-md q-mb-md">
          <div class="text-subtitle1 text-bold q-mb-sm">Секция C: Сравнение стратегий (Strategy Comparison)</div>
          <q-table
            flat
            dense
            bordered
            :rows="signalAnalyticsData?.strategy_comparison || []"
            row-key="strategy"
            :columns="[
              { name: 'strategy', label: 'Стратегия', field: 'strategy', align: 'left', sortable: true },
              { name: 'signals', label: 'Сигналы', field: 'signals', align: 'right', sortable: true },
              { name: 'executed', label: 'Исполнено', field: 'executed', align: 'right', sortable: true },
              { name: 'good_percent', label: 'GOOD %', field: 'good_percent', align: 'right', sortable: true, format: (v: any) => `${v}%` },
              { name: 'win_rate', label: 'Win Rate', field: 'win_rate', align: 'right', sortable: true, format: (v: any) => `${v}%` },
              { name: 'net_pnl', label: 'Net PnL (USDT)', field: 'net_pnl', align: 'right', sortable: true },
              { name: 'expectancy', label: 'Ожидание/сигнал', field: 'expectancy', align: 'right', sortable: true },
            ]"
            :pagination="{ rowsPerPage: 13 }"
          />
        </q-card>

        <!-- Section D: Timeframe Comparison Table -->
        <q-card flat bordered class="q-pa-md q-mb-md">
          <div class="text-subtitle1 text-bold q-mb-sm">Секция D: Сравнение канонических таймфреймов (1m, 3m, 5m, 15m, 30m, 60m)</div>
          <q-table
            flat
            dense
            bordered
            :rows="signalAnalyticsData?.timeframe_comparison || []"
            row-key="timeframe"
            :columns="[
              { name: 'timeframe', label: 'Таймфрейм', field: 'timeframe', align: 'left', sortable: true },
              { name: 'signals', label: 'Сигналы', field: 'signals', align: 'right', sortable: true },
              { name: 'executed', label: 'Исполнено', field: 'executed', align: 'right', sortable: true },
              { name: 'win_rate', label: 'Win Rate', field: 'win_rate', align: 'right', sortable: true, format: (v: any) => `${v}%` },
              { name: 'net_pnl', label: 'Net PnL (USDT)', field: 'net_pnl', align: 'right', sortable: true },
              { name: 'profit_factor', label: 'Profit Factor', field: 'profit_factor', align: 'right', sortable: true },
              { name: 'expectancy', label: 'Ожидание/сигнал', field: 'expectancy', align: 'right', sortable: true },
              { name: 'status', label: 'Статус данных', field: 'status', align: 'center', sortable: true },
            ]"
            :pagination="{ rowsPerPage: 6 }"
          >
            <template #body-cell-status="props">
              <q-td :props="props">
                <q-badge :color="props.value === 'SUPPORTED' ? 'positive' : (props.value === 'AGGREGATED' ? 'info' : 'grey-7')">
                  {{ props.value }}
                </q-badge>
              </q-td>
            </template>
          </q-table>
        </q-card>

        <!-- Section E: Signal Quality Matrix -->
        <q-card flat bordered class="q-pa-md q-mb-md">
          <div class="text-subtitle1 text-bold q-mb-sm">Секция E: Матрица Качество vs Риск (Quality vs Trade Risk Grid)</div>
          <q-table
            flat
            dense
            bordered
            :rows="signalAnalyticsData?.quality_risk_matrix || []"
            row-key="(r) => `${r.quality}_${r.trade_risk}`"
            :columns="[
              { name: 'quality', label: 'Качество (Quality)', field: 'quality', align: 'left', sortable: true },
              { name: 'trade_risk', label: 'Риск (Trade Risk)', field: 'trade_risk', align: 'left', sortable: true },
              { name: 'signals', label: 'Всего сигналов', field: 'signals', align: 'right', sortable: true },
              { name: 'executed', label: 'Исполнено', field: 'executed', align: 'right', sortable: true },
              { name: 'rejected', label: 'Отклонено', field: 'rejected', align: 'right', sortable: true },
              { name: 'win_rate', label: 'Win Rate', field: 'win_rate', align: 'right', sortable: true, format: (v: any) => `${v}%` },
              { name: 'net_pnl', label: 'Net PnL (USDT)', field: 'net_pnl', align: 'right', sortable: true },
              { name: 'expectancy', label: 'Ожидание/сигнал', field: 'expectancy', align: 'right', sortable: true },
            ]"
            :pagination="{ rowsPerPage: 16 }"
          />
        </q-card>

        <!-- Section F: Signal Explorer Table -->
        <q-card flat bordered class="q-pa-md q-mb-md">
          <div class="row items-center justify-between q-mb-sm">
            <div class="text-subtitle1 text-bold">Секция F: Обозреватель сигналов (Signal Explorer)</div>
            <q-btn outline dense color="secondary" icon="download" label="Экспорт CSV" no-caps @click="exportSignalExplorerCsv" />
          </div>
          <q-table
            flat
            dense
            bordered
            :rows="explorerSignals"
            row-key="id"
            :loading="explorerLoading"
            v-model:pagination="explorerPagination"
            @request="loadSignalExplorer"
            :columns="[
              { name: 'decision_time', label: 'Время', field: 'decision_time', align: 'left', sortable: true },
              { name: 'exchange', label: 'Биржа', field: 'exchange', align: 'left', sortable: true },
              { name: 'strategy_id', label: 'Стратегия', field: 'strategy_id', align: 'left', sortable: true },
              { name: 'symbol', label: 'Символ', field: 'symbol', align: 'left', sortable: true },
              { name: 'timeframe', label: 'ТФ', field: 'timeframe', align: 'center', sortable: true },
              { name: 'side', label: 'Сторона', field: 'side', align: 'center', sortable: true },
              { name: 'entry_price', label: 'Вход', field: 'entry_price', align: 'right' },
              { name: 'stop_loss', label: 'Stop Loss', field: 'stop_loss', align: 'right' },
              { name: 'signal_quality', label: 'Качество', field: 'signal_quality', align: 'center', sortable: true },
              { name: 'trade_risk', label: 'Риск', field: 'trade_risk', align: 'center', sortable: true },
              { name: 'gate_decision', label: 'Gate', field: 'gate_decision', align: 'center' },
              { name: 'retrospective_outcome', label: 'Исход', field: 'retrospective_outcome', align: 'center', sortable: true },
              { name: 'net_pnl', label: 'Net PnL', field: 'net_pnl', align: 'right' },
              { name: 'actions', label: 'Детали', field: 'actions', align: 'center' },
            ]"
          >
            <template #body-cell-side="props">
              <q-td :props="props">
                <q-badge :color="props.value === 'BUY' ? 'positive' : 'negative'">
                  {{ props.value }}
                </q-badge>
              </q-td>
            </template>
            <template #body-cell-signal_quality="props">
              <q-td :props="props">
                <q-badge :color="props.value === 'HIGH' ? 'indigo' : (props.value === 'MEDIUM' ? 'blue-7' : 'grey-7')">
                  {{ props.value }}
                </q-badge>
              </q-td>
            </template>
            <template #body-cell-trade_risk="props">
              <q-td :props="props">
                <q-badge :color="props.value === 'LOW' ? 'positive' : (props.value === 'MEDIUM' ? 'warning' : 'negative')">
                  {{ props.value }}
                </q-badge>
              </q-td>
            </template>
            <template #body-cell-retrospective_outcome="props">
              <q-td :props="props">
                <q-badge :color="props.value === 'GOOD' ? 'positive' : (props.value === 'BAD' ? 'negative' : 'grey-7')">
                  {{ props.value }}
                </q-badge>
              </q-td>
            </template>
            <template #body-cell-actions="props">
              <q-td :props="props">
                <q-btn flat dense round icon="visibility" color="primary" @click="openSignalDetail(props.row.id)">
                  <q-tooltip>Полная объяснимость сигнала</q-tooltip>
                </q-btn>
              </q-td>
            </template>
          </q-table>
        </q-card>
      </q-tab-panel>
    </q-tab-panels>

    <!-- Dialog: Signal Explainability Detail View -->
    <q-dialog v-model="showSignalDetailDialog" maximized>
      <q-card>
        <q-toolbar class="bg-indigo-9 text-white">
          <q-toolbar-title>Объяснимость сигнала (Signal Explainability & Audit Trace)</q-toolbar-title>
          <q-btn flat round dense icon="close" v-close-popup />
        </q-toolbar>

        <q-card-section class="q-pa-md" v-if="signalDetailLoading">
          <div class="row justify-center q-my-lg">
            <q-spinner color="primary" size="3em" />
          </div>
        </q-card-section>

        <q-card-section class="q-pa-md" v-else-if="signalDetailError">
          <q-banner dense class="bg-negative text-white rounded-borders">
            {{ signalDetailError }}
          </q-banner>
        </q-card-section>

        <q-card-section class="q-pa-md" v-else-if="signalDetailData">
          <div class="row q-col-gutter-md q-mb-md">
            <div class="col-12 col-md-4">
              <q-card flat bordered class="q-pa-sm">
                <div class="text-caption text-grey-7">Идентификация сигнала:</div>
                <div class="text-subtitle1 text-bold">{{ signalDetailData.symbol }} · {{ signalDetailData.side }}</div>
                <div class="text-caption text-grey-6">Биржа: {{ signalDetailData.exchange }} · ТФ: {{ signalDetailData.timeframe }}</div>
                <div class="text-caption text-grey-6">Стратегия: {{ signalDetailData.strategy_id }} (v{{ signalDetailData.strategy_version }})</div>
                <div class="text-caption text-mono">ID: {{ signalDetailData.id }}</div>
              </q-card>
            </div>

            <div class="col-12 col-md-4">
              <q-card flat bordered class="q-pa-sm">
                <div class="text-caption text-grey-7">Уровни входа и риска:</div>
                <div>Зона входа: <strong>{{ signalDetailData.entry_low }} — {{ signalDetailData.entry_high }}</strong></div>
                <div>Stop Loss: <strong class="text-negative">{{ signalDetailData.stop_loss }}</strong></div>
                <div>Take Profit: <strong class="text-positive">{{ signalDetailData.take_profit_1 }}</strong> (TP2: {{ signalDetailData.take_profit_2 }})</div>
              </q-card>
            </div>

            <div class="col-12 col-md-4">
              <q-card flat bordered class="q-pa-sm">
                <div class="text-caption text-grey-7">Классификация и Gate:</div>
                <div>Качество (Quality): <strong class="text-indigo">{{ signalDetailData.signal_quality }}</strong></div>
                <div>Риск (Trade Risk): <strong class="text-warning">{{ signalDetailData.trade_risk }}</strong></div>
                <div>Решение Gate: <strong>{{ signalDetailData.gate_decision }}</strong> (Allowed: {{ signalDetailData.gate_allowed ? 'ДА' : 'НЕТ' }})</div>
                <div>Ретроспективный исход: <strong :class="signalDetailData.retrospective_outcome === 'GOOD' ? 'text-positive' : (signalDetailData.retrospective_outcome === 'BAD' ? 'text-negative' : 'text-grey')">{{ signalDetailData.retrospective_outcome }}</strong></div>
              </q-card>
            </div>
          </div>

          <div class="row q-col-gutter-md q-mb-md">
            <div class="col-12 col-md-6">
              <q-card flat bordered class="q-pa-md">
                <div class="text-subtitle2 text-bold q-mb-xs">Причины сигнала (Signal Reasons):</div>
                <q-list dense separator>
                  <q-item v-for="(reason, rIdx) in signalDetailData.reasons || []" :key="rIdx">
                    <q-item-section avatar><q-icon name="check" color="positive" /></q-item-section>
                    <q-item-section>{{ reason }}</q-item-section>
                  </q-item>
                </q-list>

                <div class="text-subtitle2 text-bold q-mt-md q-mb-xs">Риски и ограничения (Signal Risks):</div>
                <q-list dense separator>
                  <q-item v-for="(risk, kIdx) in signalDetailData.risks || []" :key="kIdx">
                    <q-item-section avatar><q-icon name="warning" color="warning" /></q-item-section>
                    <q-item-section>{{ risk }}</q-item-section>
                  </q-item>
                </q-list>
              </q-card>
            </div>

            <div class="col-12 col-md-6">
              <q-card flat bordered class="q-pa-md">
                <div class="text-subtitle2 text-bold q-mb-xs">Срез индикаторов (Indicator Snapshot):</div>
                <pre class="bg-grey-10 text-white q-pa-sm rounded-borders text-caption text-mono" style="max-height: 250px; overflow-y: auto;">{{ JSON.stringify(signalDetailData.indicator_snapshot, null, 2) }}</pre>
              </q-card>
            </div>
          </div>

          <!-- Position Trace if Executed -->
          <q-card flat bordered class="q-pa-md" v-if="signalDetailData.position">
            <div class="text-subtitle2 text-bold q-mb-xs">Связанная позиция PAPER (Virtual Position):</div>
            <div class="row q-col-gutter-sm text-caption">
              <div class="col-3">Статус: <strong>{{ signalDetailData.position.status }}</strong></div>
              <div class="col-3">Цена входа: <strong>{{ signalDetailData.position.entry_price }}</strong></div>
              <div class="col-3">Net PnL: <strong :class="Number(signalDetailData.position.net_pnl) >= 0 ? 'text-positive' : 'text-negative'">{{ signalDetailData.position.net_pnl }} USDT</strong></div>
              <div class="col-3">R-Multiple: <strong>{{ signalDetailData.position.r_multiple ?? '—' }}</strong></div>
            </div>
          </q-card>
        </q-card-section>
      </q-card>
    </q-dialog>

    <!-- Dialog: Machine-Readable Metric Registry -->
    <q-dialog v-model="showRegistryDialog" maximized>
      <q-card>
        <q-toolbar class="bg-primary text-white">
          <q-toolbar-title>Реестр метрик и правила контуров учета (Metric Registry v{{ registryData?.version || '1.0.0' }})</q-toolbar-title>
          <q-btn flat round dense icon="close" v-close-popup />
        </q-toolbar>

        <q-card-section class="q-pa-md">
          <div class="text-subtitle2 text-bold q-mb-sm">Запреты и инварианты учета:</div>
          <q-banner dense class="bg-grey-9 text-white rounded-borders q-mb-md">
            <ul>
              <li v-for="(p, i) in registryData?.prohibitions || []" :key="i">{{ p }}</li>
            </ul>
          </q-banner>

          <div class="text-subtitle2 text-bold q-mb-sm">Спецификация метрик:</div>
          <q-table
            flat
            dense
            bordered
            :rows="Object.values(registryData?.metrics || {})"
            row-key="name"
            :columns="[
              { name: 'label', label: 'Метрика', field: 'label', align: 'left', sortable: true },
              { name: 'unit', label: 'Единица', field: 'unit', align: 'left' },
              { name: 'accounting_scope', label: 'Контур учета', field: 'accounting_scope', align: 'left' },
              { name: 'formula', label: 'Формула', field: 'formula', align: 'left' },
              { name: 'denominator_rule', label: 'Правило знаменателя', field: 'denominator_rule', align: 'left' },
              { name: 'provenance', label: 'Происхождение', field: 'provenance', align: 'left' }
            ]"
            :pagination="{ rowsPerPage: 20 }"
          />
        </q-card-section>
      </q-card>
    </q-dialog>

    <!-- Dialog: Position Level Slippage Trace -->
    <q-dialog v-model="showTraceDialog" style="max-width: 900px; width: 100%;">
      <q-card style="min-width: 700px;">
        <q-toolbar class="bg-primary text-white">
          <q-toolbar-title>Детальная трассировка проскальзывания позиции</q-toolbar-title>
          <q-btn flat round dense icon="close" v-close-popup />
        </q-toolbar>

        <q-card-section class="q-pa-md" v-if="traceLoading">
          <q-spinner color="primary" size="3em" />
          <span class="q-ml-sm">Загрузка трассировки исполнений...</span>
        </q-card-section>

        <q-card-section class="q-pa-md" v-else-if="traceError">
          <q-banner class="bg-negative text-white rounded-borders">
            {{ traceError }}
          </q-banner>
        </q-card-section>

        <q-card-section class="q-pa-md" v-else>
          <div class="row q-col-gutter-sm q-mb-md">
            <div class="col-4">
              <div class="text-caption text-grey-7">Инструмент:</div>
              <div class="text-subtitle1 text-bold">{{ tracePosition?.symbol }} ({{ tracePosition?.side }})</div>
            </div>
            <div class="col-4">
              <div class="text-caption text-grey-7">Net PnL:</div>
              <div class="text-subtitle1 text-bold" :class="Number(tracePosition?.net_pnl) >= 0 ? 'text-positive' : 'text-negative'">
                {{ tracePosition?.net_pnl }} USDT
              </div>
            </div>
            <div class="col-4">
              <div class="text-caption text-grey-7">Slippage Drag:</div>
              <div class="text-subtitle1 text-bold text-warning">
                {{ formatMetric(traceAttribution?.total_slippage_drag_usdt) }} USDT
              </div>
            </div>
          </div>

          <div class="text-subtitle2 text-bold q-mb-xs">Исполнения (Fills):</div>
          <q-table
            flat
            dense
            bordered
            :rows="traceFills"
            row-key="id"
            :columns="[
              { name: 'fill_role', label: 'Роль', field: 'fill_role', align: 'left' },
              { name: 'execution_side', label: 'Сторона', field: 'execution_side', align: 'left' },
              { name: 'quantity', label: 'Кол-во', field: 'quantity', align: 'right' },
              { name: 'reference_price', label: 'Ref цена', field: 'reference_price', align: 'right' },
              { name: 'fill_price', label: 'Fill цена', field: 'fill_price', align: 'right' },
              { name: 'slippage_drag_usdt', label: 'Drag (USDT)', field: 'slippage_drag_usdt', align: 'right' },
              { name: 'slippage_bps', label: 'bps', field: 'slippage_bps', align: 'right' },
            ]"
            :pagination="{ rowsPerPage: 10 }"
          />
        </q-card-section>
      </q-card>
    </q-dialog>
  </q-page>
</template>

<style scoped>
.profitability-page {
  max-width: 1400px;
  margin: 0 auto;
}
.tracking-wider {
  letter-spacing: 0.08em;
}
.text-mono {
  font-family: monospace;
}
</style>
