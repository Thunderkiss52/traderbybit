<script setup lang="ts">
import { ref } from 'vue';
import { uploadQimEvidence, type QimEvidenceFile } from '../../services/quoteIntegrityApi';

const emit = defineEmits<{
  (e: 'imported', record: QimEvidenceFile): void;
}>();

const file = ref<File | null>(null);
const provenanceClass = ref('ORIGINAL_EXPORT');
const declaredSource = ref('bybit_rest');
const declaredTimezone = ref('UTC');
const token = ref('');
const uploading = ref(false);
const errorMsg = ref('');
const successMsg = ref('');

const provenanceOptions = [
  'ORIGINAL_EXPORT',
  'SCREENSHOT',
  'OPERATOR_TRANSCRIBED',
  'SYNTHETIC_TEST_FIXTURE',
];

async function handleUpload() {
  if (!file.value || !token.value) {
    errorMsg.value = 'Please select a file and provide the Research API Token.';
    return;
  }

  uploading.value = true;
  errorMsg.value = '';
  successMsg.value = '';

  const formData = new FormData();
  formData.append('file', file.value);
  formData.append('provenance_class', provenanceClass.value);
  formData.append('declared_source', declaredSource.value);
  formData.append('declared_timezone', declaredTimezone.value);

  try {
    const record = await uploadQimEvidence(formData, token.value);
    successMsg.value = `File uploaded successfully. SHA-256: ${record.sha256_hash}`;
    file.value = null;
    emit('imported', record);
  } catch (err: any) {
    errorMsg.value = err.message || 'Evidence import failed.';
  } finally {
    uploading.value = false;
  }
}
</script>

<template>
  <q-card flat bordered class="bg-dark text-white q-mb-md">
    <q-card-section class="q-pb-none">
      <div class="text-subtitle1 text-weight-bold">
        <q-icon name="cloud_upload" size="sm" class="q-mr-xs text-primary" />
        Manual Evidence Import (§5)
      </div>
      <div class="text-caption text-grey-5">
        Preserves operator-supplied materials (e.g. IQ Option vs TradingView external observations) without waiting for live secondary source connections. Max 20 MB; formats: png, jpg, jpeg, pdf, csv, json, txt.
      </div>
    </q-card-section>

    <!-- Mandatory Disclaimers -->
    <q-card-section class="q-py-xs">
      <q-banner dense class="bg-blue-grey-10 text-white rounded-borders q-my-xs">
        <div class="text-caption">
          <b>Evidence Disclaimer:</b> SHA-256 proves bytes unchanged after capture. Not a verification of quote authenticity. A screenshot shows what was displayed in an interface, not official market data. Case status starts at <code>INSUFFICIENT_EVIDENCE</code> and is never promoted automatically.
        </div>
      </q-banner>
    </q-card-section>

    <q-card-section>
      <div class="row q-col-gutter-sm">
        <div class="col-12 col-md-4">
          <q-file
            v-model="file"
            label="Select Evidence File"
            outlined
            dense
            dark
            accept=".png,.jpg,.jpeg,.pdf,.csv,.json,.txt"
          >
            <template #prepend>
              <q-icon name="attach_file" />
            </template>
          </q-file>
        </div>

        <div class="col-12 col-md-3">
          <q-select
            v-model="provenanceClass"
            :options="provenanceOptions"
            label="Provenance Class"
            outlined
            dense
            dark
          />
        </div>

        <div class="col-12 col-md-3">
          <q-input
            v-model="declaredSource"
            label="Declared Source"
            outlined
            dense
            dark
            placeholder="e.g. tradingview, iq_option"
          />
        </div>

        <div class="col-12 col-md-2">
          <q-input
            v-model="declaredTimezone"
            label="Declared Timezone"
            outlined
            dense
            dark
          />
        </div>

        <div class="col-12 col-md-8">
          <q-input
            v-model="token"
            label="Research API Token"
            type="password"
            outlined
            dense
            dark
            hint="X-Research-Token required for upload authorization"
          />
        </div>

        <div class="col-12 col-md-4 flex items-center">
          <q-btn
            color="primary"
            label="Upload Evidence"
            icon="upload"
            :loading="uploading"
            :disable="!file || !token"
            class="full-width"
            @click="handleUpload"
          />
        </div>
      </div>

      <div v-if="successMsg" class="text-positive text-caption q-mt-sm font-mono">
        {{ successMsg }}
      </div>
      <div v-if="errorMsg" class="text-negative text-caption q-mt-sm">
        {{ errorMsg }}
      </div>
    </q-card-section>
  </q-card>
</template>

<style scoped>
.font-mono {
  font-family: monospace;
}
code {
  font-family: monospace;
  background: rgba(255, 255, 255, 0.1);
  padding: 1px 4px;
}
</style>
