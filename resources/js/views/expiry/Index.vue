<script setup>
import Pagination from '@/components/Pagination.vue';
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import api from '@/services/api';
import ExportActions from '@/components/ExportActions.vue';
import { formatDisplayDate } from '@/dateUtils';

const route=useRoute();const result=ref(null);const busy=ref(false);const error=ref('');const search=ref('');
const filters=reactive({threshold:''});const rows=computed(()=>result.value?.data||[]);
async function load(page=1,pageSize=result.value?.pagination.per_page||15){busy.value=true;error.value='';try{const response=await api.get('pages/expiry',{params:{...filters,search:search.value||undefined,page,per_page:pageSize}});result.value=response.data;}catch(e){error.value=e.response?.data?.message||'Ժամկետների ցանկը չհաջողվեց բեռնել։';}finally{busy.value=false;}}
function clear(){filters.threshold='';search.value='';load(1);}
onMounted(()=>load());
const status=(days)=>Number(days)<0?{title:`Անցել է ${Math.abs(Number(days))} օր`,tone:'expired'}:Number(days)<=30?{title:`Մնացել է ${days} օր`,tone:'soon'}:{title:`Մնացել է ${days} օր`,tone:'safe'};
</script>

<template>
  <div class="page-heading"><div><p class="eyebrow">FEFO ՎԵՐԱՀՍԿՈՂՈՒԹՅՈՒՆ</p><h1>{{ route.meta.title }}</h1><p class="muted">Հետևեք LOT-երի ժամկետներին՝ պահեստի, մատակարարի և քանակի հետ միասին։</p></div></div>
  <div v-if="error" class="alert-error" role="alert">{{ error }}</div><section class="table-card expiry-card"><form class="expiry-toolbar" @submit.prevent="load(1)"><label class="form-field">Ժամկետի միջակայք<select v-searchable-select v-model="filters.threshold" class="form-control"><option value="">Բոլոր ժամկետները</option><option value="expired">Ժամկետանց</option><option value="7">Մինչև 7 օր</option><option value="30">Մինչև 30 օր</option><option value="60">Մինչև 60 օր</option><option value="90">Մինչև 90 օր</option><option value="180">Մինչև 180 օր</option></select></label><label class="form-field expiry-search">Որոնում<div class="search-input expiry-search-control"><span class="search-icon"><AppIcon name="search" /></span><input v-model.trim="search" class="form-control" placeholder="Ապրանք, կոդ, LOT կամ մատակարար"></div></label><button class="primary-button" :disabled="busy"><AppIcon name="adjust" />Կիրառել</button><button class="secondary-button" type="button" @click="clear"><AppIcon name="refresh" />Մաքրել</button></form><div class="table-toolbar"><span class="list-count">LOT-եր՝ <b>{{ result?.pagination.total ?? '…' }}</b></span><ExportActions page="expiry" :search="search" :threshold="filters.threshold" :disabled="busy" /></div><div class="table-scroll"><table class="data-table"><thead><tr><th>Ապրանք</th><th>LOT</th><th>Պահեստ</th><th>Մատակարար</th><th>Պիտանի է մինչև</th><th>Ժամկետ</th><th>Մնացորդ</th></tr></thead><tbody><tr v-for="row in rows" :key="row.id"><td><strong>{{ row.product }}</strong><small class="cell-subtitle">{{ row.code }} · {{ row.unit }}</small></td><td>{{ row.lot_no }}</td><td>{{ row.location || 'Կենտրոնական պահեստ' }}</td><td>{{ row.supplier || '—' }}</td><td>{{ formatDisplayDate(row.expires_on) }}</td><td><span class="expiry-pill" :class="status(row.days_left).tone">{{ status(row.days_left).title }}</span></td><td>{{ row.qty }} {{ row.unit }}</td></tr><tr v-if="!busy && result && !rows.length"><td colspan="7" class="table-empty">Ընտրված միջակայքում ժամկետ ունեցող պաշար չկա։</td></tr><tr v-if="busy&&!result"><td colspan="7" class="table-empty">Բեռնվում է…</td></tr></tbody></table></div><Pagination v-if="result" :pagination="result.pagination" :busy="busy" @page-change="load" @per-page-change="load(1, $event)" /></section>
</template>
