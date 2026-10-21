<script setup lang="ts">
import type { QimIncident } from '../../services/quoteIntegrityApi';

defineProps<{
  incidents: QimIncident[];
  loading: boolean;
}>();

const emit = defineEmits<{
  (e: 'select', incident: QimIncident): void;
}>();

function getStatusColor(status: string): string {
  switch (status) {
    case 'NEW':
      return 'negative';
    case 'INVESTIGATING':
      return 'warning';
    case 'EXPLAINED':
      return 'info';
    case 'CLOSED':
      return 'positive';
    default:
      return 'blue-grey';
  }
}
</script>

<template>
  <q-card flat bordered class="bg-dark text-white q-mb-md">
    <q-card-section class="q-pb-none">
      <div class="text-subtitle1 text-weight-bold">
        <q-icon name="warning" size="sm" class="q-mr-xs text-amber-5" />
        Incidents & Investigation Lifecycle (§8)
      </div>
    </q-card-section>

    <q-card-section>
      <q-markup-table flat dense dark class="bg-transparent text-left">
        <thead>
          <tr class="text-grey-5">
            <th>Status</th>
            <th>Key / Title</th>
            <th>Severity</th>
            <th>Symbol</th>
            <th>Count</th>
            <th>First Detected</th>
            <th>Last Detected</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="incidents.length === 0">
            <td colspan="8" class="text-center text-grey-6 q-pa-md">
              {{ loading ? 'Loading incidents...' : 'No incidents recorded.' }}
            </td>
          </tr>
          <tr v-for="inc in incidents" :key="inc.id">
            <td>
              <q-chip dense :color="getStatusColor(inc.status)" text-color="white" class="text-weight-bold">
                {{ inc.status }}
              </q-chip>
            </td>
            <td>
              <div class="text-weight-bold">{{ inc.title }}</div>
              <div class="text-caption text-grey-5 font-mono">{{ inc.incident_key }}</div>
            </td>
            <td>
              <q-badge :color="inc.severity === 'HIGH' || inc.severity === 'CRITICAL' ? 'red' : 'amber-9'">
                {{ inc.severity }} ({{ inc.priority }})
              </q-badge>
            </td>
            <td>{{ inc.symbol ?? 'All' }}</td>
            <td>{{ inc.occurrence_count }}</td>
            <td class="text-caption font-mono">{{ new Date(inc.first_detected_at).toLocaleString() }}</td>
            <td class="text-caption font-mono">{{ new Date(inc.last_detected_at).toLocaleString() }}</td>
            <td>
              <q-btn size="sm" flat dense color="primary" label="Inspect" @click="emit('select', inc)" />
            </td>
          </tr>
        </tbody>
      </q-markup-table>
    </q-card-section>
  </q-card>
</template>

<style scoped>
.font-mono {
  font-family: monospace;
}
</style>
