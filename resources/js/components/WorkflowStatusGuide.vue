<script setup>
import { computed } from 'vue';
import { statusInfo, statusOptions } from '@/workflowStatus';
import StatusBadge from '@/components/StatusBadge.vue';

const props = defineProps({ workflow: { type: String, required: true } });
const statuses = computed(() => statusOptions(props.workflow).map((item) => ({ ...item, ...statusInfo(props.workflow, item.value) })));
</script>

<template>
    <details v-if="statuses.length" class="workflow-guide">
        <summary><span>Կարգավիճակների բացատրություն</span><small>Ինչ է կատարվել և որն է հաջորդ քայլը</small></summary>
        <div class="workflow-guide-grid">
            <article v-for="item in statuses" :key="item.value" class="workflow-guide-item">
                <StatusBadge :workflow="workflow" :status="item.value" :show-description="false" />
                <p>{{ item.description }}</p>
            </article>
        </div>
    </details>
</template>

<style scoped>
.workflow-guide { margin: 0 0 18px; border: 1px solid #e1e7f2; border-radius: 13px; background: #fff; }
.workflow-guide summary { padding: 14px 18px; color: #40516f; font-size: 12px; font-weight: 600; cursor: pointer; }
.workflow-guide summary::marker { color: #7484bd; }
.workflow-guide summary small { margin-left: 12px; color: #91a0b5; font-size: 10px; font-weight: 400; }
.workflow-guide summary:focus-visible { outline: 2px solid #6879ff; outline-offset: 3px; border-radius: 13px; }
.workflow-guide[open] summary { border-bottom: 1px solid #edf0f7; }
.workflow-guide-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; padding: 16px; }
.workflow-guide-item { padding: 13px 15px; border: 1px solid #edf0f7; border-radius: 10px; background: #fbfcff; }
.workflow-guide-item p { margin: 8px 0 0; color: #65718a; font-size: 11px; line-height: 1.7; }
@media (max-width: 640px) { .workflow-guide-grid { grid-template-columns: 1fr; padding: 12px; } .workflow-guide summary small { display: block; margin: 5px 0 0 15px; } }
</style>
