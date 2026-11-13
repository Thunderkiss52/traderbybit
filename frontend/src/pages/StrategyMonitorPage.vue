<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { get } from '../services/api';
import { useSettings } from '../stores/settings';
import MetricCard from '../components/MetricCard.vue';
import type { QTableColumn } from 'quasar';

const settings = useSettings();

const loading = ref(false);
const timelineLoading = ref(false);
const error = ref('');

const selectedExchange = ref<string>(settings.exchange || '');
const selectedStrategy = ref<string>('');
const selectedSymbol = ref<string>(settings.resolvedSymbol || '');
const selectedSide = ref<string>('');
const selectedQuality = ref<string>('');
const selectedRisk = ref<string>('');
const page = ref(1);

const monitorData = shallowRef<any>({
  exchanges: [],
  strategies: [],
  metrics: {
    total_evaluations: 0,
    total_signals: 0,
    total_skips: 0,
    risk_approved: 0,
    active_positions: 0,
    closed_positions: 0,
    long_signals: 0,
    short_signals: 0,
    quality_counts: {},
    risk_counts: {},
  },
  signals: { data: [], current_page: 1, last_page: 1, total: 0 },
});

const selectedItem = ref<any>(null);
const timelineEvents = ref<any[]>([]);

const columns: QTableColumn[] = [
  { name: 'decision_time', label: 'Time (UTC)', field: 'decision_time', align: 'left', sortable: true },
  { name: 'exchange', label: 'Exchange', field: 'exchange', align: 'center', sortable: true },
  { name: 'symbol', label: 'Symbol', field: 'symbol', align: 'left', sortable: true },
  { name: 'strategy_id', label: 'Strategy', field: 'strategy_id', align: 'left', sortable: true },
  { name: 'side', label: 'Decision', field: 'side', align: 'center', sortable: true },
  { name: 'signal_quality', label: 'Signal Quality', field: (r: any) => r.signal_quality || 'UNKNOWN', align: 'center', sortable: true },
  { name: 'trade_risk', label: 'Trade Risk', field: (r: any) => r.trade_risk || 'UNKNOWN', align: 'center', sortable: true },
  { name: 'risk_decision', label: 'Risk Guardrail', field: (r: any) => r.risk_decision_count > 1 ? `${r.risk_decision_count} ACCOUNT DECISIONS` : (r.risk_decision ? (r.risk_decision.allowed ? 'APPROVED' : 'REJECTED') : '—'), align: 'center' },
  { name: 'position', label: 'Virtual Execution', field: (r: any) => r.position_count > 1 ? `${r.position_count} ACCOUNT POSITIONS` : (r.position?.status || (r.side === 'SKIP' ? 'NOT_ATTEMPTED' : 'PENDING')), align: 'center' },
  { name: 'actions', label: '', field: 'id', align: 'right' },
];

const exchangeOptions = computed(() => [
  { label: 'All Exchanges', value: '' },
  ...(monitorData.value.exchanges || []).map((e: any) => ({
    label: `${e.name} (${e.active_accounts} active)`,
    value: e.id,
  })),
]);

const strategyOptions = computed(() => [
  { label: 'All Strategies', value: '' },
  ...(monitorData.value.strategies || []).map((s: any) => ({
    label: `${s.id} (v${s.version})`,
    value: s.id,
  })),
]);

const symbolOptions = [
  { label: 'All Symbols', value: '' },
  { label: 'BTCUSDT', value: 'BTCUSDT' },
  { label: 'ETHUSDT', value: 'ETHUSDT' },
];

const sideOptions = [
  { label: 'All Decisions', value: '' },
  { label: 'BUY (Long)', value: 'BUY' },
  { label: 'SELL (Short)', value: 'SELL' },
  { label: 'SKIP (Filter Active)', value: 'SKIP' },
];

const qualityOptions = [
  { label: 'All Signal Qualities', value: '' },
  { label: 'HIGH Quality', value: 'HIGH' },
  { label: 'MEDIUM Quality', value: 'MEDIUM' },
  { label: 'LOW Quality', value: 'LOW' },
  { label: 'UNKNOWN Quality', value: 'UNKNOWN' },
];

const riskOptions = [
  { label: 'All Trade Risks', value: '' },
  { label: 'LOW Risk', value: 'LOW' },
  { label: 'MEDIUM Risk', value: 'MEDIUM' },
  { label: 'HIGH Risk', value: 'HIGH' },
  { label: 'UNKNOWN Risk', value: 'UNKNOWN' },
];

let currentController: AbortController | undefined;

async function loadMonitor() {
  currentController?.abort();
  const controller = new AbortController();
  currentController = controller;
  loading.value = true;
  error.value = '';

  try {
    const params: Record<string, unknown> = {
      page: page.value,
      per_page: 15,
    };
    if (selectedExchange.value && selectedExchange.value !== 'all') {
      params.exchange = selectedExchange.value;
    }
    if (selectedStrategy.value) {
      params.strategy_id = selectedStrategy.value;
    }
    if (selectedSymbol.value) {
      params.symbol = selectedSymbol.value;
    }
    if (selectedSide.value) {
      params.side = selectedSide.value;
    }
    if (selectedQuality.value) {
      params.quality = selectedQuality.value;
    }
    if (selectedRisk.value) {
      params.risk = selectedRisk.value;
    }

    const res: any = await get('/strategy-monitor', params, controller.signal);
    if (currentController !== controller || controller.signal.aborted) return;
    monitorData.value = res;
    const items = res.signals?.data || [];
    if (!items.some((item: any) => item.id === selectedItem.value?.id)) {
      selectItem(items[0] || null);
    } else {
      // Refresh the inspector with data from the currently selected filter/page.
      selectItem(items.find((item: any) => item.id === selectedItem.value.id));
    }
  } catch (err: any) {
    if (currentController === controller && !controller.signal.aborted) {
      error.value = err.message || 'Failed to load strategy monitor.';
    }
  } finally {
    if (currentController === controller) loading.value = false;
  }
}

let timelineRequestId = 0;
async function selectItem(item: any) {
  const requestId = ++timelineRequestId;
  selectedItem.value = item;
  timelineEvents.value = [];
  timelineLoading.value = !!item?.id;
  if (!item?.id) return;

  try {
    const res: any = await get('/strategy-monitor/timeline', { signal_id: item.id });
    if (requestId === timelineRequestId) timelineEvents.value = res.timeline || [];
  } catch {
    if (requestId === timelineRequestId) timelineEvents.value = [];
  } finally {
    if (requestId === timelineRequestId) timelineLoading.value = false;
  }
}

function resetPagination() {
  selectItem(null);
  if (page.value === 1) loadMonitor();
  else page.value = 1; // page watcher performs the single request
}

watch([selectedExchange, selectedStrategy, selectedSymbol, selectedSide, selectedQuality, selectedRisk], resetPagination);
watch(page, loadMonitor);
watch(() => settings.exchange, (newVal) => {
  if (newVal !== selectedExchange.value) {
    selectedExchange.value = newVal;
  }
});
watch(() => settings.instrument, () => {
  selectedSymbol.value = settings.resolvedSymbol || '';
});

onMounted(() => {
  loadMonitor();
});

onBeforeUnmount(() => {
  currentController?.abort();
  ++timelineRequestId;
});

function qualityColor(quality: string) {
  switch (quality) {
    case 'HIGH': return 'cyan-8';
    case 'MEDIUM': return 'primary';
    case 'LOW': return 'orange-8';
    default: return 'blue-grey-6';
  }
}

function riskColor(risk: string) {
  switch (risk) {
    case 'LOW': return 'positive';
    case 'MEDIUM': return 'warning';
    case 'HIGH': return 'negative';
    default: return 'blue-grey-6';
  }
}
</script>

<template>
  <q-page padding>
    <div class="page-title">
      <div>
        <span class="eyebrow">MULTI-EXCHANGE STRATEGY OBSERVABILITY & QUALITY GATE</span>
        <h1>Strategy Signal Monitor</h1>
      </div>
      <q-badge color="primary" label="PAPER RESEARCH ONLY" />
    </div>

    <q-banner class="bg-indigo-10 text-white q-mb-md rounded-borders">
      <template #avatar>
        <q-icon name="info" color="cyan-2" />
      </template>
      <div class="text-weight-bold">Исследовательский режим — Торговые ордера на бирже отключены</div>
      <div class="text-caption">
        Система осуществляет непрерывный технический анализ на 10 биржах и выполняет виртуальные PAPER-транзакции в изолированном реестре. Все решения оцениваются через независимые матрицы качества сигнала (Setup Edge) и риска сделки (Portfolio Hazard).
      </div>
    </q-banner>

    <q-banner v-if="error" class="bg-negative text-white q-mb-md rounded-borders">
      {{ error }}
    </q-banner>

    <!-- Metrics Cards Row -->
    <div class="metrics q-mb-md">
      <MetricCard label="Market Evaluations" :value="monitorData.metrics?.total_evaluations" />
      <MetricCard label="Actionable Signals" :value="monitorData.metrics?.total_signals" />
      <MetricCard label="Analytical Skips" :value="monitorData.metrics?.total_skips" />
      <MetricCard label="Long Signals (BUY)" :value="monitorData.metrics?.long_signals" />
      <MetricCard label="Short Signals (SELL)" :value="monitorData.metrics?.short_signals" />
      <MetricCard label="Risk Approved" :value="monitorData.metrics?.risk_approved" />
      <MetricCard label="Active Positions" :value="monitorData.metrics?.active_positions" />
      <MetricCard label="Closed Positions" :value="monitorData.metrics?.closed_positions" />
    </div>

    <!-- Filters Row -->
    <div class="row q-col-gutter-sm q-mb-md items-center">
      <div class="col-12 col-sm-2">
        <q-select
          v-model="selectedExchange"
          dense
          outlined
          emit-value
          map-options
          :options="exchangeOptions"
          label="Exchange Venue"
        />
      </div>
      <div class="col-12 col-sm-2">
        <q-select
          v-model="selectedStrategy"
          dense
          outlined
          emit-value
          map-options
          :options="strategyOptions"
          label="Strategy Model"
        />
      </div>
      <div class="col-12 col-sm-2">
        <q-select
          v-model="selectedSymbol"
          dense
          outlined
          emit-value
          map-options
          :options="symbolOptions"
          label="Instrument / Pair"
        />
      </div>
      <div class="col-12 col-sm-2">
        <q-select
          v-model="selectedSide"
          dense
          outlined
          emit-value
          map-options
          :options="sideOptions"
          label="Signal Decision"
        />
      </div>
      <div class="col-12 col-sm-2">
        <q-select
          v-model="selectedQuality"
          dense
          outlined
          emit-value
          map-options
          :options="qualityOptions"
          label="Signal Quality"
        />
      </div>
      <div class="col-12 col-sm-2">
        <q-select
          v-model="selectedRisk"
          dense
          outlined
          emit-value
          map-options
          :options="riskOptions"
          label="Trade Risk"
        />
      </div>
    </div>

    <!-- Main Content: Left Feed Table, Right Detail & Timeline Inspector -->
    <div class="row q-col-gutter-md">
      <!-- Left: Signals / Observations List -->
      <div class="col-12 col-lg-7">
        <q-table
          flat
          bordered
          :rows="monitorData.signals?.data || []"
          :columns="columns"
          row-key="id"
          :loading="loading"
          :pagination="{ rowsPerPage: 0 }"
          hide-pagination
        >
          <template #body-cell-side="props">
            <q-td :props="props">
              <q-badge
                :color="props.value === 'BUY' ? 'positive' : (props.value === 'SELL' ? 'negative' : 'warning')"
                :label="props.value"
              />
            </q-td>
          </template>

          <template #body-cell-exchange="props">
            <q-td :props="props">
              <q-badge outline color="blue-grey-4" :label="props.value.toUpperCase()" />
            </q-td>
          </template>

          <template #body-cell-signal_quality="props">
            <q-td :props="props">
              <q-badge
                :color="qualityColor(props.value)"
                :label="props.row.score ? `${props.value} (${props.row.score})` : props.value"
              />
            </q-td>
          </template>

          <template #body-cell-trade_risk="props">
            <q-td :props="props">
              <q-badge
                :color="riskColor(props.value)"
                :label="props.value"
              />
            </q-td>
          </template>

          <template #body-cell-risk_decision="props">
            <q-td :props="props">
              <q-badge
                v-if="props.row.risk_decision"
                :color="props.row.risk_decision.allowed ? 'positive' : 'negative'"
                :label="props.row.risk_decision.allowed ? 'PASSED' : 'BLOCKED'"
              />
              <q-badge
                v-else-if="props.row.risk_decision_count > 1"
                color="info"
                :label="`${props.row.risk_decision_count} ACCOUNT DECISIONS`"
              />
              <span v-else class="text-grey">—</span>
            </q-td>
          </template>

          <template #body-cell-position="props">
            <q-td :props="props">
              <q-badge
                v-if="props.row.position"
                :color="props.row.position.status === 'OPEN' ? 'teal' : 'purple'"
                :label="props.row.position.status"
              />
              <q-badge
                v-else-if="props.row.position_count > 1"
                color="info"
                :label="`${props.row.position_count} ACCOUNT POSITIONS`"
              />
              <span v-else class="text-caption text-grey">{{ props.row.side === 'SKIP' ? 'SKIPPED' : 'NO POSITION' }}</span>
            </q-td>
          </template>

          <template #body-cell-actions="props">
            <q-td :props="props">
              <q-btn
                dense
                size="sm"
                :color="selectedItem?.id === props.row.id ? 'primary' : 'grey-7'"
                icon="visibility"
                label="Inspect"
                @click="selectItem(props.row)"
              />
            </q-td>
          </template>
        </q-table>

        <div class="row items-center justify-between q-mt-md">
          <span class="text-caption text-grey">
            Showing {{ monitorData.signals?.data?.length || 0 }} of {{ monitorData.signals?.total || 0 }} items · Page {{ page }} / {{ monitorData.signals?.last_page || 1 }}
          </span>
          <q-pagination
            v-model="page"
            :max="monitorData.signals?.last_page || 1"
            :max-pages="5"
            direction-links
            boundary-links
            :disable="loading"
          />
        </div>
      </div>

      <!-- Right: Detailed Explainability, Sizing & Chronological Timeline -->
      <div class="col-12 col-lg-5">
        <q-card flat bordered class="q-pa-md bg-dark">
          <div v-if="!selectedItem" class="text-center text-grey q-pa-xl">
            <q-icon name="search" size="48px" class="q-mb-sm text-grey-6" />
            <div class="text-subtitle1">Select a signal or observation to inspect</div>
            <div class="text-caption">Detailed decision explainability, sizing precision and audit timeline will appear here.</div>
          </div>

          <div v-else>
            <!-- Inspector Header -->
            <div class="row items-center justify-between q-mb-md">
              <div>
                <span class="text-caption text-grey-5">{{ selectedItem.exchange.toUpperCase() }} · {{ selectedItem.symbol }} · {{ selectedItem.timeframe }}</span>
                <div class="text-h6 text-weight-bold">
                  {{ selectedItem.strategy_id }}
                  <span class="text-caption text-grey-4">v{{ selectedItem.strategy_version }}</span>
                </div>
              </div>
              <div class="row q-gutter-xs">
                <q-badge
                  size="md"
                  :color="selectedItem.side === 'BUY' ? 'positive' : (selectedItem.side === 'SELL' ? 'negative' : 'warning')"
                  :label="selectedItem.side"
                />
                <q-badge
                  size="md"
                  :color="qualityColor(selectedItem.signal_quality || 'UNKNOWN')"
                  :label="'Q: ' + (selectedItem.signal_quality || 'UNKNOWN')"
                />
                <q-badge
                  size="md"
                  :color="riskColor(selectedItem.trade_risk || 'UNKNOWN')"
                  :label="'R: ' + (selectedItem.trade_risk || 'UNKNOWN')"
                />
              </div>
            </div>

            <!-- Panel 1: Decision Explainability & Rules Breakdown -->
            <q-expansion-item
              dense
              default-opened
              icon="rule"
              label="Decision Explainability & Rule Evidence"
              header-class="text-weight-bold text-subtitle2 bg-grey-9 rounded-borders q-mb-xs"
            >
              <div class="q-pa-sm">
                <div v-if="selectedItem.rules && selectedItem.rules.length > 0">
                  <div
                    v-for="(rule, idx) in selectedItem.rules"
                    :key="idx"
                    class="q-pa-xs q-mb-xs rounded-borders bg-grey-10 text-caption"
                  >
                    <div class="row items-center justify-between">
                      <span class="text-weight-bold">{{ rule.rule }}</span>
                      <q-badge
                        dense
                        :color="rule.passed ? 'positive' : (rule.blocking ? 'negative' : 'warning')"
                        :label="rule.passed ? 'PASSED' : (rule.blocking ? 'BLOCKING' : 'FAILED')"
                      />
                    </div>
                    <div class="text-grey-4 text-xs q-mt-xs">
                      Category: <span class="text-grey-3">{{ rule.category }}</span> · Operator: <code>{{ rule.operator }}</code>
                    </div>
                    <div class="text-grey-4 text-xs">
                      Actual: <code class="text-white">{{ JSON.stringify(rule.actual) }}</code> vs Threshold: <code class="text-grey-3">{{ JSON.stringify(rule.threshold) }}</code>
                    </div>
                  </div>
                </div>
                <div v-else class="text-caption text-grey">
                  No structured rules recorded. Reasons: {{ selectedItem.reasons?.join(', ') || 'None' }}
                </div>
              </div>
            </q-expansion-item>

            <!-- Panel 2: Risk Guardrails & Position Sizing Precision -->
            <q-expansion-item
              dense
              default-opened
              icon="account_balance_wallet"
              label="Risk Guardrails & Position Sizing"
              header-class="text-weight-bold text-subtitle2 bg-grey-9 rounded-borders q-my-xs"
            >
              <div class="q-pa-sm">
                <div class="row q-col-gutter-xs q-mb-xs">
                  <div class="col-6">
                    <div class="text-caption text-grey-4">Gate Matrix Decision:</div>
                    <q-badge
                      :color="selectedItem.gate_decision === 'ELIGIBLE' ? 'positive' : (selectedItem.gate_decision === 'CONDITIONALLY_ELIGIBLE' ? 'info' : (selectedItem.gate_decision === 'INSUFFICIENT_EVIDENCE' ? 'warning' : 'negative'))"
                      :label="selectedItem.gate_decision || (selectedItem.risk_decision ? (selectedItem.risk_decision.allowed ? 'ELIGIBLE' : 'BLOCKED') : 'NO DECISION')"
                    />
                  </div>
                  <div class="col-6">
                    <div class="text-caption text-grey-4">Guardrail Status:</div>
                    <q-badge
                      :color="selectedItem.risk_decision_count > 1 ? 'info' : (selectedItem.risk_decision ? (selectedItem.risk_decision.allowed ? 'positive' : 'negative') : 'grey')"
                      :label="selectedItem.risk_decision_count > 1 ? `${selectedItem.risk_decision_count} ACCOUNT DECISIONS` : (selectedItem.risk_decision ? (selectedItem.risk_decision.allowed ? 'PASSED ALL CHECKS' : 'REJECTED') : 'NO DECISION')"
                    />
                  </div>
                </div>

                <div v-if="selectedItem.risk_decision?.reasons?.length > 0" class="text-caption text-grey-4 q-mb-xs">
                  Checks / Rejections: {{ selectedItem.risk_decision.reasons.join(', ') }}
                </div>
                <div v-if="selectedItem.risk_decision_count > 1" class="q-mb-sm">
                  <div v-for="decision in selectedItem.risk_decisions" :key="decision.id" class="text-caption bg-grey-10 q-pa-xs q-mb-xs">
                    Account {{ decision.paper_account_id }}:
                    <q-badge :color="decision.allowed ? 'positive' : 'negative'" :label="decision.allowed ? 'PASSED' : 'BLOCKED'" />
                    <span class="q-ml-sm">{{ (decision.reasons || []).join(', ') }}</span>
                  </div>
                </div>

                <div class="bg-grey-10 q-pa-sm rounded-borders text-caption q-mt-xs">
                  <div class="row q-col-gutter-xs">
                    <div class="col-6">Entry Zone: <span class="text-white">{{ selectedItem.entry_zone?.low }} - {{ selectedItem.entry_zone?.high }}</span></div>
                    <div class="col-6">Planned Stop: <span class="text-negative">{{ selectedItem.planned_stop_loss?.price || selectedItem.stop_loss || '—' }} ({{ selectedItem.planned_stop_loss?.distance_percent ? selectedItem.planned_stop_loss.distance_percent + '%' : '—' }})</span></div>
                    <div class="col-6">Planned Risk $: <span class="text-warning">{{ selectedItem.planned_stop_loss?.loss_amount ? selectedItem.planned_stop_loss.loss_amount + ' USDT' : '—' }}</span></div>
                    <div class="col-6">Planned Notional: <span class="text-white">{{ selectedItem.notional ? selectedItem.notional + ' USDT' : '—' }}</span></div>
                    <div class="col-6">Margin (recorded): <span class="text-cyan">{{ selectedItem.margin ? selectedItem.margin + ' USDT' : '—' }}</span></div>
                    <div class="col-6">Target 1 (TP1): <span class="text-positive">{{ selectedItem.take_profit_1 || '—' }}</span></div>
                    <div class="col-6">Target 2 (TP2): <span class="text-positive">{{ selectedItem.take_profit_2 || '—' }}</span></div>
                    <div class="col-6">R:R (TP1/TP2): <span class="text-cyan">{{ selectedItem.risk_reward?.tp1 || '—' }} / {{ selectedItem.risk_reward?.tp2 || '—' }}</span></div>
                    <div class="col-12" v-if="selectedItem.expected_net_edge !== undefined && selectedItem.expected_net_edge !== null">
                      Expected Net Edge: <span class="text-positive">{{ selectedItem.expected_net_edge }} USDT / trade</span>
                    </div>
                  </div>
                </div>
              </div>
            </q-expansion-item>

            <!-- Panel 3: Virtual Trade Execution & PnL -->
            <q-expansion-item
              dense
              default-opened
              icon="receipt"
              label="Virtual PAPER Execution & Financial Result"
              header-class="text-weight-bold text-subtitle2 bg-grey-9 rounded-borders q-my-xs"
            >
              <div class="q-pa-sm">
                <div v-for="position in (selectedItem.positions?.length ? selectedItem.positions : (selectedItem.position ? [selectedItem.position] : []))" :key="position.id" class="bg-grey-10 q-pa-sm rounded-borders text-caption q-mb-xs">
                  <div class="text-caption text-cyan q-mb-xs">PAPER account: {{ position.paper_account_id || 'legacy' }}</div>
                  <div class="row q-col-gutter-xs">
                    <div class="col-6">Position Status: <q-badge color="teal" :label="position.status" /></div>
                    <div class="col-6">Fill Price: <span class="text-white">{{ position.entry_price }}</span></div>
                    <div class="col-6">Initial Quantity: <span class="text-white">{{ position.initial_quantity }}</span></div>
                    <div class="col-6">Remaining: <span class="text-white">{{ position.remaining_quantity }}</span></div>
                    <div class="col-6">Fees: <span class="text-white">{{ position.fees }}</span></div>
                    <div class="col-6">Net PnL: <span :class="Number(position.net_pnl) >= 0 ? 'text-positive' : 'text-negative'">{{ position.net_pnl }}</span></div>
                  </div>
                </div>
                <div v-if="!(selectedItem.positions?.length || selectedItem.position)" class="text-caption text-grey">
                  {{ selectedItem.side === 'SKIP' ? 'Strategy filtered candidate out. No virtual order placed.' : 'No execution recorded yet.' }}
                </div>
              </div>
            </q-expansion-item>

            <!-- Panel 4: Chronological Audit Timeline -->
            <q-expansion-item
              dense
              default-opened
              icon="timeline"
              label="Chronological Audit Timeline"
              header-class="text-weight-bold text-subtitle2 bg-grey-9 rounded-borders q-my-xs"
            >
              <div class="q-pa-sm">
                <q-inner-loading :showing="timelineLoading" />
                <q-timeline color="primary" class="q-pl-sm" dense>
                  <q-timeline-entry
                    v-for="(event, eIdx) in timelineEvents"
                    :key="eIdx"
                    :title="event.title"
                    :subtitle="event.timestamp"
                    :color="event.color"
                    :icon="event.icon"
                  >
                    <pre
                      v-if="event.details"
                      class="bg-grey-10 text-grey-3 q-pa-xs rounded-borders text-xs"
                      style="max-height: 120px; overflow: auto; white-space: pre-wrap; font-family: monospace;"
                    >{{ JSON.stringify(event.details, null, 2) }}</pre>
                  </q-timeline-entry>
                </q-timeline>
              </div>
            </q-expansion-item>
          </div>
        </q-card>
      </div>
    </div>
  </q-page>
</template>

<style scoped>
.text-xs {
  font-size: 0.72rem;
}
</style>
