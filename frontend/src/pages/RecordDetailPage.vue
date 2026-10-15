<script setup lang="ts">
import { ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { get } from '../services/api';

const route = useRoute();
const record = ref<unknown>(null);
const error = ref('');

watch(() => route.fullPath, async () => {
  try {
    record.value = await get(`${String(route.meta.endpoint)}/${String(route.params.id)}`);
    error.value = '';
  } catch (exception: any) {
    error.value = exception.message;
  }
}, { immediate: true });
</script>

<template>
  <q-page padding>
    <div class="page-title">
      <div><span class="eyebrow">AUDIT DETAIL</span><h1>{{ route.meta.title }}</h1></div>
      <q-btn flat icon="arrow_back" label="Back" @click="$router.back()" />
    </div>
    <q-banner v-if="error" class="bg-negative text-white">{{ error }}</q-banner>
    <pre v-else class="report panel">{{ JSON.stringify(record, null, 2) }}</pre>
  </q-page>
</template>
