<script setup lang="ts">
import { computed } from "vue";
import EquityChart from "../charts/EquityChart.vue";
const props = defineProps<{ run: Record<string, unknown> }>();
const decode = (v: unknown): Record<string, unknown> =>
    typeof v === "string"
        ? JSON.parse(v)
        : (v as Record<string, unknown>) || {};
const result = computed(() => decode(props.run.result));
const metadata = computed(() => decode(props.run.metadata));
const report = computed(() => decode(result.value.report));
const metrics = computed(() =>
    Object.fromEntries(
        Object.entries(report.value).filter(
            ([key]) => !["trades", "equity_series", "metadata"].includes(key),
        ),
    ),
);
const points = computed(() =>
    ((report.value.equity_series || []) as Array<Record<string, unknown>>).map(
        (p) => ({ recorded_at: p.at || p.time, equity: p.equity }),
    ),
);
</script>
<template>
    <section>
        <p>Run {{ run.id }}</p>
        <q-banner
            v-if="metadata.funding_complete !== true"
            class="bg-warning text-black"
            >Funding is not modeled in this run. PAPER eligibility requires
            complete funding evidence.</q-banner
        >
        <q-markup-table
            ><tbody>
                <tr v-for="(value, key) in metrics" :key="key">
                    <th>{{ key }}</th>
                    <td>{{ value ?? "N/A" }}</td>
                </tr>
            </tbody></q-markup-table
        >
        <EquityChart :points="points" />
        <q-expansion-item label="Trades, executions and reproducible evidence">
            <pre>{{ JSON.stringify({ metadata, result }, null, 2) }}</pre>
        </q-expansion-item>
    </section>
</template>
<style scoped>
pre {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    max-height: 600px;
    overflow: auto;
}
</style>
