<script setup lang="ts">
import type { QimStatus } from '../../services/quoteIntegrityApi';

defineProps<{
  status: QimStatus | null;
  disabled: boolean;
}>();
</script>

<template>
  <div class="qim-banners q-gutter-y-md q-mb-md">
    <!-- Disabled Banner -->
    <q-banner v-if="disabled" class="bg-grey-9 text-white rounded-borders" rounded dense>
      <template #avatar>
        <q-icon name="block" color="amber" size="md" />
      </template>
      <div class="text-subtitle1 text-bold">FEATURE_DISABLED</div>
      <div>Quote Integrity Monitor is disabled in configuration (<code>QUOTE_INTEGRITY_ENABLED=false</code>). Capture and comparison background jobs are inactive. Trading pipeline remains unaffected.</div>
    </q-banner>

    <!-- Mandatory Disclaimer 1 -->
    <q-banner class="bg-indigo-10 text-white rounded-borders" rounded dense>
      <template #avatar>
        <q-icon name="info" color="light-blue-2" size="md" />
      </template>
      <div class="text-caption text-weight-medium">
        <b>Mandatory Integrity Disclaimer:</b> Internal Bybit consistency monitoring does not establish independent market-price correctness.
      </div>
    </q-banner>

    <!-- Mandatory Panel 2: CROSS_SOURCE_UNAVAILABLE -->
    <q-banner class="bg-blue-grey-9 text-white rounded-borders" rounded dense>
      <template #avatar>
        <q-icon name="sync_problem" color="warning" size="md" />
      </template>
      <div class="text-subtitle2 text-bold text-amber-3">
        LEVEL 2 STATUS: CROSS_SOURCE_UNAVAILABLE
      </div>
      <div class="text-caption">
        Independent cross-source monitoring against secondary venues (e.g. TradingView, IQ Option) is <b>unavailable</b>. Candidate external sources remain <code>UNDETERMINED</code> under Stage Q0 gate rules. Level 1 monitors Bybit REST vs WebSocket integrity only.
      </div>
    </q-banner>

    <!-- Active Status Bar -->
    <div v-if="status && !disabled" class="row q-col-gutter-sm items-center">
      <div class="col-auto">
        <q-chip :color="status.level_1_status === 'ACTIVE' ? 'positive' : 'negative'" text-color="white" icon="verified">
          L1: {{ status.level_1_status }}
        </q-chip>
      </div>
      <div class="col-auto">
        <q-chip color="orange-8" text-color="white" icon="access_time">
          Clock: {{ status.clock_sync_state }}
        </q-chip>
      </div>
      <div class="col-auto">
        <q-chip color="blue-grey-7" text-color="white" icon="rule">
          Rules: v{{ status.rules_version }}
        </q-chip>
      </div>
      <div v-if="status.measurement_mode" class="col-auto">
        <q-chip color="teal" text-color="white" icon="speed">
          Measurement Mode: 7d Active
        </q-chip>
      </div>
    </div>
  </div>
</template>

<style scoped>
.qim-banners code {
  background: rgba(255, 255, 255, 0.15);
  padding: 2px 6px;
  border-radius: 4px;
}
</style>
