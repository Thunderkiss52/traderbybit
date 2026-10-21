<script setup lang="ts">
import { ref } from 'vue';
import type { QimComparison } from '../../services/quoteIntegrityApi';

defineProps<{
  comparisons: QimComparison[];
  loading: boolean;
}>();

const emit = defineEmits<{
  (e: 'filter', filters: { symbol?: string; classification?: string }): void;
}>();

const symbolFilter = ref('');
const classificationFilter = ref('');

const classificationOptions = [
  'ALL',
  'MATCHED',
  'CHANNEL_INCONSISTENCY',
  'STALE_DATA',
  'CLOCK_SYNC_UNKNOWN',
  'TIMESTAMP_UNRELIABLE',
  'INSUFFICIENT_EVIDENCE',
];

function applyFilters() {
  emit('filter', {
    symbol: symbolFilter.value || undefined,
    classification: classificationFilter.value === 'ALL' ? undefined : (classificationFilter.value || undefined),
  });
}

function getChipColor(classification: string): string {
  switch (classification) {
    case 'MATCHED':
      return 'positive';
    case 'CHANNEL_INCONSISTENCY':
      return 'negative';
    case 'STALE_DATA':
      return 'orange-9';
    case 'CLOCK_SYNC_UNKNOWN':
      return 'amber-9';
    case 'TIMESTAMP_UNRELIABLE':
      return 'deep-orange';
    default:
      return 'blue-grey-6';
  }
}
</script>

<template>
  <q-card flat bordered class="bg-dark text-white q-mb-md">
    <q-card-section class="row items-center justify-between q-pb-none">
      <div class="text-subtitle1 text-weight-bold">
        <q-icon name="compare_arrows" size="sm" class="q-mr-xs" />
        Independent REST ↔ WS Candle Comparisons (C2)
      </div>
      <div class="row q-gutter-sm items-center">
        <q-input
          v-model="symbolFilter"
          dense
          outlined
          dark
          placeholder="Filter Symbol"
          style="width: 140px"
          @update:model-value="applyFilters"
        />
        <q-select
          v-model="classificationFilter"
          :options="classificationOptions"
          dense
          outlined
          dark
          placeholder="Classification"
          style="width: 190px"
          @update:model-value="applyFilters"
        />
      </div>
    </q-card-section>

    <q-card-section>
      <q-markup-table flat dense dark class="bg-transparent text-left">
        <thead>
          <tr class="text-grey-5">
            <th>Candle Time (UTC)</th>
            <th>Symbol / Int</th>
            <th>REST Price (Close)</th>
            <th>WS Price (Close)</th>
            <th>Price Diff (Abs / Rel)</th>
            <th>Skew</th>
            <th>Classification</th>
            <th>Details</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="comparisons.length === 0">
            <td colspan="8" class="text-center text-grey-6 q-pa-md">
              {{ loading ? 'Loading comparisons...' : 'No comparison records found for criteria.' }}
            </td>
          </tr>
          <tr v-for="c in comparisons" :key="c.id">
            <td class="text-caption font-mono">{{ new Date(c.candle_open_time).toISOString().replace('.000Z', 'Z') }}</td>
            <td><b>{{ c.symbol }}</b> ({{ c.interval }})</td>
            <td class="font-mono">{{ c.rest_observation?.close ?? 'N/A' }}</td>
            <td class="font-mono">{{ c.ws_observation?.close ?? 'N/A' }}</td>
            <td class="font-mono">
              <span v-if="c.price_diff_abs">
                {{ c.price_diff_abs }} ({{ (Number(c.price_diff_rel) * 100).toFixed(4) }}%)
              </span>
              <span v-else class="text-grey-6">—</span>
            </td>
            <td class="font-mono">{{ c.timestamp_skew_ms !== null ? c.timestamp_skew_ms + 'ms' : '—' }}</td>
            <td>
              <q-chip dense :color="getChipColor(c.classification)" text-color="white" class="text-weight-bold">
                {{ c.classification }}
              </q-chip>
            </td>
            <td class="text-caption text-grey-5">{{ c.explanation ?? '—' }}</td>
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
