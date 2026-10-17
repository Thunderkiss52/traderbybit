<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '../services/api';

const route = useRoute();
const evaluationId = ref((route.query.id as string) || '');
const mode = ref<'AS_OF_DECISION' | 'OUTCOME_REVIEW'>('AS_OF_DECISION');
const loading = ref(false);
const error = ref('');
const replayResult = ref<any>(null);

async function runReplay() {
  if (!evaluationId.value) {
    error.value = 'Please provide an Observation / Evaluation ID';
    return;
  }
  loading.value = true;
  error.value = '';
  replayResult.value = null;
  try {
    const res = await api.get(`/research/replay/${evaluationId.value}`, {
      params: { mode: mode.value },
    });
    replayResult.value = res.data.data;
  } catch (err: any) {
    error.value = err.message || 'Failed to replay decision';
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  if (evaluationId.value) {
    runReplay();
  }
});
</script>

<template>
  <q-page padding>
    <div class="page-title row items-center justify-between q-mb-md">
      <div>
        <span class="eyebrow">STAGE II · DETERMINISM & AUDITABILITY</span>
        <h1>Decision Replay & Inspector</h1>
      </div>
    </div>

    <!-- Controls -->
    <q-card flat bordered class="q-mb-md">
      <q-card-section>
        <div class="row q-col-gutter-md items-center">
          <div class="col-12 col-md-6">
            <q-input
              v-model="evaluationId"
              label="Observation / Signal / Evaluation UUID"
              outlined
              dense
              clearable
              placeholder="e.g. 3fa85f64-5717-4562-b3fc-2c963f66afa6"
            />
          </div>
          <div class="col-12 col-md-4">
            <q-btn-toggle
              v-model="mode"
              no-caps
              unelevated
              toggle-color="primary"
              :options="[
                { label: 'As-Of Decision (Strict)', value: 'AS_OF_DECISION' },
                { label: 'Outcome Review (Post-Facto)', value: 'OUTCOME_REVIEW' },
              ]"
            />
          </div>
          <div class="col-12 col-md-2">
            <q-btn
              color="primary"
              icon="play_arrow"
              label="Replay"
              class="full-width"
              :loading="loading"
              @click="runReplay"
            />
          </div>
        </div>
      </q-card-section>
    </q-card>

    <q-banner v-if="error" class="bg-negative text-white q-mb-md" rounded dense>
      {{ error }}
    </q-banner>

    <!-- Replay Results -->
    <div v-if="replayResult">
      <!-- Status Banner -->
      <div class="row q-col-gutter-md q-mb-md">
        <div class="col-12 col-md-4">
          <q-card flat bordered>
            <q-card-section>
              <div class="text-caption text-grey">Reproducibility</div>
              <div class="row items-center q-gutter-x-sm">
                <q-icon
                  :name="replayResult.is_reproducible ? 'check_circle' : 'warning'"
                  :color="replayResult.is_reproducible ? 'positive' : 'negative'"
                  size="24px"
                />
                <span class="text-h6 text-bold" :class="replayResult.is_reproducible ? 'text-positive' : 'text-negative'">
                  {{ replayResult.is_reproducible ? 'IDENTICAL REPLAY' : 'DRIFT DETECTED' }}
                </span>
              </div>
              <div class="text-caption text-grey-6">Deterministic recomputation status</div>
            </q-card-section>
          </q-card>
        </div>

        <div class="col-12 col-md-4">
          <q-card flat bordered>
            <q-card-section>
              <div class="text-caption text-grey">As-Of Timestamp</div>
              <div class="text-h6 text-bold">{{ replayResult.as_of_time }}</div>
              <div class="text-caption text-grey-6">Decision boundary (Zero look-ahead)</div>
            </q-card-section>
          </q-card>
        </div>

        <div class="col-12 col-md-4">
          <q-card flat bordered>
            <q-card-section>
              <div class="text-caption text-grey">Closed Candles Loaded</div>
              <div class="text-h6 text-bold">{{ replayResult.as_of_candles?.length || 0 }}</div>
              <div class="text-caption text-grey-6">Historical window strictly <= T</div>
            </q-card-section>
          </q-card>
        </div>
      </div>

      <!-- Diff Alert if any -->
      <q-banner v-if="replayResult.diff && Object.keys(replayResult.diff).length > 0" class="bg-warning text-dark q-mb-md" rounded dense>
        <strong>Differences detected between stored decision and recomputed decision:</strong>
        <pre class="q-ma-none text-caption">{{ JSON.stringify(replayResult.diff, null, 2) }}</pre>
      </q-banner>

      <!-- Side by Side Decision Comparison -->
      <div class="row q-col-gutter-md q-mb-md">
        <div class="col-12 col-md-6">
          <q-card flat bordered>
            <q-card-section class="bg-grey-9 text-white">
              <div class="text-subtitle2 text-bold">Stored Original Decision</div>
            </q-card-section>
            <q-separator />
            <q-card-section>
              <pre class="code-box">{{ JSON.stringify(replayResult.stored_decision, null, 2) }}</pre>
            </q-card-section>
          </q-card>
        </div>

        <div class="col-12 col-md-6">
          <q-card flat bordered>
            <q-card-section class="bg-grey-9 text-white">
              <div class="text-subtitle2 text-bold">Recomputed As-Of Decision</div>
            </q-card-section>
            <q-separator />
            <q-card-section>
              <pre class="code-box">{{ JSON.stringify(replayResult.recomputed_decision, null, 2) }}</pre>
            </q-card-section>
          </q-card>
        </div>
      </div>

      <!-- As-of Candles Table -->
      <q-card flat bordered v-if="replayResult.as_of_candles && replayResult.as_of_candles.length > 0">
        <q-card-section>
          <div class="text-subtitle1 text-bold">As-Of Market Context (Recent Candles at T)</div>
        </q-card-section>
        <q-separator />
        <q-table
          flat
          :rows="replayResult.as_of_candles"
          :columns="[
            { name: 'open_time', label: 'Open Time (UTC)', field: 'open_time', align: 'left' },
            { name: 'open', label: 'Open', field: 'open', align: 'right' },
            { name: 'high', label: 'High', field: 'high', align: 'right' },
            { name: 'low', label: 'Low', field: 'low', align: 'right' },
            { name: 'close', label: 'Close', field: 'close', align: 'right' },
            { name: 'volume', label: 'Volume', field: 'volume', align: 'right' },
          ]"
          row-key="open_time"
          :pagination="{ rowsPerPage: 10 }"
        />
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
.code-box {
  background: #111;
  color: #00e676;
  padding: 12px;
  border-radius: 6px;
  max-height: 380px;
  overflow: auto;
  font-size: 0.8rem;
  line-height: 1.4;
}
</style>
