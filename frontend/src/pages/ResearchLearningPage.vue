<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { api } from '../services/api';

const activeTab = ref('overview');
const loading = ref(false);
const error = ref('');

const overview = ref<any>(null);
const candidates = ref<any[]>([]);
const candidatesPage = ref(1);
const candidatesPages = ref(1);
const candidatesTotal = ref(0);
const models = ref<any[]>([]);
const settings = ref<any>(null);
const auditEvents = ref<any[]>([]);
const auditPage = ref(1);
const auditPages = ref(1);
const auditTotal = ref(0);

const selectedStrategy = ref('trend_pullback');
const generating = ref(false);

async function loadOverview() {
  loading.value = true;
  error.value = '';
  try {
    const res = await api.get('/research/learning/overview');
    overview.value = res.data.data;
    settings.value = res.data.data.settings;
  } catch (err: any) {
    error.value = err.message || 'Failed to load learning overview';
  } finally {
    loading.value = false;
  }
}

async function loadCandidates() {
  loading.value = true;
  try {
    const res = await api.get('/research/learning/candidates', {
      params: { page: candidatesPage.value, per_page: 10 },
    });
    candidates.value = res.data.data.data || [];
    candidatesPages.value = res.data.data.last_page || 1;
    candidatesTotal.value = res.data.data.total || 0;
  } catch (err: any) {
    error.value = err.message || 'Failed to load candidates';
  } finally {
    loading.value = false;
  }
}

async function loadModels() {
  loading.value = true;
  try {
    const res = await api.get('/research/learning/models');
    models.value = res.data.data || [];
  } catch (err: any) {
    error.value = err.message || 'Failed to load models';
  } finally {
    loading.value = false;
  }
}

async function loadAuditTrail() {
  loading.value = true;
  try {
    const res = await api.get('/research/learning/audit-trail', {
      params: { page: auditPage.value, per_page: 15 },
    });
    auditEvents.value = res.data.data.data || [];
    auditPages.value = res.data.data.last_page || 1;
    auditTotal.value = res.data.data.total || 0;
  } catch (err: any) {
    error.value = err.message || 'Failed to load audit trail';
  } finally {
    loading.value = false;
  }
}

async function triggerGenerateCandidate() {
  generating.value = true;
  try {
    await api.post('/research/learning/candidates/generate', {
      parent_strategy_id: selectedStrategy.value,
    });
    await loadCandidates();
  } catch (err: any) {
    error.value = err.message || 'Candidate generation failed';
  } finally {
    generating.value = false;
  }
}

async function evaluateCandidate(id: string) {
  try {
    await api.post(`/research/learning/candidates/${id}/evaluate`);
    await loadCandidates();
  } catch (err: any) {
    error.value = err.message || 'Evaluation failed';
  }
}

async function promoteCandidate(id: string) {
  try {
    await api.post(`/research/learning/candidates/${id}/promote`, { actor: 'USER_OPERATOR' });
    await loadCandidates();
    await loadOverview();
  } catch (err: any) {
    error.value = err.message || 'Promotion failed';
  }
}

async function rollbackCandidate(id: string) {
  try {
    await api.post(`/research/learning/candidates/${id}/rollback`, {
      reason: 'Manual rollback initiated from Candidate Lab',
    });
    await loadCandidates();
    await loadOverview();
  } catch (err: any) {
    error.value = err.message || 'Rollback failed';
  }
}

async function toggleKillSwitch() {
  if (!settings.value) return;
  const target = !settings.value.kill_switch_active;
  try {
    const res = await api.post('/research/learning/kill-switch', {
      active: target,
      reason: target ? 'Emergency operator kill-switch' : 'Operator resumed autonomy',
    });
    settings.value = res.data.data;
    await loadOverview();
  } catch (err: any) {
    error.value = err.message || 'Failed to toggle kill switch';
  }
}

function handleTab(tab: string) {
  activeTab.value = tab;
  if (tab === 'overview') loadOverview();
  else if (tab === 'candidates') loadCandidates();
  else if (tab === 'models') loadModels();
  else if (tab === 'audit') loadAuditTrail();
  else if (tab === 'settings') loadOverview();
}

onMounted(() => {
  loadOverview();
});
</script>

<template>
  <q-page padding>
    <div class="page-title row items-center justify-between q-mb-md">
      <div>
        <span class="eyebrow">CONTINUOUS EVOLUTION & AUTONOMY</span>
        <h1>Autonomous Self-Learning Lab</h1>
      </div>
      <div class="row q-gutter-sm items-center">
        <q-btn
          v-if="settings"
          :color="settings.kill_switch_active ? 'negative' : 'grey-8'"
          :icon="settings.kill_switch_active ? 'warning' : 'power_settings_new'"
          :label="settings.kill_switch_active ? 'KILL SWITCH ACTIVE (HALTED)' : 'Emergency Kill-Switch'"
          unelevated
          no-caps
          @click="toggleKillSwitch"
        />
        <q-btn flat icon="refresh" :loading="loading" label="Refresh" @click="handleTab(activeTab)" />
      </div>
    </div>

    <!-- Kill Switch Active Alert -->
    <q-banner v-if="settings && settings.kill_switch_active" class="bg-negative text-white q-mb-md" rounded dense>
      <div class="row items-center justify-between">
        <div>
          <strong>EMERGENCY KILL SWITCH ACTIVE:</strong> All autonomous parameter tuning, shadow experiments, and model training are suspended.
        </div>
        <q-btn flat dense color="white" label="Deactivate Kill-Switch" @click="toggleKillSwitch" />
      </div>
    </q-banner>

    <q-banner v-if="error" class="bg-negative text-white q-mb-md" rounded dense>
      {{ error }}
    </q-banner>

    <q-tabs
      v-model="activeTab"
      dense
      class="text-grey"
      active-color="primary"
      indicator-color="primary"
      align="left"
      narrow-indicator
      @update:model-value="handleTab"
    >
      <q-tab name="overview" icon="auto_mode" label="Learning Overview" />
      <q-tab name="candidates" icon="science" label="Candidate Lab" />
      <q-tab name="models" icon="psychology" label="Model Artifacts" />
      <q-tab name="settings" icon="shield" label="Autonomy & Governance" />
      <q-tab name="audit" icon="history" label="Approval Audit Trail" />
    </q-tabs>

    <q-separator class="q-mb-md" />

    <!-- TAB 1: OVERVIEW -->
    <div v-if="activeTab === 'overview' && overview">
      <div class="row q-col-gutter-md q-mb-md">
        <div class="col-12 col-md-3">
          <q-card flat bordered class="kpi-card">
            <q-card-section>
              <div class="text-caption text-grey">Feature Snapshots</div>
              <div class="text-h5 text-bold">{{ overview.feature_store.total_snapshots }}</div>
              <div class="text-caption text-grey-6">{{ overview.feature_store.matured_snapshots }} matured labels</div>
            </q-card-section>
          </q-card>
        </div>

        <div class="col-12 col-md-3">
          <q-card flat bordered class="kpi-card">
            <q-card-section>
              <div class="text-caption text-grey">Active Predictors</div>
              <div class="text-h5 text-bold text-primary">{{ overview.models.active_models_count }}</div>
              <div class="text-caption text-grey-6">Calibrated probabilistic models</div>
            </q-card-section>
          </q-card>
        </div>

        <div class="col-12 col-md-3">
          <q-card flat bordered class="kpi-card">
            <q-card-section>
              <div class="text-caption text-grey">Evolved Candidates</div>
              <div class="text-h5 text-bold">{{ overview.candidates.total }}</div>
              <div class="text-caption text-grey-6">Generated in bounded search</div>
            </q-card-section>
          </q-card>
        </div>

        <div class="col-12 col-md-3">
          <q-card flat bordered class="kpi-card">
            <q-card-section>
              <div class="text-caption text-grey">Promoted to Forward Paper</div>
              <div class="text-h5 text-bold text-positive">{{ overview.candidates.promoted_to_paper }}</div>
              <div class="text-caption text-grey-6">Isolated versioned accounts</div>
            </q-card-section>
          </q-card>
        </div>
      </div>

      <!-- Autonomous Pipelines status -->
      <q-card flat bordered class="q-mb-md">
        <q-card-section>
          <div class="text-subtitle1 text-bold">Continuous Learning State Machine</div>
          <div class="text-caption text-grey">Closed Market Data -> Feature Snapshots -> Model Retraining -> Parameter Search -> Purged Walk-Forward -> Paper Promotion -> Rollback.</div>
        </q-card-section>
        <q-separator />
        <q-card-section>
          <div class="row q-col-gutter-md">
            <div class="col-12 col-md-4">
              <q-item>
                <q-item-section avatar>
                  <q-icon name="check_circle" color="positive" size="32px" />
                </q-item-section>
                <q-item-section>
                  <q-item-label class="text-bold">Feature Store & Labels</q-item-label>
                  <q-item-label caption>Strict point-in-time extraction. Closed candles only.</q-item-label>
                </q-item-section>
              </q-item>
            </div>

            <div class="col-12 col-md-4">
              <q-item>
                <q-item-section avatar>
                  <q-icon name="model_training" color="primary" size="32px" />
                </q-item-section>
                <q-item-section>
                  <q-item-label class="text-bold">Offline Predictor Calibration</q-item-label>
                  <q-item-label caption>Brier & ECE calibration without future data leakage.</q-item-label>
                </q-item-section>
              </q-item>
            </div>

            <div class="col-12 col-md-4">
              <q-item>
                <q-item-section avatar>
                  <q-icon name="gavel" color="warning" size="32px" />
                </q-item-section>
                <q-item-section>
                  <q-item-label class="text-bold">Mandatory Validation Gates</q-item-label>
                  <q-item-label caption>OOS positive expectancy, drawdown <= 12%, sample >= 30.</q-item-label>
                </q-item-section>
              </q-item>
            </div>
          </div>
        </q-card-section>
      </q-card>
    </div>

    <!-- TAB 2: CANDIDATE LAB -->
    <div v-if="activeTab === 'candidates'">
      <!-- Controls -->
      <q-card flat bordered class="q-mb-md">
        <q-card-section class="row items-center justify-between">
          <div class="row q-gutter-md items-center">
            <q-select
              v-model="selectedStrategy"
              label="Parent Strategy to Evolve"
              outlined
              dense
              :options="['trend_pullback', 'bollinger_mean_reversion', 'donchian_atr_breakout']"
              style="width: 280px"
            />
            <q-btn
              color="primary"
              icon="tune"
              label="Run Bounded Parameter Search"
              no-caps
              unelevated
              :loading="generating"
              @click="triggerGenerateCandidate"
            />
          </div>
          <div class="text-caption text-grey">All parameter searches operate within strict risk-preserving safety bounds.</div>
        </q-card-section>
      </q-card>

      <!-- Candidates Table -->
      <q-card flat bordered>
        <q-table
          flat
          :rows="candidates"
          :columns="[
            { name: 'name', label: 'Candidate Version', field: 'name', align: 'left' },
            { name: 'parent', label: 'Parent Strategy', field: 'parent_strategy_id', align: 'left' },
            { name: 'status', label: 'Promotion Status', field: 'status', align: 'center' },
            { name: 'gates', label: 'Validation Gates', field: (r: any) => r.validation_gates?.all_gates_passed, align: 'center' },
            { name: 'diff', label: 'Parameter Diff', field: 'parameter_diff', align: 'left' },
            { name: 'actions', label: 'Actions', field: 'id', align: 'right' },
          ]"
          row-key="id"
          :pagination="{ rowsPerPage: 0 }"
          hide-pagination
        >
          <template #body-cell-status="props">
            <q-td :props="props">
              <q-badge
                :color="
                  props.value === 'PAPER_PROMOTED'
                    ? 'positive'
                    : props.value === 'PAPER_ELIGIBLE'
                    ? 'primary'
                    : props.value === 'PAPER_PROMOTION_PENDING'
                    ? 'warning'
                    : props.value === 'ROLLED_BACK'
                    ? 'negative'
                    : 'grey-8'
                "
                :label="props.value"
              />
            </q-td>
          </template>

          <template #body-cell-gates="props">
            <q-td :props="props">
              <q-icon
                v-if="props.value === true"
                name="check_circle"
                color="positive"
                size="20px"
              />
              <q-icon
                v-else-if="props.value === false"
                name="cancel"
                color="negative"
                size="20px"
              />
              <span v-else class="text-grey">—</span>
            </q-td>
          </template>

          <template #body-cell-diff="props">
            <q-td :props="props">
              <span v-for="(v, k) in props.value" :key="k" class="q-mr-xs">
                <q-chip dense size="xs" color="grey-9" text-color="white">
                  {{ k }}: {{ v.from }} &rarr; {{ v.to }}
                </q-chip>
              </span>
            </q-td>
          </template>

          <template #body-cell-actions="props">
            <q-td :props="props" class="q-gutter-xs">
              <q-btn
                v-if="props.row.status === 'RESEARCH_ONLY'"
                size="sm"
                flat
                color="primary"
                label="Evaluate Gates"
                @click="evaluateCandidate(props.row.id)"
              />
              <q-btn
                v-if="props.row.status === 'PAPER_ELIGIBLE' || props.row.status === 'PAPER_PROMOTION_PENDING'"
                size="sm"
                unelevated
                color="positive"
                label="Promote to Paper"
                @click="promoteCandidate(props.row.id)"
              />
              <q-btn
                v-if="props.row.status === 'PAPER_PROMOTED'"
                size="sm"
                flat
                color="negative"
                label="Rollback"
                @click="rollbackCandidate(props.row.id)"
              />
            </q-td>
          </template>
        </q-table>
        <div class="row items-center justify-between q-pa-md">
          <div class="text-caption text-grey">
            Showing {{ candidates.length ? (candidatesPage - 1) * 10 + 1 : 0 }}–{{ Math.min(candidatesPage * 10, candidatesTotal || candidates.length) }}
            of {{ candidatesTotal || candidates.length }} candidates · Page {{ candidatesPage }} / {{ candidatesPages }}
          </div>
          <q-pagination v-model="candidatesPage" :max="candidatesPages" :max-pages="7" boundary-links direction-links :disable="loading" @update:model-value="loadCandidates" />
        </div>
      </q-card>
    </div>

    <!-- TAB 3: MODEL ARTIFACTS -->
    <div v-if="activeTab === 'models'">
      <q-card flat bordered>
        <q-table
          flat
          :rows="models"
          :columns="[
            { name: 'name', label: 'Model Artifact', field: 'name', align: 'left' },
            { name: 'target', label: 'Target Event', field: 'target_event', align: 'left' },
            { name: 'type', label: 'Type', field: 'model_type', align: 'left' },
            { name: 'version', label: 'Version', field: 'version', align: 'left' },
            { name: 'brier', label: 'Brier Score', field: (r: any) => r.metrics?.brier_score, align: 'right' },
            { name: 'ece', label: 'Calibration ECE', field: (r: any) => r.metrics?.ece, align: 'right' },
            { name: 'status', label: 'Status', field: 'status', align: 'center' },
          ]"
          row-key="id"
          :pagination="{ rowsPerPage: 10 }"
        >
          <template #body-cell-status="props">
            <q-td :props="props">
              <q-badge :color="props.value === 'ACTIVE' ? 'positive' : 'grey-8'" :label="props.value" />
            </q-td>
          </template>
        </q-table>
      </q-card>
    </div>

    <!-- TAB 4: AUTONOMY SETTINGS -->
    <div v-if="activeTab === 'settings' && settings">
      <q-card flat bordered class="q-mb-md">
        <q-card-section>
          <div class="text-subtitle1 text-bold">Persisted Capability Permissions & Governance</div>
          <div class="text-caption text-grey">All permissions are explicit and immutable; hard risk and exchange limits cannot be bypassed.</div>
        </q-card-section>
        <q-separator />
        <q-card-section>
          <div class="row q-col-gutter-md">
            <div class="col-12 col-md-6" v-for="(val, cap) in settings.capabilities" :key="cap">
              <q-item>
                <q-item-section>
                  <q-item-label class="text-bold">{{ cap }}</q-item-label>
                  <q-item-label caption v-if="cap === 'live_exchange_trading' || cap === 'change_hard_risk_limits'" class="text-negative">
                    Permanently DISABLED in this research release.
                  </q-item-label>
                  <q-item-label caption v-else>
                    Configured autonomous permission.
                  </q-item-label>
                </q-item-section>
                <q-item-section side>
                  <q-badge :color="val ? 'positive' : 'grey-8'" :label="val ? 'ENABLED' : 'DISABLED'" />
                </q-item-section>
              </q-item>
            </div>
          </div>
        </q-card-section>
      </q-card>
    </div>

    <!-- TAB 5: AUDIT TRAIL -->
    <div v-if="activeTab === 'audit'">
      <q-card flat bordered>
        <q-table
          flat
          :rows="auditEvents"
          :columns="[
            { name: 'time', label: 'Timestamp (UTC)', field: 'created_at', align: 'left' },
            { name: 'actor', label: 'Actor', field: 'actor', align: 'left' },
            { name: 'action', label: 'Action', field: 'action', align: 'left' },
            { name: 'target', label: 'Target', field: (r: any) => `${r.target_type} [${r.target_id || ''}]`, align: 'left' },
            { name: 'state', label: 'Transition', field: (r: any) => `${r.previous_state || 'NONE'} &rarr; ${r.new_state}`, align: 'left' },
            { name: 'reason', label: 'Reason & Rationale', field: 'reason', align: 'left' },
          ]"
          row-key="id"
          :pagination="{ rowsPerPage: 0 }"
          hide-pagination
        />
        <div class="row items-center justify-between q-pa-md">
          <div class="text-caption text-grey">
            Showing {{ auditEvents.length ? (auditPage - 1) * 15 + 1 : 0 }}–{{ Math.min(auditPage * 15, auditTotal || auditEvents.length) }}
            of {{ auditTotal || auditEvents.length }} events · Page {{ auditPage }} / {{ auditPages }}
          </div>
          <q-pagination v-model="auditPage" :max="auditPages" :max-pages="7" boundary-links direction-links :disable="loading" @update:model-value="loadAuditTrail" />
        </div>
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
