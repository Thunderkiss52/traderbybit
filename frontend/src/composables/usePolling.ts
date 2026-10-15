import { onBeforeUnmount, onMounted, ref, watch, type Ref } from 'vue';

export function usePolling(load: () => Promise<void>, milliseconds = 5000, enabled?: Ref<boolean>) {
  const connected = ref(true);
  const loading = ref(false);
  let timer: number | undefined;
  let delay = milliseconds;
  let disposed = false;
  let active: Promise<void> | undefined;
  let rerun = false;

  const run = (): Promise<void> => {
    if (active) {
      rerun = true;
      return active;
    }
    window.clearTimeout(timer);
    active = (async () => {
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
        active = undefined;
        if (!disposed && enabled?.value !== false) {
          const nextDelay = rerun ? 0 : delay;
          rerun = false;
          timer = window.setTimeout(run, nextDelay);
        }
      }
    })();
    return active;
  };

  onMounted(run);
  if (enabled) watch(enabled, value => value ? run() : window.clearTimeout(timer));
  onBeforeUnmount(() => {
    disposed = true;
    window.clearTimeout(timer);
  });
  return { connected, loading, refresh: run };
}
