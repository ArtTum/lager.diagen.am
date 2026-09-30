<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter, RouterView } from 'vue-router';
import api from '@/services/api';
import { currentUser, refreshCurrentUser } from '@/router';
import { userContextChanged } from '@/router/access';
import NotificationBell from '@/components/NotificationBell.vue';

const route = useRoute();
const router = useRouter();
const user = ref(null);
const mobileMenuOpen = ref(false);
let contextRefreshTimer;
const updateUser = (event) => { user.value = event.detail; };
const openGroups = ref({ 'Գնումներ և գործողություններ': true });
const groups = [
    { title: 'Գնումներ և գործողություններ', links: [['suppliers', 'Մատակարարներ', 'users'], ['products', 'Ապրանքներ', 'box'], ['purchases', 'Գնումների պատվերներ', 'clipboard'], ['receipts', 'Մուտքեր', 'arrowDown'], ['requests', 'Պահանջագրեր', 'plusFile'], ['transfers', 'Տեղափոխումներ', 'transfers'], ['returns', 'Վերադարձներ', 'returns']] },
    { title: 'Պաշար և վերահսկում', links: [['stock', 'Ընդհանուր մնացորդ', 'boxes'], ['movements', 'Պահեստի շարժ', 'movements'], ['inventory', 'Գույքագրում', 'clipboard'], ['expiry', 'Ժամկետների վերահսկում', 'clock']] },
    { title: 'Հաշվետվություններ', links: [['reports', 'Հաշվետվություններ', 'chart'], ['audit', 'Գործողությունների պատմություն', 'history']] },
    { title: 'Կառավարում', links: [['branches', 'Մասնաճյուղեր', 'branches'], ['users', 'Օգտատերեր', 'users'], ['roles', 'Դերեր և իրավունքներ', 'shield']] },
];
const allowedGroups = computed(() => groups.map((group) => ({
    ...group,
    links: group.links.filter(([path]) => user.value?.permissions?.[`${path}.view`]),
})).filter((group) => group.links.length));
watch([() => route.path, allowedGroups], () => {
    mobileMenuOpen.value = false;
    const activeGroup = allowedGroups.value.find((group) => group.links.some(([path]) =>
        route.path === `/${path}` || route.path.startsWith(`/${path}/`),
    ));
    if (activeGroup) openGroups.value[activeGroup.title] = true;
}, { immediate: true });
const homePath = computed(() => {
    if (user.value?.permissions?.['dashboard.view']) return '/dashboard';
    const firstAllowedPage = allowedGroups.value.flatMap((group) => group.links)[0]?.[0];
    return firstAllowedPage ? `/${firstAllowedPage}` : '/no-access';
});
const isLogin = computed(() => route.path === '/login');

onMounted(() => {
    user.value = currentUser();
    window.addEventListener('lager:user', updateUser);
    contextRefreshTimer = window.setInterval(refreshUserContext, 45000);
    window.addEventListener('focus', refreshUserContext);
});

onBeforeUnmount(() => {
    window.clearInterval(contextRefreshTimer);
    window.removeEventListener('lager:user', updateUser);
    window.removeEventListener('focus', refreshUserContext);
});

async function refreshUserContext() {
    if (isLogin.value || !localStorage.getItem('lagerAuthToken')) return;
    const previousUser = user.value;
    try {
        const freshUser = await refreshCurrentUser();
        if (!freshUser) return;
        user.value = freshUser;
        if (route.meta.permission && !freshUser.permissions?.[route.meta.permission]) {
            router.replace('/no-access');
        } else if (userContextChanged(previousUser, freshUser)) {
            // User scope changes can invalidate data already rendered in an open page.
            window.location.reload();
        }
    } catch { /* The API interceptor handles expired tokens; retain the shell for transient failures. */ }
}

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
        <button v-if="mobileMenuOpen" class="mobile-nav-backdrop" type="button" aria-label="Փակել նավարկման ընտրացանկը" @click="mobileMenuOpen = false"></button>
        <aside class="sidebar" :class="{ 'mobile-open': mobileMenuOpen }">
            <RouterLink class="brand" :to="homePath"><span class="brand-mark">Դ</span><span><b>ԴԻԱԳԵՆ</b><small>ՊԼՅՈՒՍ</small></span></RouterLink>
            <div class="side-caption">ՊԱՀԵՍՏԻ ԿԱՌԱՎԱՐՈՒՄ</div>
            <RouterLink v-if="user?.permissions?.['dashboard.view']" class="nav-link" to="/dashboard"><span class="nav-icon"><AppIcon name="dashboard" /></span>Գլխավոր վահանակ</RouterLink>
            <section v-for="group in allowedGroups" :key="group.title" class="nav-group">
                <button class="group-heading" type="button" @click="openGroups[group.title] = !openGroups[group.title]">
                    <span>{{ group.title }}</span><AppIcon name="chevronDown" class="chevron" :class="{ rotated: openGroups[group.title] }" />
                </button>
                <div v-show="openGroups[group.title]" class="subnav">
                    <RouterLink v-for="[path, title, icon] in group.links" :key="path" class="nav-link" :to="`/${path}`">
                        <span class="nav-icon"><AppIcon :name="icon" /></span>{{ title }}
                    </RouterLink>
                </div>
            </section>
            <div class="sidebar-bottom"><span class="avatar">{{ (user?.name || 'Դ').slice(0, 1) }}</span><span class="user-label"><b>{{ user?.name }}</b><small>{{ user?.role?.title }}</small></span><button class="logout-icon" type="button" aria-label="Դուրս գալ" title="Դուրս գալ" @click="logout"><AppIcon name="logout" /></button></div>
        </aside>
        <main class="main-area">
            <header class="topbar"><button class="mobile-menu-toggle" type="button" :aria-expanded="mobileMenuOpen" aria-label="Բացել նավարկման ընտրացանկը" @click="mobileMenuOpen = !mobileMenuOpen"><span></span><span></span><span></span></button><div class="topbar-label">Դիագեն Պլյուս <span>/</span> {{ route.meta.title }}</div><div class="topbar-actions"><NotificationBell /><div class="topbar-user"><span class="online-dot"></span>{{ user?.branch?.name || 'Կենտրոնական պահեստ' }}</div></div></header>
            <section class="page-content"><RouterView /></section>
        </main>
    </div>
</template>
