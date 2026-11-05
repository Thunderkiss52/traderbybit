import { defineStore } from 'pinia';
import { computed, ref, watch } from 'vue';
import { api } from '../services/api';

export interface ExchangeOption {
  id: string;
  name: string;
  lifecycle_status: string;
  paper_approved?: boolean;
}

export const useSettings = defineStore('settings', () => {
  const saved = JSON.parse(localStorage.getItem('ui-settings') || '{}');
  const locale = ref(saved.locale || 'ru');
  const dark = ref(saved.dark ?? true);
  const timezone = ref(saved.timezone || 'local');
  const numberLocale = ref(saved.numberLocale || 'system');
  const autoRefresh = ref(saved.autoRefresh ?? true);
  const account = ref(saved.account || '');
  const preferredSymbol = ref(saved.preferredSymbol || 'BTCUSDT');
  const preferredInterval = ref(saved.preferredInterval || '15');
  const exchange = ref<string>(saved.exchange || 'bybit');

  const availableExchanges = ref<ExchangeOption[]>([
    { id: 'bybit', name: 'Bybit', lifecycle_status: 'ACTIVE_PAPER', paper_approved: true },
    { id: 'binance', name: 'Binance', lifecycle_status: 'ACTIVE_PAPER', paper_approved: true },
  ]);

  const selectedExchangeRecord = computed(() => {
    return availableExchanges.value.find((e) => e.id === exchange.value);
  });

  const selectedExchangeName = computed(() => {
    if (exchange.value === 'all') return 'All Exchanges';
    return selectedExchangeRecord.value?.name || (exchange.value ? exchange.value.toUpperCase() : 'Bybit');
  });

  const selectedExchangeStatus = computed(() => {
    if (exchange.value === 'all') return 'ALL';
    return selectedExchangeRecord.value?.lifecycle_status || 'UNKNOWN';
  });

  async function loadExchanges() {
    try {
      const res = await api.get('/exchanges');
      const list = res.data?.data;
      if (Array.isArray(list) && list.length > 0) {
        availableExchanges.value = list.map((e: any) => ({
          id: e.id,
          name: e.name || e.id.toUpperCase(),
          lifecycle_status: e.lifecycle_status,
          paper_approved: e.paper_approved,
        }));
        if (exchange.value !== 'all' && !availableExchanges.value.some((e) => e.id === exchange.value)) {
          exchange.value = 'bybit';
        }
      }
    } catch {
      // Fallback
    }
  }

  watch(exchange, () => {
    account.value = '';
  });

  watch(
    [locale, dark, timezone, numberLocale, autoRefresh, account, preferredSymbol, preferredInterval, exchange],
    () => localStorage.setItem('ui-settings', JSON.stringify({
      locale: locale.value, dark: dark.value, timezone: timezone.value,
      numberLocale: numberLocale.value, autoRefresh: autoRefresh.value, account: account.value,
      preferredSymbol: preferredSymbol.value, preferredInterval: preferredInterval.value,
      exchange: exchange.value,
    })),
    { deep: true },
  );

  return {
    locale, dark, timezone, numberLocale, autoRefresh, account,
    preferredSymbol, preferredInterval, exchange,
    availableExchanges, selectedExchangeRecord, selectedExchangeName, selectedExchangeStatus,
    loadExchanges,
  };
});
