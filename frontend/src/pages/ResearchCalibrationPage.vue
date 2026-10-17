<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { api } from '../services/api';

const symbol = ref('BTCUSDT');
const loading = ref(false);
const error = ref('');
const calibration = ref<any>(null);

async function refresh() {
  loading.value = true;
  error.value = '';
  try {
    const res = await api.get('/research/calibration', {
      params: { symbol: symbol.value || undefined },
    });
    calibration.value = res.data.data;
  } catch (err: any) {
    error.value = err.message || 'Failed to load calibration report';
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  refresh();
});
</script>

<template>
  <q-page padding>
    <div class="page-title row items-center justify-between q-mb-md">
      <div>
        <span class="eyebrow">STAGE II · FORECAST VERIFICATION</span>
        <h1>Probability Forecast Calibration</h1>
      </div>
      <div class="row q-gutter-sm items-center">
        <q-btn-toggle
          v-model="symbol"
          no-caps
          unelevated
          toggle-color="primary"
          :options="[
            { label: 'BTCUSDT', value: 'BTCUSDT' },
            { label: 'ETHUSDT', value: 'ETHUSDT' },
            { label: 'All Symbols', value: '' },
          ]"
          @update:model-value="refresh"
        />
        <q-btn flat icon="refresh" :loading="loading" label="Refresh" @click="refresh" />
      </div>
    </div>

    <q-banner v-if="error" class="bg-negative text-white q-mb-md" rounded dense>
      {{ error }}
    </q-banner>

    <div v-if="calibration">
      <!-- High level KPIs -->
      <div class="row q-col-gutter-md q-mb-md">
        <div class="col-12 col-md-3">
          <q-card flat bordered class="kpi-card">
            <q-card-section>
              <div class="text-caption text-grey">Total Evaluated Forecasts</div>
              <div class="text-h5 text-bold">{{ calibration.total_samples }}</div>
              <div class="text-caption text-grey-6">Matured probabilistic predictions</div>
            </q-card-section>
          </q-card>
        </div>

        <div class="col-12 col-md-3">
          <q-card flat bordered class="kpi-card">
            <q-card-section>
              <div class="text-caption text-grey">Brier Score</div>
              <div class="text-h5 text-bold" :class="calibration.brier_score !== null && calibration.brier_score <= 0.25 ? 'text-positive' : 'text-warning'">
                {{ calibration.brier_score !== null ? calibration.brier_score.toFixed(4) : '—' }}
              </div>
              <div class="text-caption text-grey-6">Mean squared error (<= 0.25 beats random)</div>
            </q-card-section>
          </q-card>
        </div>

        <div class="col-12 col-md-3">
          <q-card flat bordered class="kpi-card">
            <q-card-section>
              <div class="text-caption text-grey">Expected Calibration Error (ECE)</div>
              <div class="text-h5 text-bold" :class="calibration.ece !== null && calibration.ece <= 0.10 ? 'text-positive' : 'text-warning'">
                {{ calibration.ece !== null ? (calibration.ece * 100).toFixed(2) + '%' : '—' }}
              </div>
              <div class="text-caption text-grey-6">Weighted bin deviation</div>
            </q-card-section>
          </q-card>
        </div>

        <div class="col-12 col-md-3">
          <q-card flat bordered class="kpi-card">
            <q-card-section>
              <div class="text-caption text-grey">Model Calibration Status</div>
              <div class="text-h5 text-bold" :class="calibration.status === 'CALIBRATED' ? 'text-positive' : 'text-info'">
                {{ calibration.status }}
              </div>
              <div class="text-caption text-grey-6">Forecast reliability verdict</div>
            </q-card-section>
          </q-card>
        </div>
      </div>

      <!-- Reliability Diagram & Bins -->
      <q-card flat bordered>
        <q-card-section>
          <div class="text-subtitle1 text-bold">Reliability Diagram (Empirical vs Predicted)</div>
          <div class="text-caption text-grey">
            In a well-calibrated forecast, when the model predicts a 70% probability of a bounce/breakout, approximately 70% of those events should occur.
          </div>
        </q-card-section>
        <q-separator />
        <q-table
          flat
          :rows="calibration.reliability_bins || []"
          :columns="[
            { name: 'bin', label: 'Probability Bin', field: 'bin', align: 'left' },
            { name: 'count', label: 'Observations', field: 'count', align: 'right' },
            { name: 'mean_predicted', label: 'Mean Predicted Prob', field: 'mean_predicted', align: 'right' },
            { name: 'empirical_rate', label: 'Empirical Hit Rate', field: 'empirical_rate', align: 'right' },
            { name: 'gap', label: 'Calibration Gap', field: 'gap', align: 'right' },
          ]"
          row-key="bin"
          hide-pagination
          :pagination="{ rowsPerPage: 0 }"
        >
          <template #body-cell-mean_predicted="props">
            <q-td :props="props">
              {{ (props.value * 100).toFixed(1) }}%
            </q-td>
          </template>
          <template #body-cell-empirical_rate="props">
            <q-td :props="props" class="text-bold">
              {{ (props.value * 100).toFixed(1) }}%
            </q-td>
          </template>
          <template #body-cell-gap="props">
            <q-td :props="props" :class="Math.abs(props.value) <= 0.08 ? 'text-positive' : 'text-warning'">
              {{ (props.value * 100).toFixed(1) }}%
            </q-td>
          </template>
          <template #no-data>
            <div class="full-width row flex-center q-pa-lg text-grey">
              No matured forecast outcomes available for calibration evaluation.
            </div>
          </template>
        </q-table>
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
</style>
