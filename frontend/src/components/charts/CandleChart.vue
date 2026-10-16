<script setup lang="ts">
import { CandlestickSeries, createChart, createSeriesMarkers, HistogramSeries, LineSeries } from 'lightweight-charts';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{ candles: any[]; indicators?: any; signals?: any[]; patterns?: any[] }>();
const element = ref<HTMLElement>();
let chart: ReturnType<typeof createChart> | undefined;

const points = (values: Array<string | null> = []) => values.flatMap((value, index) => {
  const candle = props.candles[index];
  return value === null || value === undefined || !candle ? [] : [{
    time: Math.floor(new Date(candle.open_time).getTime() / 1000) as any,
    value: Number(value),
  }];
});

const mount = () => {
  if (!element.value) return;
  chart?.remove();
  chart = createChart(element.value, {
    height: element.value.clientHeight || 420,
    layout: { background: { color: '#111827' }, textColor: '#94a3b8' },
    grid: { vertLines: { color: '#1f2937' }, horzLines: { color: '#1f2937' } },
  });
  const candles = chart.addSeries(CandlestickSeries);
  candles.setData(props.candles.map(value => ({
    time: Math.floor(new Date(value.open_time).getTime() / 1000) as any,
    open: Number(value.open), high: Number(value.high), low: Number(value.low), close: Number(value.close),
  })));
  const visibleSignals = (props.signals || []).filter(signal => ['BUY', 'SELL'].includes(signal.side));
  const signalMarkers = visibleSignals.map(signal => ({
    time: Math.floor(new Date(signal.candle_close_time || signal.decision_time).getTime() / 1000) as any,
    position: signal.side === 'BUY' ? 'belowBar' as const : 'aboveBar' as const,
    color: signal.side === 'BUY' ? '#35d0a0' : '#fb7185',
    shape: signal.side === 'BUY' ? 'arrowUp' as const : 'arrowDown' as const,
    text: `${signal.side} ${signal.quality_class || ''}`,
  }));
  const patternMarkers = (props.patterns || []).map(pattern => ({
    time: Math.floor((new Date(pattern.detected_at).getTime() - Number(pattern.timeframe) * 60_000) / 1000) as any,
    position: pattern.direction === 'BEARISH' ? 'aboveBar' as const : 'belowBar' as const,
    color: pattern.direction === 'BEARISH' ? '#f59e0b' : pattern.direction === 'BULLISH' ? '#a78bfa' : '#94a3b8',
    shape: 'circle' as const,
    text: pattern.pattern_name,
  }));
  createSeriesMarkers(candles, [...signalMarkers, ...patternMarkers].sort((a, b) => Number(a.time) - Number(b.time)));
  const latestSignal = visibleSignals[0];
  if (latestSignal) {
    const levels = [
      [latestSignal.entry_low, '#60a5fa', 'Entry low'], [latestSignal.entry_high, '#60a5fa', 'Entry high'],
      [latestSignal.stop_loss, '#fb7185', 'SL'], [latestSignal.take_profit_1, '#35d0a0', 'TP1'],
      [latestSignal.take_profit_2, '#10b981', 'TP2'],
    ] as const;
    levels.forEach(([price, color, title]) => {
      if (price !== null && price !== undefined) candles.createPriceLine({ price: Number(price), color, title, lineWidth: 1, axisLabelVisible: true });
    });
  }

  const series = props.indicators?.series;
  if (series) {
    const overlays = [
      [series.emaEntry, '#35d0a0', 'EMA 20'], [series.emaFast, '#60a5fa', 'EMA 50'],
      [series.emaSlow, '#f59e0b', 'EMA 200'], [series.vwap, '#c084fc', 'VWAP'],
      [series.bollingerBands?.upper, '#64748b', 'BB upper'], [series.bollingerBands?.lower, '#64748b', 'BB lower'],
      [series.supportResistance?.map((point: any) => point.support ?? null), '#22c55e', 'Support'],
      [series.supportResistance?.map((point: any) => point.resistance ?? null), '#ef4444', 'Resistance'],
    ] as const;
    overlays.forEach(([values, color, title]) => {
      const line = chart!.addSeries(LineSeries, { color, title, lineWidth: title.startsWith('BB') ? 1 : 2 });
      line.setData(points(values));
    });
    const volume = chart.addSeries(HistogramSeries, { priceFormat: { type: 'volume' }, priceScaleId: 'volume' });
    volume.priceScale().applyOptions({ scaleMargins: { top: .82, bottom: 0 } });
    volume.setData(props.candles.map((value, index) => ({
      time: Math.floor(new Date(value.open_time).getTime() / 1000) as any,
      value: Number(series.volume?.volume?.[index] ?? value.volume),
      color: Number(value.close) >= Number(value.open) ? '#35d0a066' : '#fb718566',
    })));
  }
  chart.timeScale().fitContent();
};

onMounted(mount);
watch(() => [props.candles, props.indicators, props.signals, props.patterns], mount, { flush: 'post' });
onBeforeUnmount(() => {
  chart?.remove();
  chart = undefined;
});
</script>

<template><div ref="element" class="candle" aria-label="Candles with backend indicators" /></template>
