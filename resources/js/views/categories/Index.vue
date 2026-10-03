<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { RouterLink } from 'vue-router';
import api from '@/services/api';
import { currentUser } from '@/router';
import { useLiveRefresh } from '@/composables/useLiveRefresh';
import DestructiveConfirmDialog from '@/components/DestructiveConfirmDialog.vue';

const user = ref(currentUser());
const categories = ref(null);
const search = ref('');
const busy = ref(false);
const saving = ref(false);
const error = ref('');
const notice = ref('');
const modal = ref(false);
const formError = ref('');
const form = reactive({ name: '', parent_id: '' });
const nameInput = ref(null);
const createButton = ref(null);
const confirmCategory = ref(null);
const confirmError = ref('');
const can = (permission) => Boolean(user.value?.permissions?.[permission]);
const canView = computed(() => can('products.view'));
let requestVersion = 0;
let sessionVersion = 0;

const parentName = (category) => category.parent?.name
    || (categories.value || []).find((item) => Number(item.id) === Number(category.parent_id))?.name || '';
const rows = computed(() => {
    const query = search.value.trim().toLocaleLowerCase('hy-AM');
    return (categories.value || []).filter((category) => !query
        || [category.name, parentName(category)].join(' ').toLocaleLowerCase('hy-AM').includes(query));
});

function deleteReason(category) {
    const products = Number(category.products_count);
    const children = Number(category.children_count);
    if (!Number.isFinite(products) || !Number.isFinite(children)) return 'Օգտագործման տվյալները հասանելի չեն։ Թարմացրեք ցանկը։';
    return [products > 0 ? `Կապակցված է ${products} ապրանքի։` : '', children > 0 ? `Ունի ${children} ենթատեսակ։` : ''].filter(Boolean).join(' ');
}

async function load() {
    if (!canView.value) return;
    const version = ++requestVersion;
    busy.value = true;
    error.value = '';
    try {
        const response = await api.get('categories');
        if (version === requestVersion) categories.value = response.data.data;
    } catch (exception) {
        if (version === requestVersion) error.value = exception.response?.data?.message || 'Տեսակների ցանկը չհաջողվեց բեռնել։';
    } finally { if (version === requestVersion) busy.value = false; }
}

async function openCreate() {
    if (!canView.value || !can('products.create') || saving.value || categories.value === null) return;
    Object.assign(form, { name: '', parent_id: '' });
    formError.value = ''; notice.value = ''; modal.value = true;
    await nextTick();
    nameInput.value?.focus();
}

function closeCreate() {
    if (saving.value) return;
    modal.value = false;
    formError.value = '';
    createButton.value?.focus();
}

function message(exception, fallback) {
    return Object.values(exception.response?.data?.errors || {})[0]?.[0] || exception.response?.data?.message || fallback;
}

async function save() {
    if (saving.value || !canView.value || !can('products.create')) return;
    if (!form.name.trim()) { formError.value = 'Լրացրեք տեսակի անվանումը։'; return; }
    const version = sessionVersion;
    saving.value = true; formError.value = '';
    try {
        await api.post('categories', { name: form.name.trim(), parent_id: form.parent_id === '' ? null : Number(form.parent_id) });
        if (version !== sessionVersion) return;
        modal.value = false;
        notice.value = 'Ապրանքի տեսակը ավելացվեց։';
        Object.assign(form, { name: '', parent_id: '' });
        await load();
    } catch (exception) {
        if (version === sessionVersion) formError.value = message(exception, 'Տեսակը չհաջողվեց ավելացնել։');
    } finally { if (version === sessionVersion) saving.value = false; }
}

function askDelete(category) {
    if (!canView.value || !can('products.delete') || busy.value || saving.value || deleteReason(category)) return;
    confirmCategory.value = { id: category.id, name: category.name };
    confirmError.value = ''; notice.value = '';
}

async function remove() {
    if (!confirmCategory.value || busy.value || saving.value || !canView.value || !can('products.delete')) return;
    const category = (categories.value || []).find((item) => Number(item.id) === Number(confirmCategory.value.id));
    if (!category || deleteReason(category)) {
        confirmError.value = category ? deleteReason(category) : 'Այս տեսակը այլևս ցանկում չէ։ Թարմացրեք ցանկը։';
        return;
    }
    const version = sessionVersion;
    saving.value = true; confirmError.value = '';
    try {
        await api.delete(`categories/${category.id}`);
        if (version !== sessionVersion) return;
        confirmCategory.value = null;
        categories.value = categories.value.filter((item) => Number(item.id) !== Number(category.id));
        notice.value = 'Ապրանքի տեսակը ջնջվեց։';
        await load();
    } catch (exception) {
        if (version === sessionVersion) confirmError.value = message(exception, 'Տեսակը չհաջողվեց ջնջել։');
    } finally { if (version === sessionVersion) saving.value = false; }
}

function userScope(value) {
    return JSON.stringify([value?.id, value?.location_id, value?.branch?.id,
        ['products.view', 'products.create', 'products.delete'].map((permission) => Boolean(value?.permissions?.[permission]))]);
}
const updateUser = (event) => {
    const changed = userScope(user.value) !== userScope(event.detail);
    user.value = event.detail;
    if (!changed) return;
    requestVersion += 1; sessionVersion += 1;
    categories.value = null; search.value = ''; error.value = ''; notice.value = '';
    modal.value = false; formError.value = ''; confirmCategory.value = null; confirmError.value = '';
    Object.assign(form, { name: '', parent_id: '' });
    busy.value = false; saving.value = false;
    if (canView.value) load();
};
onMounted(() => { load(); window.addEventListener('lager:user', updateUser); });
onBeforeUnmount(() => { requestVersion += 1; sessionVersion += 1; window.removeEventListener('lager:user', updateUser); });
useLiveRefresh(load, { isBusy: () => busy.value || saving.value });
</script>

<template>
    <div class="categories-page">
        <div class="page-heading">
            <div><p class="eyebrow">ԱՊՐԱՆՔՆԵՐԻ ԿԱՌԱՎԱՐՈՒՄ</p><h1>Ապրանքի տեսակներ</h1><p class="muted">Տեսակներն ընտրում եք ապրանքի քարտում։ Օգտագործվող կամ ենթատեսակներ ունեցող տեսակը չի ջնջվում։</p></div>
            <div class="category-page-actions"><RouterLink class="secondary-button" to="/products"><AppIcon name="arrowLeft" /> Ապրանքների ցանկ</RouterLink><button v-if="canView && can('products.create')" ref="createButton" class="primary-button" :disabled="categories === null || saving" @click="openCreate"><AppIcon name="add" /> Ավելացնել տեսակ</button></div>
        </div>
        <p v-if="!canView" class="alert-error" role="alert">Ապրանքի տեսակները դիտելու իրավունք չունեք։</p>
        <template v-else>
            <div v-if="notice" class="notice-success" role="status">{{ notice }}</div>
            <div v-if="error" class="alert-error category-load-error" role="alert"><span>{{ error }}</span><button type="button" class="secondary-button" :disabled="busy || saving" @click="load">Կրկին փորձել</button></div>
            <section class="table-card" :aria-busy="busy">
                <div class="table-toolbar"><label class="search-input"><span class="search-icon"><AppIcon name="search" /></span><input v-model="search" type="search" class="form-control" aria-label="Որոնել ապրանքի տեսակ" placeholder="Որոնել տեսակով կամ հիմնական տեսակով…"></label><div class="list-count">Տեսակներ՝ <b>{{ categories === null ? '…' : `${rows.length} / ${categories.length}` }}</b></div><span v-if="busy && categories !== null" class="muted" role="status">Թարմացվում է…</span></div>
                <div class="table-scroll"><table class="data-table category-table"><thead><tr><th>Տեսակ</th><th>Հիմնական տեսակ</th><th>Ապրանքներ</th><th>Ենթատեսակներ</th><th v-if="can('products.delete')">Գործողություն</th></tr></thead><tbody>
                    <tr v-for="category in rows" :key="category.id" :data-category-id="category.id"><td><strong>{{ category.name }}</strong><small v-if="deleteReason(category)" class="cell-subtitle category-usage-note">{{ deleteReason(category) }}</small></td><td>{{ parentName(category) || '—' }}</td><td>{{ category.products_count ?? '—' }}</td><td>{{ category.children_count ?? '—' }}</td><td v-if="can('products.delete')"><button v-if="!deleteReason(category)" type="button" class="danger-button compact-action" :disabled="busy || saving" :aria-label="`${category.name} տեսակը ջնջել`" @click="askDelete(category)"><AppIcon name="trash" /> Ջնջել</button><span v-else class="muted">Չի ջնջվում</span></td></tr>
                    <tr v-if="busy && categories === null"><td :colspan="can('products.delete') ? 5 : 4" class="table-empty" role="status">Տեսակները բեռնվում են…</td></tr>
                    <tr v-else-if="categories !== null && !rows.length"><td :colspan="can('products.delete') ? 5 : 4" class="table-empty">{{ search.trim() ? 'Որոնմանը համապատասխան տեսակ չկա։' : 'Ապրանքի տեսակներ դեռ չկան։' }}</td></tr>
                </tbody></table></div>
            </section>
        </template>

        <div v-if="modal && canView && can('products.create')" class="modal-backdrop" @click.self="closeCreate" @keydown.esc="closeCreate">
            <form class="modal-card category-form" role="dialog" aria-modal="true" aria-labelledby="category-create-title" @submit.prevent="save">
                <header class="modal-header"><div><p class="eyebrow">ԱՊՐԱՆՔԻ ՏԵՍԱԿ</p><h2 id="category-create-title">Ավելացնել տեսակ</h2><p class="muted">Անվանումը հասանելի կլինի ապրանքի քարտի ընտրացանկում։</p></div><button type="button" class="icon-button close-button" aria-label="Փակել" :disabled="saving" @click="closeCreate"><AppIcon name="xmark" /></button></header>
                <div class="category-form-fields"><label class="form-field">Տեսակի անվանում *<input ref="nameInput" v-model.trim="form.name" class="form-control" maxlength="120" required :disabled="saving" placeholder="Օրինակ՝ Լաբորատոր նյութեր"></label><label class="form-field">Հիմնական տեսակ (ընտրովի)<select v-searchable-select v-model="form.parent_id" class="form-control" :disabled="saving"><option value="">Առանց հիմնական տեսակի</option><option v-for="category in categories || []" :key="category.id" :value="category.id">{{ category.name }}</option></select></label></div>
                <p v-if="formError" class="form-error" role="alert">{{ formError }}</p>
                <div class="modal-actions"><button type="button" class="secondary-button" :disabled="saving" @click="closeCreate">Չեղարկել</button><button class="primary-button" :disabled="saving">{{ saving ? 'Ավելացվում է…' : 'Ավելացնել տեսակ' }}</button></div>
            </form>
        </div>
        <DestructiveConfirmDialog v-if="confirmCategory && canView && can('products.delete')" title="Ջնջե՞լ ապրանքի տեսակը" :entity="confirmCategory.name" :description="`«${confirmCategory.name}» տեսակը ընդմիշտ կհեռացվի։ Գործողությունը հնարավոր չէ հետ բերել։`" confirm-label="Ջնջել տեսակը" :busy-label="saving ? 'Ջնջվում է…' : 'Թարմացվում է…'" :busy="saving || busy" :error="confirmError" @cancel="confirmCategory = null" @confirm="remove" />
    </div>
</template>

<style scoped>
.category-page-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
.category-page-actions a, .category-page-actions button { display: inline-flex; align-items: center; gap: 8px; }
.category-load-error { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.category-table { min-width: 620px; }
.category-table th:nth-child(3), .category-table th:nth-child(4), .category-table td:nth-child(3), .category-table td:nth-child(4) { text-align: center; }
.category-usage-note { max-width: 290px; white-space: normal; line-height: 1.6; }
.category-form { width: min(540px, 100%); }
.category-form-fields { display: grid; gap: 17px; }
@media (max-width: 640px) { .category-page-actions { width: 100%; } .category-page-actions > * { flex: 1; justify-content: center; } .category-load-error { align-items: flex-start; flex-direction: column; } }
</style>
