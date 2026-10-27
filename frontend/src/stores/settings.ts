import { defineStore } from 'pinia';
import { ref, watch } from 'vue';

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
  const exchange = ref<'bybit' | 'binance'>(saved.exchange === 'binance' ? 'binance' : 'bybit');

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

  return { locale, dark, timezone, numberLocale, autoRefresh, account, preferredSymbol, preferredInterval, exchange };
});
