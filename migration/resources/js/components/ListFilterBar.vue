<script setup>
import AppIcon from '@/components/AppIcon.vue';

const props = defineProps({
    modelValue: { type: Object, required: true },
    selects: { type: Array, default: () => [] },
    dateRange: { type: Boolean, default: false },
    dateLabels: { type: Array, default: () => ['Սկսած', 'Մինչև'] },
});
const emit = defineEmits(['change', 'apply', 'reset']);

function update(key, value) {
    emit('change', { key, value });
}
</script>

<template>
    <form class="list-filter-bar" @submit.prevent="emit('apply')">
        <label v-for="select in selects" :key="select.key" class="form-field">
            {{ select.label }}
            <select class="form-control" :value="modelValue[select.key] || ''" @change="update(select.key, $event.target.value)">
                <option value="">{{ select.allLabel || 'Բոլորը' }}</option>
                <option v-for="option in select.options" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
        </label>
        <label v-if="dateRange" class="form-field">{{ dateLabels[0] }}<DatePicker :model-value="modelValue.from || ''" :max="modelValue.to || ''" @update:model-value="update('from', $event)" /></label>
        <label v-if="dateRange" class="form-field">{{ dateLabels[1] }}<DatePicker :model-value="modelValue.to || ''" :min="modelValue.from || ''" @update:model-value="update('to', $event)" /></label>
            <div class="list-filter-actions"><button class="primary-button" type="submit"><AppIcon name="adjust" />Կիրառել</button><button class="secondary-button" type="button" @click="emit('reset')"><AppIcon name="refresh" />Մաքրել</button></div>
    </form>
</template>
