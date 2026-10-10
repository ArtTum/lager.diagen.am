<script setup>
import { useLiveRefresh } from '@/composables/useLiveRefresh';
import Pagination from '@/components/Pagination.vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import api from '@/services/api';
import { currentUser } from '@/router';
import { userContextChanged } from '@/router/access';
import ExportActions from '@/components/ExportActions.vue';
import ListFilterBar from '@/components/ListFilterBar.vue';
import { formatDisplayDate } from '@/dateUtils';
import { statusOptions } from '@/workflowStatus';
import StatusBadge from '@/components/StatusBadge.vue';
import WorkflowStatusGuide from '@/components/WorkflowStatusGuide.vue';

const route = useRoute();
const user = ref(currentUser());
const result = ref(null); const options = ref({ branches: [], products: [] });
const requestSuggestions = ref({}); const suggestionBusy = ref(false);
const busy = ref(false); const saving = ref(false); const search = ref(''); const error = ref(''); const notice = ref('');
const modal = ref(false); const reviewModal = ref(false); const confirmAction = ref(null); const editingId = ref(null); const reviewRequest = ref(null);
const detailsOpen = ref(false); const detailsBusy = ref(false); const detailsError = ref(''); const details = ref(null);
const filters = reactive({ status: '', urgency: '', branch_id: '', from: '', to: '' });
const form = reactive({ branch_id: '', urgency: 'normal', reason: '', submit_mode: 'send', items: [{ product_id: '', qty: '', note: '' }] });
let debounce;
let listRequestVersion = 0;
let suggestionRequestVersion = 0;
let detailRequestVersion = 0;
let mutationRequestVersion = 0;
let mutationSessionVersion = 0;
const rows = computed(() => result.value?.data || []);
const filterSelects = computed(() => [
  { key: 'status', label: 'Կարգավիճակ', allLabel: 'Բոլոր փուլերը', options: statusOptions('requests') },
  { key: 'urgency', label: 'Հրատապություն', options: [{ value: 'normal', label: 'Սովորական' }, { value: 'high', label: 'Բարձր' }, { value: 'urgent', label: 'Շտապ' }] },
  { key: 'branch_id', label: 'Մասնաճյուղ', allLabel: 'Բոլոր մասնաճյուղերը', options: (result.value?.filter_options?.branches || []).map((branch) => ({ value: branch.id, label: branch.name })) },
]);
const can = (permission) => Boolean(user.value?.permissions?.[permission]);
const isAdmin = computed(() => user.value?.role?.name === 'admin');
const location = computed(() => Number(user.value?.location_id || 0));

async function load(page=1,pageSize=result.value?.pagination.per_page||15) { const version=++listRequestVersion;busy.value=true;error.value='';try{const r=await api.get('pages/requests',{params:{...filters,page,per_page:pageSize,search:search.value||undefined}});if(version===listRequestVersion)result.value=r.data;}catch(e){if(version===listRequestVersion)error.value=e.response?.data?.message||'Պահանջագրերի ցանկը չհաջողվեց բեռնել։';}finally{if(version===listRequestVersion)busy.value=false;} }
function resetFilters(){Object.assign(filters,{status:'',urgency:'',branch_id:'',from:'',to:''});load(1);}
watch(search,()=>{clearTimeout(debounce);debounce=setTimeout(()=>load(1),250);});
const updateUser=(event)=>{
  const changed=userContextChanged(user.value,event.detail);user.value=event.detail;
  if(!changed)return;
  closeDetails();closeMutationForms();mutationSessionVersion+=1;listRequestVersion+=1;
  saving.value=false;busy.value=false;result.value=null;options.value={branches:[],products:[]};error.value='';notice.value='';
  if(can('requests.view'))load(1);
};
onMounted(()=>{load();window.addEventListener('lager:user',updateUser);});
onBeforeUnmount(()=>{listRequestVersion+=1;mutationSessionVersion+=1;closeMutationForms();closeDetails();clearTimeout(debounce);window.removeEventListener('lager:user',updateUser);});
function closeMutationForms() {
  closeDetails();
  mutationRequestVersion += 1; suggestionRequestVersion += 1;
  modal.value = false; reviewModal.value = false; confirmAction.value = null; editingId.value = null; reviewRequest.value = null;
  requestSuggestions.value = {}; suggestionBusy.value = false;
}
function closeDetails() {
  detailRequestVersion += 1;
  detailsOpen.value = false; detailsBusy.value = false; details.value = null; detailsError.value = '';
}
async function openDetails(row) {
  if (!can('requests.view')) return;
  closeMutationForms();
  const version = ++detailRequestVersion;
  detailsOpen.value = true; detailsBusy.value = true; details.value = null; detailsError.value = '';
  try {
    const response = await api.get(`requests/${row.id}`);
    if (version !== detailRequestVersion || !can('requests.view')) return;
    const snapshot = response.data.data;
    if (Number(snapshot?.id) !== Number(row.id) || !Array.isArray(snapshot?.items)) throw new Error('incomplete request');
    details.value = snapshot;
  } catch (e) {
    if (version === detailRequestVersion) detailsError.value = e.response?.data?.message || 'Պահանջագրի տվյալները չհաջողվեց բեռնել։';
  } finally { if (version === detailRequestVersion) detailsBusy.value = false; }
}
function approvedQuantity(item) {
  return ['approved', 'partially_approved', 'collecting', 'ready_to_ship', 'shipped', 'received', 'closed'].includes(details.value?.status)
    ? item.approved_qty ?? '—' : '—';
}
async function loadOptions(version){const r=await api.get('catalog/requests/options');if(version!==mutationRequestVersion)return false;options.value=r.data.data;return true;}
async function loadSuggestions(){const version=++suggestionRequestVersion;const branchId=Number(form.branch_id||0);requestSuggestions.value={};suggestionBusy.value=false;if(!branchId)return;suggestionBusy.value=true;try{const r=await api.get('requests/suggestions',{params:{branch_id:branchId}});if(version===suggestionRequestVersion)requestSuggestions.value=r.data.data.items||{};}catch(e){if(version===suggestionRequestVersion)error.value=e.response?.data?.message||'Մնացորդի առաջարկը չհաջողվեց բեռնել։';}finally{if(version===suggestionRequestVersion)suggestionBusy.value=false;}}
function suggestionFor(productId){return requestSuggestions.value[String(productId)]||null;}
function useSuggestion(line){const suggestion=suggestionFor(line.product_id);if(suggestion)line.qty=suggestion.suggested;}
async function create(){
  if(saving.value||!can('requests.view')||!can('requests.create'))return;
  closeMutationForms();const version=mutationRequestVersion;error.value='';
  try{
    if(!await loadOptions(version)||version!==mutationRequestVersion||!can('requests.create'))return;
    const branchId=location.value===0?(options.value.branches.find(branch=>branch.code!=='CENTRAL')?.id||''):(user.value?.branch?.id||'');
    Object.assign(form,{branch_id:branchId,urgency:'normal',reason:'',submit_mode:'send',items:[{product_id:'',qty:'',note:''}]});modal.value=true;await loadSuggestions();
  }catch(e){if(version===mutationRequestVersion)error.value=e.response?.data?.message||'Տվյալները չհաջողվեց բեռնել։';}
}
function addLine(){form.items.push({product_id:'',qty:'',note:''});}function removeLine(i){if(form.items.length>1)form.items.splice(i,1);}
async function edit(row){
  if(saving.value||action(row)?.[0]!=='edit'||!can('requests.view'))return;
  closeMutationForms();const version=mutationRequestVersion;error.value='';
  try{
    if(!await loadOptions(version)||version!==mutationRequestVersion)return;
    const r=await api.get(`requests/${row.id}`);if(version!==mutationRequestVersion||!can('requests.edit'))return;
    const d=r.data.data;
    if(Number(d?.id)!==Number(row.id)||d.status!=='draft'||!Array.isArray(d.items)){error.value='Պահանջագիրն այլևս խմբագրվող սևագիր չէ։ Թարմացրեք ցանկը։';return;}
    editingId.value=row.id;Object.assign(form,{branch_id:d.branch_id,urgency:d.urgency,reason:d.reason||'',submit_mode:'draft',items:d.items.map(x=>({product_id:x.product_id,qty:x.requested_qty,note:x.note||''}))});modal.value=true;await loadSuggestions();
  }catch(e){if(version===mutationRequestVersion)error.value=e.response?.data?.message||'Պահանջագիրը չհաջողվեց բացել։';}
}
async function save(mode){
  if(saving.value||!modal.value||!can('requests.view')||!can(editingId.value?'requests.edit':'requests.create'))return;
  const session=mutationSessionVersion;saving.value=true;error.value='';
  try{
    const body={...form,branch_id:Number(form.branch_id),submit_mode:mode,items:form.items.map(x=>({...x,product_id:Number(x.product_id),qty:Number(x.qty)}))};
    if(editingId.value)await api.put(`requests/${editingId.value}/draft`,body);else await api.post('requests',body);
    if(session!==mutationSessionVersion)return;
    closeMutationForms();notice.value=mode==='draft'?'Սևագիրը պահպանվեց։':'Պահանջագիրն ուղարկվեց ստուգման։';await load(1);
    setTimeout(()=>{if(session===mutationSessionVersion)notice.value='';},3200);
  }catch(e){if(session===mutationSessionVersion)error.value=Object.values(e.response?.data?.errors||{})[0]?.[0]||e.response?.data?.message||'Պահանջագիրը չպահպանվեց։';}
  finally{if(session===mutationSessionVersion)saving.value=false;}
}
function canCancel(row){return ['draft','sent','review'].includes(row.status)&&can('requests.edit')&&(isAdmin.value||(Number(row.branch_id)===Number(user.value?.branch?.id)&&Number(row.requested_by)===Number(user.value?.id)));}
function action(row){if(row.status==='draft'&&can('requests.edit')&&(isAdmin.value||(Number(row.branch_id)===Number(user.value?.branch?.id)&&Number(row.requested_by)===Number(user.value?.id))))return ['edit','Խմբագրել'];if(row.status==='sent'&&can('requests.approve'))return ['start_review','Վերցնել ստուգման'];if(row.status==='review'&&can('requests.approve'))return ['review','Դիտարկել'];if(canCancel(row))return ['cancel','Չեղարկել'];if(['approved','partially_approved'].includes(row.status)&&can('requests.edit')&&(location.value===0||isAdmin.value))return ['collect','Պատրաստել ապրանքները'];if(row.status==='collecting'&&can('requests.edit')&&(location.value===0||isAdmin.value))return ['ready','Նշել՝ պատրաստ է ուղարկման'];if(row.status==='ready_to_ship'&&can('requests.edit')&&(location.value===0||isAdmin.value))return ['ship','Ուղարկել'];if(row.status==='shipped'&&can('requests.edit')&&(isAdmin.value||Number(row.branch_id)===Number(user.value?.branch?.id)))return ['receive','Հաստատել ստացումը'];if(row.status==='received'&&can('requests.edit')&&(isAdmin.value||Number(row.branch_id)===Number(user.value?.branch?.id)))return ['close','Ավարտել պահանջագիրը'];return null;}
async function openReview(row){
  if(saving.value||!can('requests.view')||!can('requests.approve'))return;
  closeMutationForms();const version=mutationRequestVersion;error.value='';
  try{
    const r=await api.get(`requests/${row.id}`);if(version!==mutationRequestVersion||!can('requests.approve'))return;
    const snapshot=r.data.data;
    if(Number(snapshot?.id)!==Number(row.id)||snapshot.status!=='review'||!Array.isArray(snapshot.items)){error.value='Պահանջագիրն այլևս ստուգման փուլում չէ։ Թարմացրեք ցանկը։';return;}
    reviewRequest.value={...snapshot,items:snapshot.items.map(x=>({...x,approve_qty:Number(x.approved_qty)>0?x.approved_qty:Math.min(Number(x.requested_qty)||0,Number(x.central_free_qty)||0)}))};reviewModal.value=true;
  }catch(e){if(version===mutationRequestVersion)error.value=e.response?.data?.message||'Պահանջագրի տողերը չհաջողվեց բացել։';}
}
async function review(decision){
  const row=reviewRequest.value;
  if(!row||!reviewModal.value||saving.value||!can('requests.view')||!can('requests.approve')||!['approve','reject'].includes(decision))return;
  const session=mutationSessionVersion;saving.value=true;error.value='';
  try{
    const data={decision};if(decision==='approve')data.approved=Object.fromEntries(row.items.map(x=>[x.id,Number(x.approve_qty||0)]));if(decision==='reject')data.rejection_reason=row.rejection_reason||'';
    await api.post(`requests/${row.id}/review`,data);if(session!==mutationSessionVersion)return;
    closeMutationForms();notice.value=decision==='reject'?'Պահանջագիրը մերժվեց։':'Պահանջագրի որոշումը պահպանվեց։';await load(result.value?.pagination.current_page||1);
    setTimeout(()=>{if(session===mutationSessionVersion)notice.value='';},3200);
  }catch(e){if(session===mutationSessionVersion)error.value=Object.values(e.response?.data?.errors||{})[0]?.[0]||e.response?.data?.message||'Որոշումը չպահպանվեց։';}
  finally{if(session===mutationSessionVersion)saving.value=false;}
}
function requestAction(row,key){if(key==='edit')return edit(row);if(key==='review')return openReview(row);if(key==='start_review')return reviewFor(row,'start_review');if(action(row)?.[0]!==key&&!(key==='cancel'&&canCancel(row)))return;closeMutationForms();confirmAction.value={row,key};}
async function reviewFor(row,decision){
  if(saving.value||!can('requests.view')||!can('requests.approve')||row.status!=='sent')return;
  const session=mutationSessionVersion;saving.value=true;error.value='';
  try{await api.post(`requests/${row.id}/review`,{decision});if(session!==mutationSessionVersion)return;notice.value='Պահանջագիրը տեղափոխվեց ստուգման փուլ։';await load(result.value?.pagination.current_page||1);}
  catch(e){if(session===mutationSessionVersion)error.value=e.response?.data?.message||'Գործողությունը չկատարվեց։';}
  finally{if(session===mutationSessionVersion)saving.value=false;}
}
async function runAction(){
  if(!confirmAction.value||saving.value||!can('requests.view'))return;
  const {row,key}=confirmAction.value;if(action(row)?.[0]!==key&&!(key==='cancel'&&canCancel(row)))return;
  const session=mutationSessionVersion;saving.value=true;error.value='';
  try{await api.post(`requests/${row.id}/${key}`);if(session!==mutationSessionVersion)return;closeMutationForms();notice.value='Գործողությունը կատարվեց։';await load(result.value?.pagination.current_page||1);}
  catch(e){if(session===mutationSessionVersion)error.value=e.response?.data?.message||'Գործողությունը չկատարվեց։';}
  finally{if(session===mutationSessionVersion)saving.value=false;}
}
const actionTitle=k=>({cancel:'Չեղարկե՞լ պահանջագիրը',collect:'Սկսե՞լ ապրանքների պատրաստումը',ready:'Նշե՞լ՝ պատրաստ է ուղարկման',ship:'Ուղարկե՞լ մասնաճյուղ',receive:'Հաստատե՞լ ստացումը',close:'Ավարտե՞լ պահանջագիրը'}[k]||'Հաստատե՞լ գործողությունը');
useLiveRefresh(() => load(result.value?.pagination.current_page || 1), { isBusy: () => busy.value || saving.value });
</script>

<template>
<div class="page-heading"><div><p class="eyebrow">ՊԱՀԱՆՋԻՑ ՄԻՆՉԵՎ ՍՏԱՑՈՒՄ</p><h1>{{route.meta.title}}</h1><p class="muted">Պահանջագիրը անցնում է ստուգման, հաստատման, հավաքագրման, առաքման և ստացման փուլերով։</p></div><button v-if="can('requests.create')" class="primary-button" @click="create"><span><AppIcon name="add" /></span>Նոր պահանջագիր</button></div>
<div v-if="error&&!modal&&!reviewModal&&!confirmAction" class="alert-error" role="alert">{{error}}</div><div v-if="notice" class="notice-success" role="status">{{notice}}</div>
<WorkflowStatusGuide workflow="requests" />
<section class="table-card"><div class="table-toolbar"><label class="search-input"><span class="search-icon"><AppIcon name="search" /></span><input v-model="search" class="form-control" placeholder="Որոնել համարով կամ մասնաճյուղով…"></label><div class="list-count">Ընդամենը՝ <b>{{result?.pagination.total??'…'}}</b></div><ExportActions page="requests" :search="search" :filters="filters" :disabled="busy" /></div><ListFilterBar :model-value="filters" @change="filters[$event.key] = $event.value" :selects="filterSelects" :date-range="true" @apply="load(1)" @reset="resetFilters" />
<div class="table-scroll"><table class="data-table"><thead><tr><th>Պահանջագիր</th><th>Մասնաճյուղ</th><th>Հրատապություն</th><th>Կարգավիճակ</th><th>Ստեղծվել է</th><th>Գործողություն</th></tr></thead><tbody><tr v-for="row in rows" :key="row.id"><td><strong>{{row.request_no}}</strong><RouterLink v-if="['shipped','received','closed'].includes(row.status)" class="dispatch-link" :to="'/requests/' + row.id + '/dispatch'">Դիտել / տպել բաշխումը</RouterLink></td><td>{{row.branch}}</td><td>{{({normal:'Սովորական',high:'Բարձր',urgent:'Շտապ'})[row.urgency]||row.urgency}}</td><td><StatusBadge workflow="requests" :status="row.status" /></td><td>{{formatDisplayDate(row.created_at)}}</td><td><button v-if="action(row)" class="secondary-button compact-action" :disabled="saving" @click="requestAction(row,action(row)[0])">{{action(row)[1]}}</button><button v-if="canCancel(row) && action(row)?.[0] !== 'cancel'" class="secondary-button compact-action" :disabled="saving" @click="requestAction(row,'cancel')">Չեղարկել</button><button v-if="can('requests.view')" type="button" class="secondary-button compact-action request-details-trigger" @click="openDetails(row)">Դիտել</button><span v-if="!action(row) && !canCancel(row) && !can('requests.view')" class="muted">—</span></td></tr><tr v-if="!busy&&result&&!rows.length"><td colspan="6" class="table-empty">{{search?'Որոնմանը համապատասխան պահանջագիր չկա։':'Պահանջագրեր դեռ չկան։'}}</td></tr><tr v-if="busy&&!result"><td colspan="6" class="table-empty">Բեռնվում է…</td></tr></tbody></table></div>
<Pagination v-if="result" :pagination="result.pagination" :busy="busy" @page-change="load" @per-page-change="load(1, $event)" /></section>

<div v-if="modal" class="modal-backdrop" @keydown.esc="closeMutationForms"><form class="modal-card request-modal" @submit.prevent="save('send')"><div class="modal-header"><div><p class="eyebrow">ՄԱՍՆԱՃՅՈՒՂԱՅԻՆ ՊԱՀԱՆՋ</p><h2>{{editingId?'Խմբագրել սևագիրը':'Նոր պահանջագիր'}}</h2><p>Ավելացրեք ապրանքներն ու քանակները, ապա ուղարկեք կենտրոնական պահեստ։</p></div><button aria-label="Փակել" class="icon-button close-button" type="button" @click="closeMutationForms"><AppIcon name="xmark" /></button></div>
<div class="form-grid"><label class="form-field">Մասնաճյուղ *<select v-searchable-select v-model="form.branch_id" class="form-control" :disabled="location>0" required @change="loadSuggestions"><option value="">Ընտրել մասնաճյուղը</option><option v-for="b in options.branches" :key="b.id" :value="b.id">{{b.name}}</option></select></label><label class="form-field">Հրատապություն<select v-searchable-select v-model="form.urgency" class="form-control"><option value="normal">Սովորական</option><option value="high">Բարձր</option><option value="urgent">Շտապ</option></select></label><label class="form-field span-2">Հիմնավորում<textarea v-model.trim="form.reason" class="form-control" placeholder="Պահանջի նպատակը"></textarea></label></div>
<div class="transfer-lines"><div class="section-label">Պահանջվող ապրանքներ</div><div v-for="(line,i) in form.items" :key="i" class="request-line-row"><label class="form-field">Ապրանք<select v-searchable-select v-model="line.product_id" class="form-control" required><option value="">Ընտրել ապրանքը</option><option v-for="p in options.products" :key="p.id" :value="p.id">{{p.code}} · {{p.name}}</option></select><small v-if="suggestionFor(line.product_id)" class="request-stock-hint">Մասնաճյուղում՝ {{suggestionFor(line.product_id).current}} · Միջին ամսական սպառում՝ {{suggestionFor(line.product_id).average}} · Առաջարկ՝ <b>{{suggestionFor(line.product_id).suggested}}</b> <button type="button" class="text-link" @click="useSuggestion(line)">Լրացնել առաջարկը</button></small><small v-else-if="suggestionBusy&&line.product_id" class="request-stock-hint">Մնացորդը հաշվարկվում է…</small></label><label class="form-field">Քանակ<input v-model="line.qty" class="form-control" type="number" min="0.001" step="0.001" required></label><label class="form-field">Նշում<input v-model.trim="line.note" class="form-control"></label><button v-if="form.items.length>1" class="icon-button danger remove-line" type="button" @click="removeLine(i)"><AppIcon name="xmark" /></button></div><button class="secondary-button add-line" type="button" @click="addLine"><AppIcon name="add" /> Ավելացնել ապրանք</button></div>
<p v-if="error" class="form-error">{{error}}</p><div class="modal-actions"><button type="button" class="secondary-button" @click="closeMutationForms">Չեղարկել</button><button type="button" class="secondary-button" :disabled="saving" @click="save('draft')">Պահպանել սևագիր</button><button class="primary-button" :disabled="saving">{{saving?'Պահպանվում է…':'Ուղարկել պահանջագիրը'}}</button></div></form></div>

<div v-if="reviewModal&&reviewRequest" class="modal-backdrop" @keydown.esc="closeMutationForms"><section class="modal-card request-modal"><div class="modal-header"><div><p class="eyebrow">ՊԱՀԱՆՋԱԳՐԻ ԴԻՏԱՐԿՈՒՄ</p><h2>{{reviewRequest.request_no}}</h2><p>{{reviewRequest.branch_name}} · ստուգեք քանակներն ու հաստատման հնարավորությունը։</p></div><button aria-label="Փակել" type="button" class="icon-button close-button" @click="closeMutationForms"><AppIcon name="xmark" /></button></div><div class="approval-list"><div v-for="line in reviewRequest.items" :key="line.id" class="approval-line"><div><b>{{line.name}}</b><small class="cell-subtitle">Պահանջված՝ {{line.requested_qty}} {{line.unit}} · Մասնաճյուղում՝ {{line.branch_current_qty??'—'}} {{line.unit}} · Միջին ամսական սպառում՝ {{line.branch_monthly_average??'—'}} {{line.unit}} · Կենտրոնի ազատ մնացորդ՝ {{line.central_free_qty??'—'}} {{line.unit}}</small></div><label class="form-field">Հաստատվող<input v-model="line.approve_qty" type="number" min="0" :max="Math.min(Number(line.requested_qty)||0,Number(line.central_free_qty)||0)" step="0.001" class="form-control"></label></div></div><label class="form-field rejection-field">Մերժման պատճառ<textarea v-model.trim="reviewRequest.rejection_reason" class="form-control" placeholder="Պարտադիր է միայն մերժելիս"></textarea></label><p v-if="error" class="form-error">{{error}}</p><div class="modal-actions"><button type="button" class="secondary-button" @click="closeMutationForms">Փակել</button><button class="danger-button" :disabled="saving" @click="review('reject')">Մերժել</button><button class="primary-button" :disabled="saving" @click="review('approve')">Հաստատել քանակները</button></div></section></div>

<div v-if="confirmAction" class="modal-backdrop" @keydown.esc="closeMutationForms"><section class="modal-card confirm-card"><button type="button" class="icon-button close-button modal-dismiss" aria-label="Փակել" @click="closeMutationForms"><AppIcon name="xmark" /></button><div class="metric-icon blue"><AppIcon name="transfers" /></div><h2>{{actionTitle(confirmAction.key)}}</h2><p>Պահանջագիր՝ <b>{{confirmAction.row.request_no}}</b></p><p class="muted">Գործողությունը կպահպանվի պատմությունում և կփոխի պահանջագրի ընթացիկ փուլը։</p><p v-if="error" class="form-error">{{error}}</p><div class="modal-actions"><button type="button" class="secondary-button" @click="closeMutationForms">Չեղարկել</button><button class="primary-button" :disabled="saving" @click="runAction">{{saving?'Կատարվում է…':'Հաստատել'}}</button></div></section></div>
<div v-if="detailsOpen" class="modal-backdrop" @keydown.esc="closeDetails">
  <section class="modal-card request-details-modal" role="dialog" aria-modal="true" aria-labelledby="request-details-title">
    <div class="modal-header"><div><p class="eyebrow">ՊԱՀԱՆՋԱԳՐԻ ՄԱՆՐԱՄԱՍՆԵՐ</p><h2 id="request-details-title">{{ details?.request_no || 'Պահանջագիր' }}</h2><p v-if="details">{{ details.branch_name || details.branch?.name || '—' }} · {{ formatDisplayDate(details.created_at) }}</p></div><button type="button" class="icon-button close-button" aria-label="Փակել" @click="closeDetails"><AppIcon name="xmark" /></button></div>
    <p v-if="detailsBusy" class="table-empty" role="status">Տվյալները բեռնվում են…</p>
    <p v-if="detailsError" class="form-error" role="alert">{{ detailsError }}</p>
    <template v-if="details">
      <StatusBadge workflow="requests" :status="details.status" />
      <p v-if="details.reason"><b>Պահանջի պատճառը․</b> {{ details.reason }}</p>
      <p v-if="details.rejection_reason" class="request-rejection-reason"><b>Մերժման պատճառը․</b> {{ details.rejection_reason }}</p>
      <div class="table-scroll"><table class="data-table"><thead><tr><th>Ապրանք</th><th>Պահանջված քանակ</th><th>Հաստատված քանակ</th><th>Միավոր</th><th>Նշում</th></tr></thead><tbody><tr v-for="item in details.items" :key="item.id"><td><strong>{{ item.code || item.product?.code }}</strong><span class="cell-subtitle">{{ item.name || item.product?.name }}</span></td><td>{{ item.requested_qty }}</td><td>{{ approvedQuantity(item) }}</td><td>{{ item.unit || item.product?.unit || '—' }}</td><td>{{ item.note || '—' }}</td></tr><tr v-if="!details.items.length"><td colspan="5" class="table-empty">Ապրանքային տողեր չկան։</td></tr></tbody></table></div>
    </template>
    <div class="modal-actions"><button type="button" class="secondary-button" @click="closeDetails">Փակել</button></div>
  </section>
</div>
</template>

<style scoped>
.request-details-modal { width: min(920px, calc(100vw - 32px)); }
.request-rejection-reason { padding: 12px; border-radius: 8px; background: #fff0f2; color: #a43c52; }
</style>
