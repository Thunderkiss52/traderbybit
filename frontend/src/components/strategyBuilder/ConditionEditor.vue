<script setup lang="ts">
import { computed } from "vue";
import type {
    Comparison,
    Condition,
} from "../../strategyBuilder/definition.js";
const props = withDefaults(
    defineProps<{
        modelValue: Condition;
        indicators: string[];
        depth?: number;
    }>(),
    { depth: 0 },
);
const emit = defineEmits<{ "update:modelValue": [value: Condition] }>();
const kind = computed(() =>
    props.modelValue.and
        ? "AND"
        : props.modelValue.or
          ? "OR"
          : props.modelValue.not
            ? "NOT"
            : "comparison",
);
const children = computed(
    () => props.modelValue.and || props.modelValue.or || [],
);
const kinds = ["comparison", "AND", "OR", "NOT"];
const modes = ["number", "indicator"];
const numberMode = ["number"];
const operators: Comparison[] = [
    "gt",
    "lt",
    "gte",
    "lte",
    "crosses_above",
    "crosses_below",
    "within",
    "change_pct",
];
const leaf = (): Condition => ({
    op: "gt",
    left: props.indicators[0] || "fast",
    right: 0,
});
function changeKind(value: string) {
    emit(
        "update:modelValue",
        value === "AND"
            ? { and: [props.modelValue] }
            : value === "OR"
              ? { or: [props.modelValue] }
              : value === "NOT"
                ? { not: props.modelValue }
                : leaf(),
    );
}
function child(index: number, value: Condition) {
    const list = children.value.map((c, i) => (i === index ? value : c));
    emit(
        "update:modelValue",
        kind.value === "AND" ? { and: list } : { or: list },
    );
}
function add() {
    const list = [...children.value, leaf()];
    emit(
        "update:modelValue",
        kind.value === "AND" ? { and: list } : { or: list },
    );
}
function remove(index: number) {
    const list = children.value.filter((_, i) => i !== index);
    emit(
        "update:modelValue",
        kind.value === "AND" ? { and: list } : { or: list },
    );
}
function patch(value: Partial<Condition>) {
    emit("update:modelValue", { ...props.modelValue, ...value });
}
function operation(value: Comparison) {
    patch({
        op: value,
        right:
            value === "within"
                ? [0, 1]
                : value === "change_pct"
                  ? 0
                  : Array.isArray(props.modelValue.right)
                    ? 0
                    : props.modelValue.right,
    });
}
const mode = computed(() =>
    Array.isArray(props.modelValue.right)
        ? "range"
        : typeof props.modelValue.right === "string"
          ? "indicator"
          : "number",
);
function operandMode(value: string) {
    patch({ right: value === "indicator" ? props.indicators[0] || "fast" : 0 });
}
function bound(index: number, value: string | number | null) {
    const values = Array.isArray(props.modelValue.right)
        ? [...props.modelValue.right]
        : [0, 1];
    values[index] = Number(value);
    patch({ right: values as [number, number] });
}
</script>
<template>
    <div class="rule q-pa-sm q-my-sm">
        <q-select
            :model-value="kind"
            :options="depth < 8 ? kinds : kinds.slice(0, 1)"
            label="Condition type"
            dense
            @update:model-value="changeKind"
        />
        <template v-if="kind === 'NOT'">
            <ConditionEditor
                :model-value="modelValue.not!"
                :indicators="indicators"
                :depth="depth + 1"
                @update:model-value="emit('update:modelValue', { not: $event })"
            />
        </template>
        <template v-else-if="kind === 'AND' || kind === 'OR'">
            <div v-for="(node, index) in children" :key="index">
                <ConditionEditor
                    :model-value="node"
                    :indicators="indicators"
                    :depth="depth + 1"
                    @update:model-value="child(index, $event)"
                />
                <q-btn
                    label="Remove condition"
                    flat
                    size="sm"
                    :disable="children.length < 2"
                    @click="remove(index)"
                />
            </div>
            <q-btn
                label="Add condition"
                flat
                :disable="children.length >= 16 || depth >= 8"
                @click="add"
            />
        </template>
        <div v-else class="row q-col-gutter-sm">
            <q-select
                class="col-4"
                :model-value="modelValue.left"
                :options="indicators"
                label="Left indicator"
                @update:model-value="patch({ left: $event })"
            />
            <q-select
                class="col-4"
                :model-value="modelValue.op"
                :options="operators"
                label="Comparison"
                @update:model-value="operation"
            />
            <template v-if="mode === 'range'">
                <q-input
                    class="col-2"
                    :model-value="(modelValue.right as number[])[0]"
                    type="number"
                    label="Minimum"
                    @update:model-value="bound(0, $event)"
                />
                <q-input
                    class="col-2"
                    :model-value="(modelValue.right as number[])[1]"
                    type="number"
                    label="Maximum"
                    @update:model-value="bound(1, $event)"
                />
            </template>
            <template v-else>
                <q-select
                    class="col-2"
                    :model-value="mode"
                    :options="
                        modelValue.op === 'change_pct' ? numberMode : modes
                    "
                    label="Operand type"
                    @update:model-value="operandMode"
                />
                <q-select
                    v-if="mode === 'indicator'"
                    class="col-2"
                    :model-value="modelValue.right"
                    :options="indicators"
                    label="Right indicator"
                    @update:model-value="patch({ right: $event })"
                />
                <q-input
                    v-else
                    class="col-2"
                    :model-value="modelValue.right as number"
                    type="number"
                    label="Threshold"
                    @update:model-value="patch({ right: Number($event) })"
                />
            </template>
        </div>
    </div>
</template>
<style scoped>
.rule {
    border: 1px solid #445366;
    border-radius: 6px;
}
</style>
