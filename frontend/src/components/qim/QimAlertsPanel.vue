<script setup lang="ts">
import type { QimAlert } from '../../services/quoteIntegrityApi';

defineProps<{
  alerts: QimAlert[];
}>();
</script>

<template>
  <q-card flat bordered class="bg-dark text-white q-mb-md">
    <q-card-section class="q-pb-none">
      <div class="text-subtitle1 text-weight-bold">
        <q-icon name="notifications_active" size="sm" class="q-mr-xs text-red-4" />
        In-App Deduplicated Alerts (§7)
      </div>
      <div class="text-caption text-grey-5">
        External delivery is unavailable; alerts appear in-app only and are deduplicated by (rule_version, rule_key, incident_group, window).
      </div>
    </q-card-section>

    <q-card-section>
      <div v-if="alerts.length === 0" class="text-grey-6 text-caption q-pa-sm">
        No active in-app alerts recorded in observation window.
      </div>

      <q-list dark separator>
        <q-item v-for="a in alerts" :key="a.id">
          <q-item-section avatar>
            <q-icon name="report" :color="a.severity === 'HIGH' ? 'negative' : 'warning'" />
          </q-item-section>
          <q-item-section>
            <q-item-label class="text-weight-bold">{{ a.title }}</q-item-label>
            <q-item-label caption class="text-grey-4">
              Rule: <code>{{ a.rule_key }}</code> | Window: {{ new Date(a.window_start).toLocaleTimeString() }} - {{ new Date(a.window_end).toLocaleTimeString() }}
            </q-item-label>
          </q-item-section>
          <q-item-section side>
            <q-badge color="grey-8">{{ a.symbol ?? 'Global' }}</q-badge>
          </q-item-section>
        </q-item>
      </q-list>
    </q-card-section>
  </q-card>
</template>

<style scoped>
code {
  font-family: monospace;
}
</style>
