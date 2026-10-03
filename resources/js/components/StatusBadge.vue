<script setup>
import { computed } from 'vue';
import { statusInfo } from '@/workflowStatus';

const props = defineProps({
    workflow: { type: String, default: 'generic' },
    status: { type: [String, Number], default: '' },
    showDescription: { type: Boolean, default: true },
    description: { type: String, default: undefined },
});
const info = computed(() => statusInfo(props.workflow, props.status));
const explanation = computed(() => props.description ?? info.value.description);
</script>

<template>
    <span class="workflow-state" :class="{ 'workflow-state-compact': !showDescription }" :data-status="status">
        <span class="workflow-status" :class="`status-tone-${info.tone}`">{{ info.label }}</span>
        <small v-if="showDescription && explanation" class="workflow-state-note">{{ explanation }}</small>
    </span>
</template>

<style scoped>
.workflow-state { display: inline-flex; flex-direction: column; align-items: flex-start; gap: 6px; min-width: 175px; max-width: 280px; }
.workflow-state-compact { min-width: 0; }
.workflow-status { max-width: 100%; padding: 6px 10px; border-radius: 8px; font-size: 11px; line-height: 1.5; white-space: normal; }
.workflow-state-note { display: block; color: #65718a; font-size: 11px; font-weight: 400; line-height: 1.6; white-space: normal; }
.status-tone-neutral { background: #f0f2f6; color: #697690; }
.status-tone-waiting { background: #fff5e5; color: #a36a12; }
.status-tone-progress { background: #eaf0ff; color: #4762c9; }
.status-tone-partial, .status-tone-shipping { background: #f2ebff; color: #8052b9; }
.status-tone-success { background: #e8f7ef; color: #21875d; }
.status-tone-error { background: #fff0f2; color: #c9576f; }
</style>
