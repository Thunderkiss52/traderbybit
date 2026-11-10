import { defineStore } from 'pinia';
import { computed, ref, watch } from 'vue';
import { api } from '../services/api';

export interface ExchangeOption {
  id: string;
  name: string;
  lifecycle_status: string;
  paper_approved?: boolean;
}

export type ConnectivityStatus = 'LIVE' | 'STALE' | 'OFFLINE' | 'UNKNOWN';

export interface ExchangeConnectivity {
  status: ConnectivityStatus;
  label: string;
  color: string;
  detail?: string;
}

export interface InstrumentOption {
  id: string;
  name: string;
  symbol: string;
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
  const instrument = ref<string>(saved.instrument || 'all');

  const availableInstruments = ref<InstrumentOption[]>([
    { id: 'all', name: 'All Instruments', symbol: 'ALL' },
    { id: 'BTC', name: 'BTC · Bitcoin', symbol: 'BTCUSDT' },
    { id: 'ETH', name: 'ETH · Ethereum', symbol: 'ETHUSDT' },
  ]);

  const selectedInstrumentRecord = computed(() => {
    return availableInstruments.value.find((i) => i.id === instrument.value);
  });

  const selectedInstrumentName = computed(() => {
    return selectedInstrumentRecord.value?.name || (instrument.value === 'all' ? 'All Instruments' : instrument.value);
  });

  const resolvedSymbol = computed(() => {
    if (instrument.value === 'BTC') return 'BTCUSDT';
    if (instrument.value === 'ETH') return 'ETHUSDT';
    return '';
  });

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

  const connectivity = ref<ExchangeConnectivity>({
    status: 'UNKNOWN',
    label: 'DATA UNKNOWN',
    color: 'grey-8',
    detail: 'Initializing connectivity check...',
  });

  async function checkConnectivity() {
    try {
      const targetExchange = exchange.value;
      if (targetExchange === 'all') {
        const res = await api.get('/research/health/data-freshness');
        const payload = res.data?.data;
        if (!payload || !Array.isArray(payload.candle_freshness)) {
          connectivity.value = { status: 'UNKNOWN', label: 'DATA UNKNOWN', color: 'grey-8', detail: 'Invalid freshness payload' };
          return;
        }
        const candles = payload.candle_freshness;
        if (candles.length === 0) {
          connectivity.value = { status: 'OFFLINE', label: 'DATA OFFLINE', color: 'negative', detail: 'No market feeds active' };
          return;
        }
        const freshCount = candles.filter((c: any) => c.status === 'FRESH').length;
        const staleCount = candles.filter((c: any) => c.status === 'STALE').length;
        if (freshCount > 0 && staleCount === 0) {
          connectivity.value = { status: 'LIVE', label: 'DATA LIVE', color: 'positive', detail: 'All market feeds fresh' };
        } else if (freshCount > 0 && staleCount > 0) {
          connectivity.value = { status: 'STALE', label: 'DATA STALE', color: 'warning', detail: `${staleCount} series lagging` };
        } else if (staleCount > 0) {
          connectivity.value = { status: 'STALE', label: 'DATA STALE', color: 'warning', detail: 'All feeds lagging' };
        } else {
          connectivity.value = { status: 'OFFLINE', label: 'DATA OFFLINE', color: 'negative', detail: 'Feeds offline or missing' };
        }
        return;
      }

      const res = await api.get(`/exchanges/${targetExchange}/coverage`);
      const data = res.data?.data;
      if (!data || !data.diagnostics) {
        connectivity.value = { status: 'UNKNOWN', label: 'DATA UNKNOWN', color: 'grey-8', detail: 'Coverage diagnostics unavailable' };
        return;
      }
      const { has_live_adapter, public_data_fresh, adapter_status } = data.diagnostics;
      if (!has_live_adapter || adapter_status === 'OFFLINE_DATA_SOURCE') {
        connectivity.value = { status: 'OFFLINE', label: 'DATA OFFLINE', color: 'negative', detail: 'Adapter offline or uninitialized' };
      } else if (public_data_fresh) {
        connectivity.value = { status: 'LIVE', label: 'DATA LIVE', color: 'positive', detail: 'Market feed live & fresh' };
      } else if (data.total_candles > 0 || (Array.isArray(data.series) && data.series.length > 0)) {
        connectivity.value = { status: 'STALE', label: 'DATA STALE', color: 'warning', detail: 'Market feed data stale' };
      } else {
        connectivity.value = { status: 'OFFLINE', label: 'DATA OFFLINE', color: 'negative', detail: 'No feed data ingested' };
      }
    } catch (err: any) {
      connectivity.value = { status: 'UNKNOWN', label: 'DATA UNKNOWN', color: 'grey-8', detail: err?.message || 'Connection error' };
    }
  }

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
      void checkConnectivity();
    } catch {
      // Fallback
    }
  }

  watch(exchange, () => {
    account.value = '';
    void checkConnectivity();
  });

  watch(
    [locale, dark, timezone, numberLocale, autoRefresh, account, preferredSymbol, preferredInterval, exchange, instrument],
    () => localStorage.setItem('ui-settings', JSON.stringify({
      locale: locale.value, dark: dark.value, timezone: timezone.value,
      numberLocale: numberLocale.value, autoRefresh: autoRefresh.value, account: account.value,
      preferredSymbol: preferredSymbol.value, preferredInterval: preferredInterval.value,
      exchange: exchange.value, instrument: instrument.value,
    })),
    { deep: true },
  );

  return {
    locale, dark, timezone, numberLocale, autoRefresh, account,
    preferredSymbol, preferredInterval, exchange, instrument,
    availableExchanges, selectedExchangeRecord, selectedExchangeName, selectedExchangeStatus,
    availableInstruments, selectedInstrumentRecord, selectedInstrumentName, resolvedSymbol,
    connectivity, checkConnectivity,
    loadExchanges,
  };
});
