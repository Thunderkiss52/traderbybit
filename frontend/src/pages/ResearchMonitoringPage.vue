<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { api } from '../services/api';

const activeTab = ref('degradation');
const horizon = ref('14d');
const reportPeriod = ref('daily');
const loading = ref(false);
const error = ref('');

const degradation = ref<any[]>([]);
const correlations = ref<any>(null);
const recommendations = ref<any[]>([]);
const reportData = ref<any>(null);

const recPagination = ref({
  page: 1,
  rowsPerPage: 10,
  rowsNumber: 0,
});

let currentRequestSeq = 0;

function formatPercent(val: any, fallback = '—'): string {
  if (val === null || val === undefined || val === '') return fallback;
  const num = Number(val);
  return Number.isFinite(num) ? `${(num * 100).toFixed(1)}%` : fallback;
}

function formatDecimal(val: any, decimals = 2, fallback = '—'): string {
  if (val === null || val === undefined || val === '') return fallback;
  const num = Number(val);
  return Number.isFinite(num) ? num.toFixed(decimals) : fallback;
}

function formatR(val: any, fallback = '—'): string {
  if (val === null || val === undefined || val === '') return fallback;
  const num = Number(val);
  return Number.isFinite(num) ? `${num.toFixed(2)} R` : fallback;
}

async function refreshDegradation() {
  const seq = ++currentRequestSeq;
  loading.value = true;
  error.value = '';
  try {
    const res = await api.get('/research/degradation', { params: { horizon: horizon.value } });
    if (seq !== currentRequestSeq) return;
    degradation.value = res.data?.data?.strategies || [];
  } catch (err: any) {
    if (seq !== currentRequestSeq) return;
    error.value = err.message || 'Failed to load degradation data';
  } finally {
    if (seq === currentRequestSeq) loading.value = false;
  }
}

async function refreshCorrelations() {
  const seq = ++currentRequestSeq;
  loading.value = true;
  error.value = '';
  try {
    const res = await api.get('/research/correlations', { params: { horizon: horizon.value } });
    if (seq !== currentRequestSeq) return;
    correlations.value = res.data?.data ?? null;
  } catch (err: any) {
    if (seq !== currentRequestSeq) return;
    error.value = err.message || 'Failed to load correlations';
  } finally {
    if (seq === currentRequestSeq) loading.value = false;
  }
}

async function refreshRecommendations(page?: number) {
  const seq = ++currentRequestSeq;
  if (typeof page === 'number') {
    recPagination.value.page = page;
  }
  loading.value = true;
  error.value = '';
  try {
    const res = await api.get('/research/recommendations', {
      params: {
        page: recPagination.value.page,
        per_page: recPagination.value.rowsPerPage,
      },
    });
    if (seq !== currentRequestSeq) return;
    const payload = res.data?.data;
    if (payload && Array.isArray(payload.data)) {
      recommendations.value = payload.data;
      recPagination.value.rowsNumber = payload.total ?? payload.data.length;
      recPagination.value.page = payload.current_page ?? recPagination.value.page;
    } else if (Array.isArray(payload)) {
      recommendations.value = payload;
      recPagination.value.rowsNumber = payload.length;
    } else {
      recommendations.value = [];
      recPagination.value.rowsNumber = 0;
    }
  } catch (err: any) {
    if (seq !== currentRequestSeq) return;
    error.value = err.message || 'Failed to load recommendations';
  } finally {
    if (seq === currentRequestSeq) loading.value = false;
  }
}

async function refreshReport() {
  const seq = ++currentRequestSeq;
  loading.value = true;
  error.value = '';
  try {
    const res = await api.get('/research/report', { params: { period: reportPeriod.value } });
    if (seq !== currentRequestSeq) return;
    reportData.value = res.data?.data ?? null;
  } catch (err: any) {
    if (seq !== currentRequestSeq) return;
    error.value = err.message || 'Failed to generate audit report';
  } finally {
    if (seq === currentRequestSeq) loading.value = false;
  }
}

async function acceptRecommendation(id: string) {
  try {
    await api.post(`/research/recommendations/${id}/accept`);
    await refreshRecommendations(recPagination.value.page);
  } catch (err: any) {
    error.value = err.message || 'Failed to accept recommendation';
  }
}

async function dismissRecommendation(id: string) {
  try {
    await api.post(`/research/recommendations/${id}/dismiss`);
    await refreshRecommendations(recPagination.value.page);
  } catch (err: any) {
    error.value = err.message || 'Failed to dismiss recommendation';
  }
}

const capabilities = ref<any>(null);
const jobs = ref<any[]>([]);

async function refreshCompute() {
  const seq = ++currentRequestSeq;
  loading.value = true;
  error.value = '';
  try {
    const [capRes, jobsRes] = await Promise.all([
      api.get('/research/capabilities'),
      api.get('/research/jobs', { params: { per_page: 20 } }),
    ]);
    if (seq !== currentRequestSeq) return;
    capabilities.value = capRes.data?.data ?? null;
    jobs.value = jobsRes.data?.data?.data || jobsRes.data?.data || [];
  } catch (err: any) {
    if (seq !== currentRequestSeq) return;
    error.value = err.message || 'Failed to load compute worker information';
  } finally {
    if (seq === currentRequestSeq) loading.value = false;
  }
}

async function cancelJob(jobId: string) {
  try {
    await api.post(`/research/jobs/${jobId}/cancel`, { reason: 'User requested abort from monitoring' });
    await refreshCompute();
  } catch (err: any) {
    error.value = err.message || 'Failed to cancel job';
  }
}

async function queueQuickJob(kind: string) {
  loading.value = true;
  try {
    await api.post('/research/jobs', {
      kind,
      strategy_id: 'trend_pullback',
      payload: { symbol: 'BTCUSDT', timeframe: '15m' },
    });
    await refreshCompute();
  } catch (err: any) {
    error.value = err.message || 'Failed to queue research job';
  } finally {
    loading.value = false;
  }
}

function handleTabChange(tab: string) {
  activeTab.value = tab;
  if (tab === 'degradation') refreshDegradation();
  else if (tab === 'correlations') refreshCorrelations();
  else if (tab === 'recommendations') refreshRecommendations();
  else if (tab === 'report') refreshReport();
  else if (tab === 'compute') refreshCompute();
}

onMounted(() => {
  refreshDegradation();
});
</script>

<template>
  <q-page padding>
    <div class="page-title row items-center justify-between q-mb-md">
      <div>
        <span class="eyebrow">STAGE III · INTELLIGENT MONITORING & DECISION SUPPORT</span>
        <h1>Research Intelligence & Decisions</h1>
      </div>
      <div class="row q-gutter-sm items-center">
        <q-btn-toggle
          v-if="activeTab === 'degradation' || activeTab === 'correlations'"
          v-model="horizon"
          no-caps
          unelevated
          toggle-color="primary"
          :options="[{ label: '7d', value: '7d' }, { label: '14d', value: '14d' }, { label: '30d', value: '30d' }]"
          @update:model-value="activeTab === 'degradation' ? refreshDegradation() : refreshCorrelations()"
        />
        <q-btn-toggle
          v-if="activeTab === 'report'"
          v-model="reportPeriod"
          no-caps
          unelevated
          toggle-color="primary"
          :options="[{ label: 'Daily', value: 'daily' }, { label: 'Weekly', value: 'weekly' }]"
          @update:model-value="refreshReport"
        />
        <q-btn flat icon="refresh" :loading="loading" label="Refresh" @click="handleTabChange(activeTab)" />
      </div>
    </div>

    <q-banner v-if="error" class="bg-negative text-white q-mb-md" rounded dense>
      {{ error }}
    </q-banner>

    <q-tabs
      v-model="activeTab"
      dense
      class="text-grey"
      active-color="primary"
      indicator-color="primary"
      align="left"
      narrow-indicator
      @update:model-value="handleTabChange"
    >
      <q-tab name="degradation" icon="show_chart" label="Strategy Degradation" />
      <q-tab name="correlations" icon="grid_view" label="Portfolio Correlation" />
      <q-tab name="recommendations" icon="lightbulb" label="Advisory Recommendations" />
      <q-tab name="report" icon="assessment" label="Audit Reports" />
      <q-tab name="compute" icon="memory" label="Compute Workers & Tasks" />
    </q-tabs>

    <q-separator class="q-mb-md" />

    <!-- TAB 1: STRATEGY DEGRADATION -->
    <div v-if="activeTab === 'degradation'">
      <q-card flat bordered class="q-mb-md">
        <q-card-section>
          <div class="text-subtitle1 text-bold">Strategy Health & Degradation Watchlist (Horizon: {{ horizon }})</div>
          <div class="text-caption text-grey">
            Detects performance drift, rolling expectancy degradation, drawdown spikes, and distinguishes regime mismatches from intrinsic alpha decay.
          </div>
        </q-card-section>
        <q-separator />
        <q-table
          flat
          :rows="degradation"
          :columns="[
            { name: 'strategy', label: 'Strategy', field: 'strategy_id', align: 'left' },
            { name: 'status', label: 'Status', field: 'status', align: 'center' },
            { name: 'degradation_type', label: 'Degradation Type', field: 'degradation_type', align: 'left' },
            { name: 'sample_size', label: 'Closed Trades', field: 'sample_size', align: 'right' },
            { name: 'win_rate', label: 'Win Rate (95% CI)', field: 'win_rate', align: 'right' },
            { name: 'expectancy_r', label: 'Expectancy (R)', field: 'expectancy_r', align: 'right' },
            { name: 'profit_factor', label: 'Profit Factor', field: 'profit_factor', align: 'right' },
            { name: 'max_drawdown', label: 'Max Drawdown', field: 'max_drawdown_percent', align: 'right' },
          ]"
          row-key="id"
          hide-pagination
          :pagination="{ rowsPerPage: 0 }"
        >
          <template #body-cell-status="props">
            <q-td :props="props">
              <q-badge
                :color="
                  props.value === 'STABLE'
                    ? 'positive'
                    : props.value === 'WATCH'
                    ? 'warning'
                    : props.value === 'INSUFFICIENT_DATA'
                    ? 'grey-7'
                    : 'negative'
                "
                :label="props.value"
              />
            </q-td>
          </template>
          <template #body-cell-degradation_type="props">
            <q-td :props="props">
              <q-chip
                dense
                size="sm"
                :color="props.value === 'REGIME_MISMATCH' ? 'info' : (props.value === 'POSSIBLE_ALPHA_DECAY' ? 'negative' : 'grey-8')"
                text-color="white"
              >
                {{ props.value }}
              </q-chip>
            </q-td>
          </template>
          <template #body-cell-win_rate="props">
            <q-td :props="props">
              <span v-if="typeof props.row.win_rate === 'number' && Number.isFinite(props.row.win_rate)">
                {{ (props.row.win_rate * 100).toFixed(1) }}%
                <small class="text-grey" v-if="typeof props.row.win_rate_ci_lower === 'number' && typeof props.row.win_rate_ci_upper === 'number' && Number.isFinite(props.row.win_rate_ci_lower)">
                  [{{ (props.row.win_rate_ci_lower * 100).toFixed(0) }}%-{{ (props.row.win_rate_ci_upper * 100).toFixed(0) }}%]
                </small>
              </span>
              <span v-else class="text-grey">—</span>
            </q-td>
          </template>
          <template #body-cell-expectancy_r="props">
            <q-td :props="props" :class="Number.isFinite(Number(props.value)) ? (Number(props.value) > 0 ? 'text-positive' : (Number(props.value) < 0 ? 'text-negative' : '')) : ''">
              {{ formatR(props.value) }}
            </q-td>
          </template>
          <template #body-cell-profit_factor="props">
            <q-td :props="props" :class="Number.isFinite(Number(props.value)) ? (Number(props.value) >= 1.2 ? 'text-positive' : (Number(props.value) < 1.0 ? 'text-negative' : '')) : ''">
              {{ formatDecimal(props.value, 2) }}
            </q-td>
          </template>
          <template #body-cell-max_drawdown="props">
            <q-td :props="props" :class="Number.isFinite(Number(props.value)) && Number(props.value) >= 15 ? 'text-negative text-bold' : ''">
              {{ formatDecimal(props.value, 2) !== '—' ? formatDecimal(props.value, 2) + '%' : '—' }}
            </q-td>
          </template>
        </q-table>
      </q-card>
    </div>

    <!-- TAB 2: PORTFOLIO CORRELATION & OVERLAP -->
    <div v-if="activeTab === 'correlations' && correlations">
      <!-- Risk Metrics Cards -->
      <div class="row q-col-gutter-md q-mb-md">
        <div class="col-12 col-md-4">
          <q-card flat bordered class="kpi-card">
            <q-card-section>
              <div class="text-caption text-grey">Concentration Index (HHI)</div>
              <div class="text-h5 text-bold" :class="Number.isFinite(Number(correlations.concentration_score)) && Number(correlations.concentration_score) > 0.5 ? 'text-warning' : 'text-positive'">
                {{ correlations.insufficient_data || correlations.concentration_score === null || correlations.concentration_score === undefined || !Number.isFinite(Number(correlations.concentration_score)) ? '— (Insufficient Data)' : Number(correlations.concentration_score).toFixed(3) }}
              </div>
              <div class="text-caption text-grey-6">Normalized Herfindahl index (lower is diversified)</div>
            </q-card-section>
          </q-card>
        </div>

        <div class="col-12 col-md-4">
          <q-card flat bordered class="kpi-card">
            <q-card-section>
              <div class="text-caption text-grey">Concurrent Asset Overlaps</div>
              <div class="text-h5 text-bold">
                {{ correlations.concurrent_overlap ? Object.keys(correlations.concurrent_overlap).length : 0 }}
              </div>
              <div class="text-caption text-grey-6">Positions held in identical direction</div>
            </q-card-section>
          </q-card>
        </div>

        <div class="col-12 col-md-4">
          <q-card flat bordered class="kpi-card">
            <q-card-section>
              <div class="text-caption text-grey">Active Correlation Alerts</div>
              <div class="text-h5 text-bold" :class="correlations.alerts && correlations.alerts.length > 0 ? 'text-negative' : 'text-positive'">
                {{ correlations.alerts ? correlations.alerts.length : 0 }}
              </div>
              <div class="text-caption text-grey-6">Spike & co-movement warnings</div>
            </q-card-section>
          </q-card>
        </div>
      </div>

      <!-- Alerts List -->
      <q-banner v-if="correlations.alerts && correlations.alerts.length > 0" class="bg-warning text-dark q-mb-md" rounded dense>
        <div class="text-subtitle2 text-bold q-mb-xs">Portfolio Co-Movement Warnings:</div>
        <ul class="q-ma-none q-pl-md">
          <li v-for="(alert, idx) in correlations.alerts" :key="idx">{{ alert }}</li>
        </ul>
      </q-banner>

      <!-- Correlation Matrix -->
      <q-card flat bordered class="q-mb-md">
        <q-card-section>
          <div class="text-subtitle1 text-bold">Strategy Pairwise Return Correlation Matrix</div>
          <div class="text-caption text-grey">Pearson correlation coefficient of closed trade returns across active strategies.</div>
        </q-card-section>
        <q-separator />
        <q-card-section>
          <div v-if="correlations.pair_matrix && Object.keys(correlations.pair_matrix).length > 0">
            <div class="row q-col-gutter-sm text-center">
              <div class="col-2 text-bold text-left">Strategy</div>
              <div
                v-for="sCol in Object.keys(correlations.pair_matrix)"
                :key="sCol"
                class="col text-bold text-caption ellipsis"
              >
                {{ sCol }}
              </div>
            </div>
            <div
              v-for="(rowVals, sRow) in correlations.pair_matrix"
              :key="sRow"
              class="row q-col-gutter-sm items-center q-my-xs text-center"
            >
              <div class="col-2 text-bold text-left ellipsis">{{ sRow }}</div>
              <div
                v-for="(val, colKey) in rowVals"
                :key="colKey"
                class="col q-pa-xs"
              >
                <q-badge
                  :color="
                    Number(val) === 1.0
                      ? 'grey-8'
                      : Number(val) > 0.7
                      ? 'negative'
                      : Number(val) < -0.2
                      ? 'positive'
                      : 'grey-9'
                  "
                  :label="val !== null && val !== undefined && Number.isFinite(Number(val)) ? Number(val).toFixed(2) : '—'"
                />
              </div>
            </div>
          </div>
          <div v-else class="text-grey text-center q-pa-md">
            Insufficient paired trading history to calculate correlation matrix.
          </div>
        </q-card-section>
      </q-card>
    </div>

    <!-- TAB 3: ADVISORY RECOMMENDATIONS -->
    <div v-if="activeTab === 'recommendations'">
      <div v-if="recommendations.length > 0" class="row q-col-gutter-md">
        <div v-for="rec in recommendations" :key="rec.id" class="col-12 col-md-6">
          <q-card flat bordered>
            <q-card-section class="row items-center justify-between">
              <div>
                <q-chip
                  dense
                  size="sm"
                  :color="
                    rec.category === 'RISK_MITIGATION'
                      ? 'negative'
                      : rec.category === 'ALPHA_IMPROVEMENT'
                      ? 'positive'
                      : 'info'
                  "
                  text-color="white"
                >
                  {{ rec.category }}
                </q-chip>
                <span class="text-caption text-grey q-ml-sm" v-if="rec.strategy_id">{{ rec.strategy_id }}</span>
              </div>
              <q-badge color="grey-8" :label="rec.status" />
            </q-card-section>
            <q-card-section>
              <div class="text-subtitle1 text-bold">{{ rec.title }}</div>
              <p class="text-body2 text-grey-4 q-mt-xs">{{ rec.description }}</p>

              <div class="bg-grey-10 q-pa-sm rounded-borders q-mt-sm" v-if="rec.evidence">
                <div class="text-caption text-bold text-grey">Statistical Evidence:</div>
                <pre class="text-caption text-grey-4 q-ma-none">{{ JSON.stringify(rec.evidence, null, 2) }}</pre>
              </div>

              <div class="bg-grey-10 q-pa-sm rounded-borders q-mt-sm" v-if="rec.proposed_changes">
                <div class="text-caption text-bold text-primary">Proposed Parameter Modifications:</div>
                <pre class="text-caption text-primary q-ma-none">{{ JSON.stringify(rec.proposed_changes, null, 2) }}</pre>
              </div>
            </q-card-section>
            <q-separator />
            <q-card-actions align="right">
              <q-btn flat label="Dismiss" color="grey" @click="dismissRecommendation(rec.id)" />
              <q-btn unelevated label="Accept & Record" color="primary" @click="acceptRecommendation(rec.id)" />
            </q-card-actions>
          </q-card>
        </div>
        <div v-if="recPagination.rowsNumber > recPagination.rowsPerPage" class="col-12 flex flex-center q-mt-md">
          <q-pagination
            v-model="recPagination.page"
            :max="Math.ceil(recPagination.rowsNumber / recPagination.rowsPerPage)"
            :max-pages="7"
            direction-links
            boundary-links
            @update:model-value="refreshRecommendations"
          />
        </div>
      </div>
      <q-card v-else flat bordered class="q-pa-lg text-center text-grey">
        No pending advisory recommendations. Strategy parameters and portfolio risk are currently within calibrated bounds.
      </q-card>
    </div>

    <!-- TAB 4: AUDIT REPORT -->
    <div v-if="activeTab === 'report' && reportData">
      <q-card flat bordered class="q-mb-md">
        <q-card-section class="row items-center justify-between">
          <div>
            <div class="text-subtitle1 text-bold">{{ reportData.report_type }}</div>
            <div class="text-caption text-grey">Generated at: {{ reportData.generated_at }}</div>
          </div>
          <q-badge color="primary" :label="'SHA-256: ' + (reportData.manifest?.content_sha256?.substring(0, 16) + '...')" />
        </q-card-section>
        <q-separator />
        <q-card-section>
          <div class="row q-col-gutter-md">
            <div class="col-12 col-md-6">
              <div class="text-subtitle2 text-bold q-mb-xs">System Freshness & Infrastructure</div>
              <pre class="code-box">{{ JSON.stringify(reportData.system_freshness, null, 2) }}</pre>
            </div>
            <div class="col-12 col-md-6">
              <div class="text-subtitle2 text-bold q-mb-xs">Portfolio Correlation & Concentration</div>
              <pre class="code-box">{{ JSON.stringify(reportData.portfolio_correlation_and_risk, null, 2) }}</pre>
            </div>
            <div class="col-12">
              <div class="text-subtitle2 text-bold q-mb-xs">Strategy Degradation Watchlist</div>
              <pre class="code-box">{{ JSON.stringify(reportData.strategy_degradation_watchlist, null, 2) }}</pre>
            </div>
          </div>
        </q-card-section>
      </q-card>
    </div>

    <!-- TAB 5: COMPUTE WORKERS & TASKS -->
    <div v-if="activeTab === 'compute'">
      <!-- Engines Overview -->
      <div v-if="capabilities" class="row q-col-gutter-md q-mb-md">
        <div class="col-12 col-md-4">
          <q-card flat bordered class="kpi-card">
            <q-card-section>
              <div class="row items-center justify-between">
                <span class="text-caption text-grey">Go Research Worker</span>
                <q-chip
                  dense
                  :color="
                    capabilities.capabilities.go?.available
                      ? 'positive'
                      : capabilities.capabilities.go?.status === 'unsupported_version'
                      ? 'warning'
                      : 'negative'
                  "
                  text-color="white"
                  size="sm"
                >
                  {{
                    capabilities.capabilities.go?.available
                      ? 'READY'
                      : (capabilities.capabilities.go?.status || 'OFFLINE').toUpperCase()
                  }}
                </q-chip>
              </div>
              <div class="text-h6 text-bold q-mt-xs">{{ capabilities.capabilities.go?.engine }}</div>
              <div class="text-caption text-grey-6 q-mb-sm">High-throughput backtest & pattern mining</div>
              <div class="text-caption">
                Concurrency: {{ capabilities.capabilities.go?.concurrency_cap }} | Memory: {{ capabilities.capabilities.go?.memory_cap_mb }}MB
              </div>
              <div class="text-caption text-grey-5 q-mt-xs" v-if="capabilities.capabilities.go?.workers?.length">
                Active workers: {{ capabilities.capabilities.go?.workers?.length }}
              </div>
              <div class="text-caption text-negative q-mt-xs" v-else-if="capabilities.capabilities.go?.unavailable_reason">
                {{ capabilities.capabilities.go?.unavailable_reason }}
              </div>
            </q-card-section>
          </q-card>
        </div>

        <div class="col-12 col-md-4">
          <q-card flat bordered class="kpi-card">
            <q-card-section>
              <div class="row items-center justify-between">
                <span class="text-caption text-grey">Laravel Primary Engine</span>
                <q-chip dense color="primary" text-color="white" size="sm">READY</q-chip>
              </div>
              <div class="text-h6 text-bold q-mt-xs">{{ capabilities.capabilities.php?.engine }}</div>
              <div class="text-caption text-grey-6 q-mb-sm">Orchestration, safety, and fallback</div>
              <div class="text-caption">Concurrency: {{ capabilities.capabilities.php?.concurrency_cap }} | Ledger Authority</div>
            </q-card-section>
          </q-card>
        </div>

        <div class="col-12 col-md-4">
          <q-card flat bordered class="kpi-card">
            <q-card-section>
              <div class="row items-center justify-between">
                <span class="text-caption text-grey">Python ML Engine</span>
                <q-chip dense color="grey" text-color="white" size="sm">STANDBY</q-chip>
              </div>
              <div class="text-h6 text-bold q-mt-xs">{{ capabilities.capabilities.python?.engine }}</div>
              <div class="text-caption text-grey-6 q-mb-sm">Offline training on demand</div>
              <div class="text-caption">Status: {{ capabilities.capabilities.python?.status }}</div>
            </q-card-section>
          </q-card>
        </div>
      </div>

      <!-- Quick Actions -->
      <q-card flat bordered class="q-mb-md">
        <q-card-section class="row items-center justify-between">
          <div>
            <div class="text-subtitle1 text-bold">Dispatch Research Tasks</div>
            <div class="text-caption text-grey">Queue asynchronous computational workloads to the isolated research worker.</div>
          </div>
          <div class="row q-gutter-sm">
            <q-btn unelevated color="primary" size="sm" icon="play_arrow" label="Run Backtest" @click="queueQuickJob('BACKTEST')" />
            <q-btn unelevated color="secondary" size="sm" icon="timeline" label="Run Walk-Forward" @click="queueQuickJob('WALK_FORWARD')" />
            <q-btn unelevated color="accent" size="sm" icon="pattern" label="Mine Patterns" @click="queueQuickJob('PATTERN_MINE')" />
            <q-btn unelevated color="warning" text-color="dark" size="sm" icon="casino" label="Monte Carlo" @click="queueQuickJob('MONTE_CARLO')" />
          </div>
        </q-card-section>
      </q-card>

      <!-- Task Queue Table -->
      <q-card flat bordered>
        <q-card-section>
          <div class="row items-center justify-between">
            <div>
              <div class="text-subtitle1 text-bold">Durable Research Job Queue</div>
              <div class="text-caption text-grey">PostgreSQL FOR UPDATE SKIP LOCKED state machine with fencing tokens and heartbeat leases.</div>
            </div>
            <q-btn flat dense icon="refresh" label="Refresh Queue" @click="refreshCompute" />
          </div>
        </q-card-section>
        <q-separator />
        <q-card-section class="q-pa-none">
          <q-markup-table flat dense wrap-cells>
            <thead>
              <tr class="text-left">
                <th>Job ID</th>
                <th>Kind</th>
                <th>Strategy</th>
                <th>Status</th>
                <th>Progress</th>
                <th>Duration</th>
                <th>Requested At</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="jobs.length === 0">
                <td colspan="8" class="text-center text-grey q-pa-md">No research jobs queued or running.</td>
              </tr>
              <tr v-for="j in jobs" :key="j.job_id">
                <td class="text-mono text-caption">{{ j.job_id?.substring(0, 8) }}...</td>
                <td><q-badge color="primary">{{ j.kind }}</q-badge></td>
                <td>{{ j.strategy_id || 'All' }}</td>
                <td>
                  <q-badge
                    :color="j.status === 'SUCCEEDED' ? 'positive' : j.status === 'FAILED' ? 'negative' : j.status === 'RUNNING' ? 'warning' : 'grey'"
                  >
                    {{ j.status }}
                  </q-badge>
                </td>
                <td style="min-width: 100px;">
                  <q-linear-progress
                    :value="(j.progress || 0) / 100"
                    :color="j.status === 'SUCCEEDED' ? 'positive' : 'primary'"
                    rounded
                    size="8px"
                  />
                  <div class="text-caption text-grey-6">{{ j.progress }}%</div>
                </td>
                <td>{{ j.runtime_stats?.duration_ms ? `${j.runtime_stats.duration_ms} ms` : '-' }}</td>
                <td class="text-caption">{{ j.requested_at_utc ? new Date(j.requested_at_utc).toLocaleTimeString() : '-' }}</td>
                <td>
                  <q-btn
                    v-if="j.status === 'QUEUED' || j.status === 'RUNNING'"
                    flat
                    dense
                    color="negative"
                    icon="cancel"
                    size="sm"
                    label="Cancel"
                    @click="cancelJob(j.job_id)"
                  />
                  <span v-else class="text-caption text-grey">Finalized</span>
                </td>
              </tr>
            </tbody>
          </q-markup-table>
        </q-card-section>
      </q-card>
    </div>
  </q-page>
</template>

<style scoped>
.eyebrow {
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.1em;
  color: var(--q-primary);
  text-transform: uppercase;
}
.kpi-card {
  border-radius: 8px;
}
.code-box {
  background: #111;
  color: #00e676;
  padding: 12px;
  border-radius: 6px;
  max-height: 280px;
  overflow: auto;
  font-size: 0.8rem;
  line-height: 1.4;
}
</style>
