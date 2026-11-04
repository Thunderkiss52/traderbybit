<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useQuasar } from 'quasar';
import {
  approveExchangePaper,
  fetchExchangeCoverage,
  fetchExchangeDetail,
  fetchExchanges,
  registerExchange,
  syncExchangeTopology,
  updateExchangeLifecycle,
  uploadExchangeCandles,
} from '../services/api';
import type {
  Exchange,
  ExchangeCoverageReport,
  HistoricalCandleUploadResult,
} from '../types';

const $q = useQuasar();

const exchanges = ref<Exchange[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);

// Selected exchange for detail/coverage view
const selectedExchangeId = ref<string | null>(null);
const selectedExchange = ref<Exchange | null>(null);
const coverageReport = ref<ExchangeCoverageReport | null>(null);
const coverageLoading = ref(false);

// Register Dialog
const registerDialogOpen = ref(false);
const registerForm = ref({
  id: '',
  name: '',
  market_type: 'linear_perpetual',
  description: '',
  maker_rate: '0.0002',
  taker_rate: '0.0005',
  slippage_rate: '0.0002',
});
const registerLoading = ref(false);

// Upload CSV Dialog
const uploadDialogOpen = ref(false);
const uploadTargetExchange = ref<string>('');
const uploadSymbol = ref('BTCUSDT');
const uploadInterval = ref('5m');
const uploadFile = ref<File | null>(null);
const uploadCsvText = ref('');
const uploadMode = ref<'file' | 'text'>('file');
const uploadLoading = ref(false);
const uploadResult = ref<HistoricalCandleUploadResult | null>(null);
const uploadResultOpen = ref(false);

// Approval Dialog
const approveDialogOpen = ref(false);
const approveTargetExchange = ref<Exchange | null>(null);
const approveReference = ref('OWNER_MANUAL_APPROVAL');
const approveLoading = ref(false);

// Topology Sync Dialog
const syncDialogOpen = ref(false);
const syncTargetExchange = ref<string>('');
const syncApply = ref(false);
const syncLoading = ref(false);
const syncResult = ref<any | null>(null);

const intervals = ['1m', '3m', '5m', '15m', '30m', '1h', '2h', '4h', '6h', '8h', '12h', '1d'];

async function loadExchanges() {
  loading.value = true;
  error.value = null;
  try {
    exchanges.value = await fetchExchanges();
    if (selectedExchangeId.value) {
      await inspectExchange(selectedExchangeId.value);
    }
  } catch (err: any) {
    error.value = err.message || 'Failed to load registered exchanges.';
  } finally {
    loading.value = false;
  }
}

async function inspectExchange(id: string) {
  selectedExchangeId.value = id;
  coverageLoading.value = true;
  try {
    const [detail, cov] = await Promise.all([
      fetchExchangeDetail(id),
      fetchExchangeCoverage(id),
    ]);
    selectedExchange.value = detail;
    coverageReport.value = cov;
  } catch (err: any) {
    $q.notify({
      type: 'negative',
      message: `Failed to load diagnostics for ${id}: ${err.message}`,
    });
  } finally {
    coverageLoading.value = false;
  }
}

function openRegisterDialog() {
  registerForm.value = {
    id: '',
    name: '',
    market_type: 'linear_perpetual',
    description: '',
    maker_rate: '0.0002',
    taker_rate: '0.0005',
    slippage_rate: '0.0002',
  };
  registerDialogOpen.value = true;
}

async function submitRegister() {
  registerLoading.value = true;
  try {
    const payload = {
      id: registerForm.value.id.toLowerCase().trim(),
      name: registerForm.value.name.trim(),
      market_type: registerForm.value.market_type,
      description: registerForm.value.description.trim() || undefined,
      cost_model: {
        version: `${registerForm.value.id}-model-2026.1`,
        market: registerForm.value.market_type,
        fee_tier: 'Standard',
        maker_rate: registerForm.value.maker_rate,
        taker_rate: registerForm.value.taker_rate,
        slippage_rate: registerForm.value.slippage_rate,
      },
    };
    const res = await registerExchange(payload);
    $q.notify({ type: 'positive', message: res.message });
    registerDialogOpen.value = false;
    await loadExchanges();
    await inspectExchange(payload.id);
  } catch (err: any) {
    $q.notify({ type: 'negative', message: err.message || 'Registration failed.' });
  } finally {
    registerLoading.value = false;
  }
}

function openUploadDialog(exchangeId: string) {
  uploadTargetExchange.value = exchangeId;
  uploadSymbol.value = 'BTCUSDT';
  uploadInterval.value = '5m';
  uploadFile.value = null;
  uploadCsvText.value = '';
  uploadMode.value = 'file';
  uploadDialogOpen.value = true;
}

async function submitUpload() {
  if (uploadMode.value === 'file' && !uploadFile.value) {
    $q.notify({ type: 'warning', message: 'Please select a CSV file to upload.' });
    return;
  }
  if (uploadMode.value === 'text' && !uploadCsvText.value.trim()) {
    $q.notify({ type: 'warning', message: 'Please enter CSV content.' });
    return;
  }

  uploadLoading.value = true;
  try {
    let payload: FormData | object;
    if (uploadMode.value === 'file' && uploadFile.value) {
      const formData = new FormData();
      formData.append('symbol', uploadSymbol.value.toUpperCase().trim());
      formData.append('interval', uploadInterval.value);
      formData.append('category', 'linear');
      formData.append('file', uploadFile.value);
      payload = formData;
    } else {
      payload = {
        symbol: uploadSymbol.value.toUpperCase().trim(),
        interval: uploadInterval.value,
        category: 'linear',
        csv: uploadCsvText.value,
      };
    }

    const res = await uploadExchangeCandles(uploadTargetExchange.value, payload);
    uploadResult.value = res.data;
    uploadDialogOpen.value = false;
    uploadResultOpen.value = true;
    $q.notify({ type: 'positive', message: res.message });
    await loadExchanges();
  } catch (err: any) {
    $q.notify({ type: 'negative', message: err.message || 'CSV upload failed.' });
  } finally {
    uploadLoading.value = false;
  }
}

async function enableResearch(exchangeId: string) {
  try {
    const res = await updateExchangeLifecycle(exchangeId, 'RESEARCH_ENABLED');
    $q.notify({ type: 'positive', message: res.message });
    await loadExchanges();
  } catch (err: any) {
    $q.notify({ type: 'negative', message: err.message || 'Failed to transition lifecycle.' });
  }
}

function openApproveDialog(exchange: Exchange) {
  approveTargetExchange.value = exchange;
  approveReference.value = 'OWNER_MANUAL_APPROVAL';
  approveDialogOpen.value = true;
}

async function submitApprove() {
  if (!approveTargetExchange.value) return;
  approveLoading.value = true;
  try {
    const res = await approveExchangePaper(
      approveTargetExchange.value.id,
      true,
      approveReference.value
    );
    $q.notify({ type: 'positive', message: res.message });
    approveDialogOpen.value = false;
    await loadExchanges();
  } catch (err: any) {
    $q.notify({ type: 'negative', message: err.message || 'PAPER approval rejected.' });
  } finally {
    approveLoading.value = false;
  }
}

function openSyncDialog(exchangeId: string) {
  syncTargetExchange.value = exchangeId;
  syncApply.value = false;
  syncResult.value = null;
  syncDialogOpen.value = true;
}

async function submitSync() {
  syncLoading.value = true;
  try {
    const res = await syncExchangeTopology(syncTargetExchange.value, syncApply.value);
    syncResult.value = res.data;
    $q.notify({ type: 'positive', message: res.message });
    if (syncApply.value) {
      await loadExchanges();
    }
  } catch (err: any) {
    $q.notify({ type: 'negative', message: err.message || 'Topology sync failed.' });
  } finally {
    syncLoading.value = false;
  }
}

function getStatusColor(status: string) {
  switch (status) {
    case 'ACTIVE_PAPER':
      return 'positive';
    case 'PAPER_ELIGIBLE':
      return 'warning';
    case 'RESEARCH_ENABLED':
      return 'secondary';
    case 'CONNECTED_PUBLIC_DATA':
      return 'info';
    case 'OFFLINE_DATA_SOURCE':
    default:
      return 'grey-7';
  }
}

const statusDescriptions: Record<string, string> = {
  OFFLINE_DATA_SOURCE: 'Historical CSV data source only. No live API connection or paper trading.',
  CONNECTED_PUBLIC_DATA: 'Public REST/WS market data connected for real-time candles.',
  RESEARCH_ENABLED: 'Verified historical candle coverage for multi-strategy backtesting.',
  PAPER_ELIGIBLE: 'All technical prerequisites met (adapter, cost model, timeframes, 0 conflicts).',
  ACTIVE_PAPER: 'Owner-approved active PAPER trading with 13 isolated strategy accounts.',
};

onMounted(() => {
  loadExchanges();
});
</script>

<template>
  <q-page class="q-pa-md">
    <!-- Header -->
    <div class="row items-center justify-between q-mb-md">
      <div>
        <div class="text-h5 text-weight-bold">Exchange Onboarding & Management</div>
        <div class="text-caption text-grey-6">
          Add research data sources, manage capability declarations, import historical CSVs, and provision isolated PAPER topologies.
        </div>
      </div>
      <div class="row q-gutter-sm">
        <q-btn
          outline
          color="primary"
          icon="refresh"
          label="Refresh"
          :loading="loading"
          @click="loadExchanges"
        />
        <q-btn
          unelevated
          color="primary"
          icon="add_business"
          label="Register Exchange"
          @click="openRegisterDialog"
        />
      </div>
    </div>

    <!-- Mandatory Research Disclaimer -->
    <q-banner dense rounded class="bg-blue-grey-10 text-white q-mb-md">
      <template #avatar>
        <q-icon name="security" color="primary" />
      </template>
      <div class="text-caption">
        <strong>VIRTUAL PAPER TRADING ONLY:</strong> VECTOR operates exclusively in research and virtual paper trading mode.
        All API integrations are strictly read-only public market data. Zero live exchange orders, withdrawals, or trade credentials exist.
        Adding or activating an exchange provisions isolated PAPER accounts in <code>BLOCKED</code> status without expanding the global 5% portfolio risk budget.
      </div>
    </q-banner>

    <!-- Error Banner -->
    <q-banner v-if="error" dense rounded class="bg-negative text-white q-mb-md">
      {{ error }}
    </q-banner>

    <!-- Exchanges Grid -->
    <div class="row q-col-gutter-md q-mb-lg">
      <div
        v-for="exchange in exchanges"
        :key="exchange.id"
        class="col-12 col-md-6 col-lg-4"
      >
        <q-card
          bordered
          flat
          class="exchange-card full-height column justify-between"
          :class="{ 'card-active': selectedExchangeId === exchange.id }"
        >
          <q-card-section>
            <div class="row items-center justify-between no-wrap q-mb-xs">
              <div class="text-subtitle1 text-weight-bold row items-center no-wrap">
                <q-icon
                  :name="exchange.has_live_adapter ? 'swap_horiz' : 'folder_zip'"
                  size="20px"
                  class="q-mr-xs text-primary"
                />
                {{ exchange.name }}
              </div>
              <q-badge
                :color="getStatusColor(exchange.lifecycle_status)"
                :label="exchange.lifecycle_status"
                class="q-px-sm q-py-xs"
              />
            </div>

            <div class="text-caption text-grey-5 q-mb-sm">
              <code>{{ exchange.id }}</code> &bull; {{ exchange.market_type }}
            </div>

            <p class="text-caption text-grey-4 q-mb-md ellipsis-2-lines">
              {{ exchange.description || 'No description provided.' }}
            </p>

            <q-separator class="q-my-sm" />

            <!-- Metrics Summary -->
            <div class="row q-col-gutter-xs text-caption">
              <div class="col-6">
                <div class="text-grey-5">Historical Candles</div>
                <div class="text-weight-bold">{{ exchange.candle_count?.toLocaleString() || 0 }} bars</div>
              </div>
              <div class="col-6">
                <div class="text-grey-5">Unique Symbols</div>
                <div class="text-weight-bold">{{ exchange.distinct_symbols || 0 }}</div>
              </div>
              <div class="col-6 q-mt-xs">
                <div class="text-grey-5">Live Adapter</div>
                <div class="text-weight-bold">
                  <q-icon
                    :name="exchange.has_live_adapter ? 'check_circle' : 'cancel'"
                    :color="exchange.has_live_adapter ? 'positive' : 'grey-6'"
                    size="14px"
                  />
                  {{ exchange.has_live_adapter ? 'Compiled' : 'Generic/CSV' }}
                </div>
              </div>
              <div class="col-6 q-mt-xs">
                <div class="text-grey-5">PAPER Approved</div>
                <div class="text-weight-bold">
                  <q-icon
                    :name="exchange.paper_approved ? 'verified' : 'hourglass_empty'"
                    :color="exchange.paper_approved ? 'positive' : 'warning'"
                    size="14px"
                  />
                  {{ exchange.paper_approved ? 'Active' : 'Unapproved' }}
                </div>
              </div>
            </div>

            <div v-if="exchange.cost_model" class="q-mt-sm text-caption text-grey-5">
              Cost: Taker {{ (parseFloat(exchange.cost_model.taker_rate) * 10000).toFixed(1) }} bps &bull;
              Slippage {{ (parseFloat(exchange.cost_model.slippage_rate) * 10000).toFixed(1) }} bps
            </div>
          </q-card-section>

          <q-separator />

          <q-card-actions align="between" class="bg-grey-10 q-px-sm">
            <q-btn
              flat
              dense
              size="sm"
              color="primary"
              icon="query_stats"
              label="Diagnostics"
              @click="inspectExchange(exchange.id)"
            />
            <div class="row q-gutter-xs">
              <q-btn
                flat
                dense
                size="sm"
                color="info"
                icon="upload_file"
                label="CSV"
                @click="openUploadDialog(exchange.id)"
              />
              <q-btn
                v-if="exchange.lifecycle_status === 'OFFLINE_DATA_SOURCE' && (exchange.candle_count || 0) > 0"
                flat
                dense
                size="sm"
                color="secondary"
                icon="science"
                label="Enable Research"
                @click="enableResearch(exchange.id)"
              />
              <q-btn
                v-if="exchange.paper_eligible && !exchange.paper_approved"
                flat
                dense
                size="sm"
                color="warning"
                icon="gavel"
                label="Approve PAPER"
                @click="openApproveDialog(exchange)"
              />
              <q-btn
                v-if="exchange.paper_approved"
                flat
                dense
                size="sm"
                color="positive"
                icon="sync_alt"
                label="Topology"
                @click="openSyncDialog(exchange.id)"
              />
            </div>
          </q-card-actions>
        </q-card>
      </div>
    </div>

    <!-- Diagnostics & Historical Coverage Section -->
    <div v-if="selectedExchange" class="q-mt-md">
      <q-card bordered flat class="bg-dark">
        <q-card-section class="row items-center justify-between">
          <div class="row items-center q-gutter-sm">
            <q-avatar icon="analytics" color="primary" text-color="black" />
            <div>
              <div class="text-h6 text-weight-bold">
                {{ selectedExchange.name }} (<code>{{ selectedExchange.id }}</code>) Diagnostics & Coverage
              </div>
              <div class="text-caption text-grey-5">
                Lifecycle State: <strong>{{ selectedExchange.lifecycle_status }}</strong> &bull;
                {{ statusDescriptions[selectedExchange.lifecycle_status] }}
              </div>
            </div>
          </div>
          <q-btn
            outline
            dense
            color="primary"
            icon="upload_file"
            label="Upload CSV to this Exchange"
            @click="openUploadDialog(selectedExchange.id)"
          />
        </q-card-section>

        <q-separator />

        <div class="row q-col-gutter-md q-pa-md">
          <!-- Capabilities Matrix -->
          <div class="col-12 col-md-4">
            <q-card bordered flat class="bg-grey-10 full-height">
              <q-card-section>
                <div class="text-subtitle2 text-weight-bold q-mb-sm">Declared Capabilities</div>
                <q-list dense>
                  <q-item>
                    <q-item-section>Historical Candles</q-item-section>
                    <q-item-section side>
                      <q-badge :color="selectedExchange.capabilities?.has_candles ? 'positive' : 'grey-7'">
                        {{ selectedExchange.capabilities?.has_candles ? 'YES' : 'NO' }}
                      </q-badge>
                    </q-item-section>
                  </q-item>
                  <q-item>
                    <q-item-section>Funding Rates Ingestion</q-item-section>
                    <q-item-section side>
                      <q-badge :color="selectedExchange.capabilities?.has_funding ? 'positive' : 'grey-7'">
                        {{ selectedExchange.capabilities?.has_funding ? 'YES' : 'NO' }}
                      </q-badge>
                    </q-item-section>
                  </q-item>
                  <q-item>
                    <q-item-section>Open Interest Ingestion</q-item-section>
                    <q-item-section side>
                      <q-badge :color="selectedExchange.capabilities?.has_open_interest ? 'positive' : 'grey-7'">
                        {{ selectedExchange.capabilities?.has_open_interest ? 'YES' : 'NO' }}
                      </q-badge>
                    </q-item-section>
                  </q-item>
                  <q-item>
                    <q-item-section>Live WebSocket/REST Adapter</q-item-section>
                    <q-item-section side>
                      <q-badge :color="selectedExchange.has_live_adapter ? 'positive' : 'warning'">
                        {{ selectedExchange.has_live_adapter ? 'COMPILED' : 'NONE' }}
                      </q-badge>
                    </q-item-section>
                  </q-item>
                  <q-item>
                    <q-item-section>Supported Timeframes</q-item-section>
                    <q-item-section side class="text-caption">
                      {{ selectedExchange.capabilities?.supported_timeframes?.join(', ') || 'None' }}
                    </q-item-section>
                  </q-item>
                  <q-item>
                    <q-item-section>Price Precision</q-item-section>
                    <q-item-section side class="text-caption">
                      {{ selectedExchange.capabilities?.price_precision ?? 2 }} decimals
                    </q-item-section>
                  </q-item>
                  <q-item>
                    <q-item-section>Min Quantity / Notional</q-item-section>
                    <q-item-section side class="text-caption">
                      {{ selectedExchange.capabilities?.min_qty }} / {{ selectedExchange.capabilities?.min_notional }} USDT
                    </q-item-section>
                  </q-item>
                </q-list>

                <div class="text-subtitle2 text-weight-bold q-mt-md q-mb-xs">Cost & Sizing Model</div>
                <div class="text-caption text-grey-4">
                  <div>Fee Tier: <strong>{{ selectedExchange.cost_model?.fee_tier || 'Standard' }}</strong></div>
                  <div>Maker Rate: <code>{{ selectedExchange.cost_model?.maker_rate }}</code></div>
                  <div>Taker Rate: <code>{{ selectedExchange.cost_model?.taker_rate }}</code></div>
                  <div>Slippage Rate: <code>{{ selectedExchange.cost_model?.slippage_rate }}</code></div>
                </div>
              </q-card-section>
            </q-card>
          </div>

          <!-- Eligibility Evaluation -->
          <div class="col-12 col-md-8">
            <q-card bordered flat class="bg-grey-10 full-height">
              <q-card-section>
                <div class="row items-center justify-between q-mb-sm">
                  <div class="text-subtitle2 text-weight-bold">Technical PAPER Eligibility Audit</div>
                  <q-badge
                    :color="selectedExchange.eligibility?.eligible ? 'positive' : 'warning'"
                    :label="selectedExchange.eligibility?.eligible ? 'PAPER ELIGIBLE' : 'PAPER INELIGIBLE'"
                    class="q-px-sm"
                  />
                </div>

                <div v-if="!selectedExchange.eligibility?.eligible" class="q-mb-md">
                  <div class="text-caption text-negative text-weight-bold q-mb-xs">
                    Blockers preventing PAPER activation:
                  </div>
                  <ul class="q-pl-md q-ma-none text-caption text-grey-4">
                    <li v-for="reason in selectedExchange.eligibility?.reasons_missing" :key="reason">
                      {{ reason }}
                    </li>
                  </ul>
                </div>
                <div v-else class="text-caption text-positive q-mb-md">
                  All technical invariants satisfied. This exchange is eligible for owner-approved PAPER forward execution.
                </div>

                <q-separator class="q-my-sm" />

                <!-- Series Coverage Table -->
                <div class="text-subtitle2 text-weight-bold q-mb-xs">Historical Series Coverage</div>
                <div v-if="coverageLoading" class="row justify-center q-pa-md">
                  <q-spinner color="primary" size="30px" />
                </div>
                <div v-else-if="!coverageReport?.series?.length" class="text-caption text-grey-5 q-pa-sm">
                  No candles recorded for this exchange. Upload historical CSV datasets to enable multi-timeframe backtesting.
                </div>
                <q-markup-table v-else dense flat bordered class="bg-dark text-caption">
                  <thead>
                    <tr>
                      <th class="text-left">Symbol</th>
                      <th class="text-left">Interval</th>
                      <th class="text-right">Bars</th>
                      <th class="text-right">Span (h)</th>
                      <th class="text-right">Completeness</th>
                      <th class="text-right">Freshness</th>
                      <th class="text-left">Earliest UTC</th>
                      <th class="text-left">Latest UTC</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="s in coverageReport.series" :key="s.symbol + s.interval">
                      <td class="text-weight-bold">{{ s.symbol }}</td>
                      <td><code>{{ s.interval }}</code></td>
                      <td class="text-right">{{ s.count.toLocaleString() }}</td>
                      <td class="text-right">{{ s.span_hours }}h</td>
                      <td class="text-right">
                        <q-badge
                          :color="s.completeness_percent >= 99 ? 'positive' : (s.completeness_percent >= 80 ? 'warning' : 'negative')"
                        >
                          {{ s.completeness_percent }}%
                        </q-badge>
                      </td>
                      <td class="text-right">
                        <q-icon
                          :name="s.is_fresh ? 'cloud_done' : 'cloud_off'"
                          :color="s.is_fresh ? 'positive' : 'grey-6'"
                          size="14px"
                        />
                        {{ s.is_fresh ? 'Fresh' : (s.lag_seconds ? `${Math.round(s.lag_seconds / 3600)}h lag` : 'Offline') }}
                      </td>
                      <td class="text-grey-5">{{ s.earliest ? s.earliest.slice(0, 19).replace('T', ' ') : '—' }}</td>
                      <td class="text-grey-5">{{ s.latest ? s.latest.slice(0, 19).replace('T', ' ') : '—' }}</td>
                    </tr>
                  </tbody>
                </q-markup-table>
              </q-card-section>
            </q-card>
          </div>
        </div>
      </q-card>
    </div>

    <!-- Register Dialog -->
    <q-dialog v-model="registerDialogOpen" persistent>
      <q-card style="min-width: 480px" class="bg-dark text-white">
        <q-card-section class="row items-center justify-between">
          <div class="text-h6 text-weight-bold">Register New Exchange</div>
          <q-btn flat round dense icon="close" v-close-popup />
        </q-card-section>
        <q-separator />
        <q-card-section class="q-gutter-sm">
          <q-input
            v-model="registerForm.id"
            label="Exchange Identifier (slug)"
            hint="Lowercase alphanumeric, 2-32 chars (e.g. okx, hyperliquid)"
            dense
            outlined
            :rules="[v => !!v || 'ID is required', v => /^[a-z0-9_-]+$/.test(v) || 'Invalid slug format']"
          />
          <q-input
            v-model="registerForm.name"
            label="Display Name"
            dense
            outlined
            :rules="[v => !!v || 'Name is required']"
          />
          <q-select
            v-model="registerForm.market_type"
            :options="['linear_perpetual', 'spot', 'inverse_perpetual']"
            label="Market Type"
            dense
            outlined
          />
          <q-input
            v-model="registerForm.description"
            label="Description"
            dense
            outlined
            type="textarea"
            rows="2"
          />
          <div class="text-caption text-grey-5 q-mt-sm">Initial Cost Schedule</div>
          <div class="row q-col-gutter-sm">
            <div class="col-4">
              <q-input v-model="registerForm.maker_rate" label="Maker Rate" dense outlined />
            </div>
            <div class="col-4">
              <q-input v-model="registerForm.taker_rate" label="Taker Rate" dense outlined />
            </div>
            <div class="col-4">
              <q-input v-model="registerForm.slippage_rate" label="Slippage Rate" dense outlined />
            </div>
          </div>
        </q-card-section>
        <q-separator />
        <q-card-actions align="right" class="q-pa-md">
          <q-btn flat label="Cancel" v-close-popup />
          <q-btn
            unelevated
            color="primary"
            label="Register"
            :loading="registerLoading"
            @click="submitRegister"
          />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <!-- Upload CSV Dialog -->
    <q-dialog v-model="uploadDialogOpen" persistent>
      <q-card style="min-width: 520px" class="bg-dark text-white">
        <q-card-section class="row items-center justify-between">
          <div class="text-h6 text-weight-bold">
            Upload Historical CSV to <code>{{ uploadTargetExchange }}</code>
          </div>
          <q-btn flat round dense icon="close" v-close-popup />
        </q-card-section>
        <q-separator />
        <q-card-section class="q-gutter-sm">
          <div class="row q-col-gutter-sm">
            <div class="col-6">
              <q-input
                v-model="uploadSymbol"
                label="Symbol"
                hint="e.g. BTCUSDT, ETHUSDT"
                dense
                outlined
              />
            </div>
            <div class="col-6">
              <q-select
                v-model="uploadInterval"
                :options="intervals"
                label="Interval"
                dense
                outlined
              />
            </div>
          </div>

          <q-btn-toggle
            v-model="uploadMode"
            dense
            rounded
            unelevated
            toggle-color="primary"
            color="grey-9"
            class="q-my-sm"
            :options="[
              { label: 'Upload File', value: 'file' },
              { label: 'Paste CSV Text', value: 'text' },
            ]"
          />

          <div v-if="uploadMode === 'file'">
            <q-file
              v-model="uploadFile"
              label="Select CSV File"
              accept=".csv,.txt"
              outlined
              dense
              clearable
            >
              <template #prepend>
                <q-icon name="attach_file" />
              </template>
            </q-file>
          </div>
          <div v-else>
            <q-input
              v-model="uploadCsvText"
              label="CSV Content"
              hint="Header must include: timestamp/open_time, open, high, low, close, volume"
              dense
              outlined
              type="textarea"
              rows="6"
            />
          </div>

          <div class="text-caption text-grey-5 q-mt-xs">
            * Timestamps must be monotonic and aligned to the {{ uploadInterval }} boundary in UTC.
            Identical existing candles are safely skipped. Conflicting values are recorded in the tamper-evident audit journal.
          </div>
        </q-card-section>
        <q-separator />
        <q-card-actions align="right" class="q-pa-md">
          <q-btn flat label="Cancel" v-close-popup />
          <q-btn
            unelevated
            color="primary"
            label="Import Dataset"
            :loading="uploadLoading"
            @click="submitUpload"
          />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <!-- Upload Result Summary Dialog -->
    <q-dialog v-model="uploadResultOpen">
      <q-card style="min-width: 440px" class="bg-dark text-white">
        <q-card-section class="row items-center">
          <q-avatar icon="check_circle" color="positive" text-color="black" class="q-mr-sm" />
          <div class="text-subtitle1 text-weight-bold">CSV Import Completed</div>
        </q-card-section>
        <q-separator />
        <q-card-section v-if="uploadResult" class="q-gutter-xs text-caption">
          <div>Exchange: <strong>{{ uploadResult.exchange_id }}</strong></div>
          <div>Series: <strong>{{ uploadResult.symbol }} ({{ uploadResult.interval }})</strong></div>
          <div>Imported Bars: <strong class="text-positive">{{ uploadResult.imported_bars }}</strong></div>
          <div>Duplicates Skipped: <strong class="text-info">{{ uploadResult.duplicates_skipped }}</strong></div>
          <div>Conflicts Recorded: <strong :class="uploadResult.conflicts_recorded > 0 ? 'text-warning' : 'text-grey-5'">{{ uploadResult.conflicts_recorded }}</strong></div>
          <div>First Bar: <code>{{ uploadResult.first_bar_open_time || '—' }}</code></div>
          <div>Last Bar: <code>{{ uploadResult.last_bar_open_time || '—' }}</code></div>
          <div class="ellipsis">Checksum (SHA-256): <code>{{ uploadResult.checksum }}</code></div>
        </q-card-section>
        <q-separator />
        <q-card-actions align="right">
          <q-btn flat label="Close" color="primary" v-close-popup />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <!-- Approve PAPER Dialog -->
    <q-dialog v-model="approveDialogOpen" persistent>
      <q-card style="min-width: 460px" class="bg-dark text-white">
        <q-card-section class="row items-center">
          <q-avatar icon="gavel" color="warning" text-color="black" class="q-mr-sm" />
          <div class="text-subtitle1 text-weight-bold">Owner Approval: Activate PAPER Execution</div>
        </q-card-section>
        <q-separator />
        <q-card-section class="q-gutter-sm text-caption">
          <p>
            You are granting explicit authorization to mark exchange
            <code>{{ approveTargetExchange?.id }}</code> as <strong>ACTIVE_PAPER</strong>.
          </p>
          <p class="text-warning">
            Note: Activating PAPER execution does NOT trade with real exchange funds.
            It enables forward paper execution across isolated virtual strategy accounts.
            Provisioned accounts will start in <code>BLOCKED</code> status with 0 balance.
          </p>
          <q-input
            v-model="approveReference"
            label="Approval Reference Code"
            dense
            outlined
          />
        </q-card-section>
        <q-separator />
        <q-card-actions align="right" class="q-pa-md">
          <q-btn flat label="Cancel" v-close-popup />
          <q-btn
            unelevated
            color="warning"
            text-color="black"
            label="Approve Activation"
            :loading="approveLoading"
            @click="submitApprove"
          />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <!-- Sync Topology Dialog -->
    <q-dialog v-model="syncDialogOpen" persistent>
      <q-card style="min-width: 480px" class="bg-dark text-white">
        <q-card-section class="row items-center justify-between">
          <div class="text-subtitle1 text-weight-bold">
            Synchronize Topology for <code>{{ syncTargetExchange }}</code>
          </div>
          <q-btn flat round dense icon="close" v-close-popup />
        </q-card-section>
        <q-separator />
        <q-card-section class="q-gutter-sm text-caption">
          <p>
            Reconciles the primary strategy x exchange topology.
            Approved exchanges receive 13 independent strategy forward accounts in <code>BLOCKED</code> status.
          </p>
          <q-toggle
            v-model="syncApply"
            label="Persist Changes (Apply)"
            color="positive"
          />
          <div v-if="!syncApply" class="text-info">
            Currently in DRY-RUN mode. Invariants will be tested without modifying the database.
          </div>
          <div v-else class="text-warning">
            APPLY mode: Missing canonical primary accounts will be safely provisioned.
          </div>

          <div v-if="syncResult" class="q-mt-md bg-grey-10 q-pa-sm rounded-borders">
            <div class="text-weight-bold q-mb-xs">Result Summary:</div>
            <div>Mode: <strong>{{ syncResult.mode }}</strong></div>
            <div>Expected Pairs: <strong>{{ syncResult.expected_pairs }}</strong></div>
            <div v-if="syncResult.missing_pairs">
              Missing Pairs: <strong>{{ syncResult.missing_pairs.length }}</strong>
            </div>
            <div v-if="syncResult.created_other_blocked_unfunded !== undefined">
              Created Accounts: <strong>{{ syncResult.created_other_blocked_unfunded }}</strong>
            </div>
          </div>
        </q-card-section>
        <q-separator />
        <q-card-actions align="right" class="q-pa-md">
          <q-btn flat label="Close" v-close-popup />
          <q-btn
            unelevated
            :color="syncApply ? 'positive' : 'primary'"
            :label="syncApply ? 'Apply Provisioning' : 'Run Dry-Run'"
            :loading="syncLoading"
            @click="submitSync"
          />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </q-page>
</template>

<style scoped>
.exchange-card {
  background: #181c24;
  border-color: #2a313d;
  transition: all 0.2s ease-in-out;
}
.exchange-card:hover {
  border-color: #3f4b5e;
}
.card-active {
  border-color: #00e676 !important;
  box-shadow: 0 0 10px rgba(0, 230, 118, 0.2);
}
</style>
