<script setup lang="ts">
import { ref } from 'vue';
import type { QimIncident } from '../../services/quoteIntegrityApi';

const props = defineProps<{
  incident: QimIncident;
}>();

const emit = defineEmits<{
  (e: 'close'): void;
  (e: 'transition', data: { status: string; explanation: string; token: string }): void;
}>();

const nextStatus = ref('INVESTIGATING');
const explanation = ref('');
const token = ref('');

const allowedTransitionsMap: Record<string, string[]> = {
  NEW: ['INVESTIGATING', 'CLOSED'],
  INVESTIGATING: ['EXPLAINED', 'UNRESOLVED', 'CLOSED'],
  EXPLAINED: ['INVESTIGATING', 'CLOSED'],
  UNRESOLVED: ['INVESTIGATING', 'CLOSED'],
  CLOSED: ['INVESTIGATING'],
};

function submitTransition() {
  if (!explanation.value || !token.value) {
    return;
  }
  emit('transition', {
    status: nextStatus.value,
    explanation: explanation.value,
    token: token.value,
  });
}
</script>

<template>
  <q-dialog :model-value="true" persistent>
    <q-card style="min-width: 650px" class="bg-grey-10 text-white">
      <q-card-section class="row items-center justify-between">
        <div class="text-h6">
          Incident: {{ incident.title }}
        </div>
        <q-btn flat round dense icon="close" @click="emit('close')" />
      </q-card-section>

      <q-separator dark />

      <q-card-section class="q-gutter-y-sm">
        <div class="row q-col-gutter-sm">
          <div class="col-6">
            <div class="text-caption text-grey-5">Key</div>
            <div class="font-mono">{{ incident.incident_key }}</div>
          </div>
          <div class="col-3">
            <div class="text-caption text-grey-5">Status</div>
            <div class="text-weight-bold">{{ incident.status }}</div>
          </div>
          <div class="col-3">
            <div class="text-caption text-grey-5">Occurrences</div>
            <div>{{ incident.occurrence_count }}</div>
          </div>
        </div>

        <div v-if="incident.description" class="q-mt-sm">
          <div class="text-caption text-grey-5">Description</div>
          <div>{{ incident.description }}</div>
        </div>

        <!-- Audit Event Trail -->
        <div class="text-subtitle2 text-weight-bold q-mt-md">Append-Only Event Trail (§8):</div>
        <q-timeline color="primary" dense dark>
          <q-timeline-entry
            v-for="ev in incident.events"
            :key="ev.id"
            :title="ev.previous_status ? `${ev.previous_status} → ${ev.new_status}` : `Created as ${ev.new_status}`"
            :subtitle="new Date(ev.created_at).toLocaleString()"
          >
            <div class="text-caption">{{ ev.explanation }}</div>
            <div class="text-caption text-grey-6 font-mono">Actor: {{ ev.actor }} | Rule: v{{ ev.rule_version }}</div>
          </q-timeline-entry>
        </q-timeline>

        <!-- Status Transition Form -->
        <q-separator dark class="q-my-md" />
        <div class="text-subtitle2 text-weight-bold">Authorized Status Transition</div>

        <div class="row q-col-gutter-sm q-mt-xs">
          <div class="col-4">
            <q-select
              v-model="nextStatus"
              :options="allowedTransitionsMap[incident.status] ?? []"
              label="New Status"
              dense
              outlined
              dark
            />
          </div>
          <div class="col-8">
            <q-input
              v-model="token"
              label="Research API Token"
              type="password"
              dense
              outlined
              dark
              hint="Required for write API authorization"
            />
          </div>
          <div class="col-12 q-mt-sm">
            <q-input
              v-model="explanation"
              label="Reasoned Explanation (Mandatory)"
              type="textarea"
              rows="2"
              dense
              outlined
              dark
            />
          </div>
        </div>
      </q-card-section>

      <q-card-actions align="right" class="q-pa-md">
        <q-btn flat label="Cancel" color="grey-5" @click="emit('close')" />
        <q-btn
          color="primary"
          label="Apply Transition"
          :disable="!explanation || !token"
          @click="submitTransition"
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<style scoped>
.font-mono {
  font-family: monospace;
}
</style>
