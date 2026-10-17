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

async function refreshDegradation() {
  loading.value = true;
  error.value = '';
  try {
    const res = await api.get('/research/degradation', { params: { horizon: horizon.value } });
    degradation.value = res.data.data.strategies || [];
  } catch (err: any) {
    error.value = err.message || 'Failed to load degradation data';
  } finally {
    loading.value = false;
  }
}

async function refreshCorrelations() {
  loading.value = true;
  error.value = '';
  try {
    const res = await api.get('/research/correlations', { params: { horizon: horizon.value } });
    correlations.value = res.data.data;
  } catch (err: any) {
    error.value = err.message || 'Failed to load correlations';
  } finally {
    loading.value = false;
  }
}

async function refreshRecommendations() {
  loading.value = true;
  error.value = '';
  try {
    const res = await api.get('/research/recommendations');
    recommendations.value = res.data.data || [];
  } catch (err: any) {
    error.value = err.message || 'Failed to load recommendations';
  } finally {
    loading.value = false;
  }
}

async function refreshReport() {
  loading.value = true;
  error.value = '';
  try {
    const res = await api.get('/research/report', { params: { period: reportPeriod.value } });
    reportData.value = res.data.data;
  } catch (err: any) {
    error.value = err.message || 'Failed to generate audit report';
  } finally {
    loading.value = false;
  }
}

async function acceptRecommendation(id: string) {
  try {
    await api.post(`/research/recommendations/${id}/accept`);
    await refreshRecommendations();
  } catch (err: any) {
    error.value = err.message || 'Failed to accept recommendation';
  }
}

async function dismissRecommendation(id: string) {
  try {
    await api.post(`/research/recommendations/${id}/dismiss`);
    await refreshRecommendations();
  } catch (err: any) {
    error.value = err.message || 'Failed to dismiss recommendation';
  }
}

function handleTabChange(tab: string) {
  activeTab.value = tab;
  if (tab === 'degradation') refreshDegradation();
  else if (tab === 'correlations') refreshCorrelations();
  else if (tab === 'recommendations') refreshRecommendations();
  else if (tab === 'report') refreshReport();
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
              <span v-if="props.row.win_rate !== null">
                {{ (props.row.win_rate * 100).toFixed(1) }}%
                <small class="text-grey">
                  [{{ (props.row.win_rate_ci_lower * 100).toFixed(0) }}%-{{ (props.row.win_rate_ci_upper * 100).toFixed(0) }}%]
                </small>
              </span>
              <span v-else class="text-grey">—</span>
            </q-td>
          </template>
          <template #body-cell-expectancy_r="props">
            <q-td :props="props" :class="Number(props.value) > 0 ? 'text-positive' : (Number(props.value) < 0 ? 'text-negative' : '')">
              {{ props.value !== null ? Number(props.value).toFixed(2) + ' R' : '—' }}
            </q-td>
          </template>
          <template #body-cell-profit_factor="props">
            <q-td :props="props" :class="Number(props.value) >= 1.2 ? 'text-positive' : (Number(props.value) < 1.0 && props.value !== null ? 'text-negative' : '')">
              {{ props.value !== null ? Number(props.value).toFixed(2) : '—' }}
            </q-td>
          </template>
          <template #body-cell-max_drawdown="props">
            <q-td :props="props" :class="Number(props.value) >= 15 ? 'text-negative text-bold' : ''">
              {{ props.value !== null ? Number(props.value).toFixed(2) + '%' : '0%' }}
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
              <div class="text-h5 text-bold" :class="Number(correlations.concentration_score) > 0.5 ? 'text-warning' : 'text-positive'">
                {{ Number(correlations.concentration_score).toFixed(3) }}
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
                    val === 1.0
                      ? 'grey-8'
                      : Number(val) > 0.7
                      ? 'negative'
                      : Number(val) < -0.2
                      ? 'positive'
                      : 'grey-9'
                  "
                  :label="val !== null ? Number(val).toFixed(2) : '—'"
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
