import { onBeforeUnmount, onMounted, ref, watch, type Ref } from 'vue';

export function usePolling(load: () => Promise<void>, milliseconds = 5000, enabled?: Ref<boolean>) {
  const connected = ref(true);
  const loading = ref(false);
  let timer: number | undefined;
  let delay = milliseconds;

  const run = async () => {
    window.clearTimeout(timer);
    loading.value = true;
    try {
      await load();
      connected.value = true;
      delay = milliseconds;
    } catch {
      connected.value = false;
      delay = Math.min(delay * 2, 60000);
    } finally {
      loading.value = false;
      if (enabled?.value !== false) timer = window.setTimeout(run, delay);
    }
  };

  onMounted(run);
  if (enabled) watch(enabled, value => value ? run() : window.clearTimeout(timer));
  onBeforeUnmount(() => window.clearTimeout(timer));
  return { connected, loading, refresh: run };
}
