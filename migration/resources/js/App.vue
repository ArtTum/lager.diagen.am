<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter, RouterView } from 'vue-router';
import api from '@/services/api';
import { currentUser } from '@/router';

const route = useRoute();
const router = useRouter();
const user = ref(null);
const openGroups = ref({ 'Գնումներ և գործողություններ': true });
const groups = [
    { title: 'Գնումներ և գործողություններ', links: [['suppliers', 'Մատակարարներ', '◇'], ['products', 'Ապրանքներ', '▦'], ['purchases', 'Գնումների պատվերներ', '▤'], ['receipts', 'Մուտքեր', '↓'], ['requests', 'Պահանջագրեր', '⇧'], ['transfers', 'Տեղափոխումներ', '⇄'], ['returns', 'Վերադարձներ', '↩']] },
    { title: 'Պաշար և վերահսկում', links: [['stock', 'Ընդհանուր մնացորդ', '▤'], ['movements', 'Պահեստի շարժ', '⇆'], ['inventory', 'Գույքագրում', '☷'], ['expiry', 'Ժամկետների վերահսկում', '◷']] },
    { title: 'Հաշվետվություններ', links: [['reports', 'Հաշվետվություններ', '▥'], ['audit', 'Գործողությունների պատմություն', '◉']] },
    { title: 'Կառավարում', links: [['branches', 'Մասնաճյուղեր', '⌂'], ['users', 'Օգտատերեր', '♙'], ['roles', 'Դերեր և իրավունքներ', '⚙']] },
];
const allowedGroups = computed(() => groups.map((group) => ({
    ...group,
    links: group.links.filter(([path]) => user.value?.permissions?.[`${path}.view`]),
})).filter((group) => group.links.length));
const isLogin = computed(() => route.path === '/login');

onMounted(() => {
    user.value = currentUser();
    window.addEventListener('lager:user', (event) => { user.value = event.detail; });
});

async function logout() {
    try { await api.post('auth/logout'); } catch { /* Drop this browser session even if the server token expired. */ }
    localStorage.removeItem('lagerAuthToken');
    window.dispatchEvent(new CustomEvent('lager:user', { detail: null }));
    router.replace('/login');
}
</script>

<template>
    <RouterView v-if="isLogin" />
    <div v-else class="app-shell">
        <aside class="sidebar">
            <RouterLink class="brand" to="/dashboard"><span class="brand-mark">Դ</span><span><b>ԴԻԱԳԵՆ</b><small>ՊԼՅՈՒՍ</small></span></RouterLink>
            <div class="side-caption">ՊԱՀԵՍՏԻ ԿԱՌԱՎԱՐՈՒՄ</div>
            <RouterLink class="nav-link" to="/dashboard"><span class="nav-icon">◫</span>Գլխավոր վահանակ</RouterLink>
            <section v-for="group in allowedGroups" :key="group.title" class="nav-group">
                <button class="group-heading" type="button" @click="openGroups[group.title] = !openGroups[group.title]">
                    <span>{{ group.title }}</span><span class="chevron" :class="{ rotated: openGroups[group.title] }">⌄</span>
                </button>
                <div v-show="openGroups[group.title]" class="subnav">
                    <RouterLink v-for="[path, title, icon] in group.links" :key="path" class="nav-link" :to="`/${path}`">
                        <span class="nav-icon">{{ icon }}</span>{{ title }}
                    </RouterLink>
                </div>
            </section>
            <div class="sidebar-bottom"><span class="avatar">{{ (user?.name || 'Դ').slice(0, 1) }}</span><span class="user-label"><b>{{ user?.name }}</b><small>{{ user?.role?.title }}</small></span><button class="logout-icon" title="Դուրս գալ" @click="logout">↪</button></div>
        </aside>
        <main class="main-area">
            <header class="topbar"><div class="topbar-label">Դիագեն Պլյուս <span>/</span> {{ route.meta.title }}</div><div class="topbar-user"><span class="online-dot"></span>{{ user?.branch?.name || 'Կենտրոնական պահեստ' }}</div></header>
            <section class="page-content"><RouterView /></section>
        </main>
    </div>
</template>
