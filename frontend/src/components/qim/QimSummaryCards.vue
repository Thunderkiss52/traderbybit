<script setup lang="ts">
import type { QimSummary } from '../../services/quoteIntegrityApi';

defineProps<{
  summary: QimSummary | null;
}>();
</script>

<template>
  <div v-if="summary" class="row q-col-gutter-sm q-mb-md">
    <div class="col-12 col-sm-6 col-md-3">
      <q-card flat bordered class="q-pa-sm bg-dark text-white">
        <div class="text-caption text-grey-5">Total Comparisons</div>
        <div class="text-h6 text-weight-bold">{{ summary.total_comparisons }}</div>
        <div class="text-caption text-positive">Matched: {{ summary.matched_comparisons }}</div>
      </q-card>
    </div>

    <div class="col-12 col-sm-6 col-md-3">
      <q-card flat bordered class="q-pa-sm bg-dark text-white">
        <div class="text-caption text-grey-5">Channel Inconsistencies</div>
        <div class="text-h6 text-weight-bold" :class="summary.channel_inconsistencies > 0 ? 'text-negative' : 'text-grey-4'">
          {{ summary.channel_inconsistencies }}
        </div>
        <div class="text-caption text-grey-5">REST vs WS disagreements</div>
      </q-card>
    </div>

    <div class="col-12 col-sm-6 col-md-3">
      <q-card flat bordered class="q-pa-sm bg-dark text-white">
        <div class="text-caption text-grey-5">Captured Duplicates</div>
        <div class="text-h6 text-weight-bold text-amber-5">
          {{ summary.duplicate_counts.exact + summary.duplicate_counts.conflicting + summary.duplicate_counts.identity_config }}
        </div>
        <div class="text-caption text-grey-5">
          Conflicting: <span class="text-negative">{{ summary.duplicate_counts.conflicting }}</span> | Exact: {{ summary.duplicate_counts.exact }}
        </div>
      </q-card>
    </div>

    <div class="col-12 col-sm-6 col-md-3">
      <q-card flat bordered class="q-pa-sm bg-dark text-white">
        <div class="text-caption text-grey-5">Gap Events / Incidents</div>
        <div class="text-h6 text-weight-bold text-cyan-4">
          Gaps: {{ summary.gap_events_count }}
        </div>
        <div class="text-caption text-grey-5">
          Active Incidents: {{ summary.incidents_count.new + summary.incidents_count.investigating }}
        </div>
      </q-card>
    </div>
  </div>
</template>
