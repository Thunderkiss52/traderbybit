<script setup lang="ts">
import { onMounted, ref } from 'vue';
import {
  fetchQimAlerts,
  fetchQimComparisons,
  fetchQimIncidents,
  fetchQimStatus,
  fetchQimSummary,
  transitionQimIncident,
  type QimAlert,
  type QimComparison,
  type QimIncident,
  type QimStatus,
  type QimSummary,
} from '../services/quoteIntegrityApi';

import QimAlertsPanel from '../components/qim/QimAlertsPanel.vue';
import QimComparisonsTable from '../components/qim/QimComparisonsTable.vue';
import QimEvidenceImport from '../components/qim/QimEvidenceImport.vue';
import QimIncidentDetail from '../components/qim/QimIncidentDetail.vue';
import QimIncidentsTable from '../components/qim/QimIncidentsTable.vue';
import QimStatusBanner from '../components/qim/QimStatusBanner.vue';
import QimSummaryCards from '../components/qim/QimSummaryCards.vue';

const status = ref<QimStatus | null>(null);
const summary = ref<QimSummary | null>(null);
const comparisons = ref<QimComparison[]>([]);
const incidents = ref<QimIncident[]>([]);
const alerts = ref<QimAlert[]>([]);
const selectedIncident = ref<QimIncident | null>(null);

const loading = ref(false);
const disabled = ref(false);
const errorMessage = ref<string | null>(null);
const isStale = ref(false);

async function loadData(filters?: { symbol?: string; classification?: string }) {
  loading.value = true;
  errorMessage.value = null;
  try {
    const s = await fetchQimStatus();
    status.value = s;
    disabled.value = !s.feature_enabled;
    isStale.value = false;

    if (s.feature_enabled) {
      const [sum, comp, inc, alt] = await Promise.all([
        fetchQimSummary(),
        fetchQimComparisons(filters),
        fetchQimIncidents(),
        fetchQimAlerts(),
      ]);
      summary.value = sum;
      comparisons.value = comp.data ?? [];
      incidents.value = inc.data ?? [];
      alerts.value = alt.data ?? [];
    }
  } catch (err: any) {
    const code = err?.response?.data?.error?.code || '';
    if (code === 'FEATURE_DISABLED' || (err.message && err.message.includes('FEATURE_DISABLED')) || err?.response?.status === 503) {
      disabled.value = true;
      errorMessage.value = 'Quote Integrity Monitor is disabled or service is unavailable (503).';
    } else {
      errorMessage.value = err?.response?.data?.message || err?.message || 'Network or server error while querying Quote Integrity API.';
    }
    if (status.value !== null) {
      isStale.value = true;
    }
  } finally {
    loading.value = false;
  }
}

async function handleTransition(data: { status: string; explanation: string; token: string }) {
  if (!selectedIncident.value) return;
  try {
    const updated = await transitionQimIncident(
      selectedIncident.value.id,
      data.status,
      data.explanation,
      data.token
    );
    selectedIncident.value = updated;
    await loadData();
  } catch (err: any) {
    alert(err.message || 'Transition failed');
  }
}

onMounted(() => {
  loadData();
});
</script>

<template>
  <q-page padding class="qim-page bg-grey-10 text-white">
    <div class="row items-center justify-between q-mb-md">
      <div>
        <div class="text-h5 text-weight-bold">
          Quote Integrity Monitor (QIM)
        </div>
        <div class="text-caption text-grey-4">
          Internal Level 1 Consistency & Provenance Verification
        </div>
      </div>
      <div>
        <q-btn
          color="primary"
          icon="refresh"
          label="Refresh"
          dense
          flat
          :loading="loading"
          @click="loadData()"
        />
      </div>
    </div>

    <!-- Visible Error & Stale Data Banner -->
    <q-banner v-if="errorMessage" class="bg-negative text-white q-mb-md" rounded>
      <template #avatar>
        <q-icon name="error_outline" color="white" />
      </template>
      <div class="text-weight-bold">Quote Integrity Monitor Notice</div>
      <div>{{ errorMessage }}</div>
      <div v-if="isStale" class="text-caption text-warning q-mt-xs">
        Preserving last-known good state. Data shown below may be stale.
      </div>
    </q-banner>

    <!-- Status & Mandatory Banners -->
    <QimStatusBanner :status="status" :disabled="disabled" />

    <!-- Summary Metrics -->
    <QimSummaryCards :summary="summary" />

    <!-- Manual Evidence Import -->
    <QimEvidenceImport @imported="loadData()" />

    <!-- Comparisons Table (C2) -->
    <QimComparisonsTable
      :comparisons="comparisons"
      :loading="loading"
      @filter="loadData"
    />

    <!-- Incidents Table (§8) -->
    <QimIncidentsTable
      :incidents="incidents"
      :loading="loading"
      @select="selectedIncident = $event"
    />

    <!-- In-App Alerts Panel (§7) -->
    <QimAlertsPanel :alerts="alerts" />

    <!-- Incident Detail Modal -->
    <QimIncidentDetail
      v-if="selectedIncident"
      :incident="selectedIncident"
      @close="selectedIncident = null"
      @transition="handleTransition"
    />
  </q-page>
</template>

<style scoped>
.qim-page {
  max-width: 1400px;
  margin: 0 auto;
}
</style>
