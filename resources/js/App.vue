<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter, RouterView } from 'vue-router';
import api from '@/services/api';
import { currentUser, refreshCurrentUser, setCurrentUser } from '@/router';
import { userContextChanged } from '@/router/access';
import NotificationBell from '@/components/NotificationBell.vue';
import { startRealtime } from '@/services/realtime';

const route = useRoute();
const router = useRouter();
const user = ref(null);
const isMobile = ref(window.matchMedia('(max-width: 1024px)').matches);
const mobileMenuOpen = ref(false);
const sidebarCollapsed = ref(false);
const navigationExpanded = computed(() => isMobile.value ? mobileMenuOpen.value : !sidebarCollapsed.value);
const profileMenuOpen = ref(false);
const accountDetailsOpen = ref(false);
const profileMenuRoot = ref(null);
let contextRefreshTimer;
let stopRealtime;
let contextRefreshDebounce;
const onDataChange = () => {
    window.clearTimeout(contextRefreshDebounce);
    contextRefreshDebounce = window.setTimeout(refreshUserContext, 150);
};
const updateUser = (event) => { user.value = event.detail; };
const closeProfileMenuOnOutsideClick = (event) => {
    if (!profileMenuRoot.value?.contains(event.target)) profileMenuOpen.value = false;
};
const closeProfileMenuOnEscape = (event) => {
    if (event.key === 'Escape') {
        profileMenuOpen.value = false;
        accountDetailsOpen.value = false;
        mobileMenuOpen.value = false;
    }
};
const syncResponsiveNavigation = () => {
    isMobile.value = window.matchMedia('(max-width: 1024px)').matches;
    if (!isMobile.value) mobileMenuOpen.value = false;
};
const toggleNavigation = () => {
    if (isMobile.value) mobileMenuOpen.value = !mobileMenuOpen.value;
    else sidebarCollapsed.value = !sidebarCollapsed.value;
};
const openGroups = ref({ 'Գնումներ և գործողություններ': true });
const groups = [
    { title: 'Գնումներ և գործողություններ', links: [['suppliers', 'Մատակարարներ', 'users'], ['products', 'Ապրանքներ', 'box'], ['categories', 'Ապրանքի տեսակներ', 'boxes', 'products.view'], ['purchases', 'Գնումների պատվերներ', 'clipboard'], ['receipts', 'Մուտքեր', 'arrowDown'], ['requests', 'Պահանջագրեր', 'plusFile'], ['transfers', 'Տեղափոխումներ', 'transfers'], ['returns', 'Վերադարձներ', 'returns']] },
    { title: 'Պաշար և վերահսկում', links: [['stock', 'Ընդհանուր մնացորդ', 'boxes'], ['movements', 'Պահեստի շարժ', 'movements'], ['inventory', 'Գույքագրում', 'clipboard'], ['expiry', 'Ժամկետների վերահսկում', 'clock']] },
    { title: 'Հաշվետվություններ', links: [['reports', 'Հաշվետվություններ', 'chart'], ['audit', 'Գործողությունների պատմություն', 'history']] },
    { title: 'Կառավարում', links: [['branches', 'Մասնաճյուղեր', 'branches'], ['users', 'Օգտատերեր', 'users'], ['roles', 'Դերեր և իրավունքներ', 'shield']] },
];
const allowedGroups = computed(() => groups.map((group) => ({
    ...group,
    links: group.links.filter(([path, , , permission]) => user.value?.permissions?.[permission || `${path}.view`]),
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
    window.addEventListener('resize', syncResponsiveNavigation);
    window.addEventListener('lager:user', updateUser);
    document.addEventListener('pointerdown', closeProfileMenuOnOutsideClick);
    document.addEventListener('keydown', closeProfileMenuOnEscape);
    contextRefreshTimer = window.setInterval(refreshUserContext, 45000);
    window.addEventListener('focus', refreshUserContext);
    window.addEventListener('lager:data-changed', onDataChange);
    stopRealtime = startRealtime(currentUser);
});

onBeforeUnmount(() => {
    window.clearInterval(contextRefreshTimer);
    window.removeEventListener('resize', syncResponsiveNavigation);
    window.removeEventListener('lager:user', updateUser);
    document.removeEventListener('pointerdown', closeProfileMenuOnOutsideClick);
    document.removeEventListener('keydown', closeProfileMenuOnEscape);
    window.removeEventListener('focus', refreshUserContext);
    window.removeEventListener('lager:data-changed', onDataChange);
    window.clearTimeout(contextRefreshDebounce);
    stopRealtime?.();
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
    const token = localStorage.getItem('lagerAuthToken');
    try {
        if (token) await api.post('auth/logout', null, { headers: { Authorization: `Bearer ${token}` } });
    } catch { /* Drop this browser session even if the server token expired. */ }
    if (localStorage.getItem('lagerAuthToken') !== token) return;
    localStorage.removeItem('lagerAuthToken');
    setCurrentUser(null);
    router.replace('/login');
}
</script>

<template>
    <RouterView v-if="isLogin" />
    <div v-else class="app-shell">
        <button v-if="mobileMenuOpen" class="mobile-nav-backdrop" type="button" aria-label="Փակել նավարկման ընտրացանկը" @click="mobileMenuOpen = false"></button>
        <aside class="sidebar" :class="{ 'mobile-open': mobileMenuOpen, 'sidebar-collapsed': sidebarCollapsed && !isMobile }">
            <RouterLink class="brand" :to="homePath" aria-label="Դիագեն Պլյուս․ գլխավոր էջ"><span class="brand-mark">Դ</span><span><b>ԴԻԱԳԵՆ</b><small>ՊԼՅՈՒՍ</small></span></RouterLink>
            <div class="side-caption">ՊԱՀԵՍՏԻ ԿԱՌԱՎԱՐՈՒՄ</div>
            <RouterLink v-if="user?.permissions?.['dashboard.view']" class="nav-link" to="/dashboard" aria-label="Գլխավոր վահանակ" :title="sidebarCollapsed && !isMobile ? 'Գլխավոր վահանակ' : undefined"><span class="nav-icon"><AppIcon name="dashboard" /></span>Գլխավոր վահանակ</RouterLink>
            <section v-for="group in allowedGroups" :key="group.title" class="nav-group">
                <button class="group-heading" type="button" :aria-expanded="Boolean(openGroups[group.title]) || !navigationExpanded" :aria-controls="`nav-group-${group.links[0][0]}`" @click="openGroups[group.title] = !openGroups[group.title]">
                    <span>{{ group.title }}</span><AppIcon name="chevronDown" class="chevron" :class="{ rotated: openGroups[group.title] }" />
                </button>
                <div :id="`nav-group-${group.links[0][0]}`" v-show="openGroups[group.title] || !navigationExpanded" class="subnav">
                    <RouterLink v-for="[path, title, icon] in group.links" :key="path" class="nav-link" :to="`/${path}`" :aria-label="title" :title="sidebarCollapsed && !isMobile ? title : undefined">
                        <span class="nav-icon"><AppIcon :name="icon" /></span>{{ title }}
                    </RouterLink>
                </div>
            </section>
            <div class="sidebar-bottom"><span class="avatar">{{ (user?.name || 'Դ').slice(0, 1) }}</span><span class="user-label"><b>{{ user?.name }}</b><small>{{ user?.role?.title }}</small></span><button class="logout-icon" type="button" aria-label="Դուրս գալ" title="Դուրս գալ" @click="logout"><AppIcon name="logout" /></button></div>
        </aside>
        <main class="main-area" :class="{ 'main-area-expanded': sidebarCollapsed && !isMobile }">
            <header class="topbar"><button class="mobile-menu-toggle" type="button" :aria-expanded="navigationExpanded" :aria-label="navigationExpanded ? 'Փակել նավարկման ընտրացանկը' : 'Բացել նավարկման ընտրացանկը'" @click="toggleNavigation"><span></span><span></span><span></span></button><div class="topbar-label">Դիագեն Պլյուս <span>/</span> {{ route.meta.title }}</div><div class="topbar-actions"><NotificationBell /><div class="topbar-user"><span class="online-dot"></span>{{ user?.branch?.name || 'Կենտրոնական պահեստ' }}</div><div ref="profileMenuRoot" class="profile-menu-root"><button class="profile-trigger" type="button" aria-label="Բացել հաշվի ընտրացանկը" :aria-expanded="profileMenuOpen" aria-haspopup="menu" @click.stop="profileMenuOpen = !profileMenuOpen"><span class="profile-avatar"><AppIcon name="profile" /></span><span class="profile-trigger-copy"><b>{{ user?.name || 'Օգտատեր' }}</b><small>{{ user?.role?.title || 'Օգտատեր' }}</small></span><AppIcon name="chevronDown" class="profile-chevron" :class="{ rotated: profileMenuOpen }" /></button><div v-if="profileMenuOpen" class="profile-popover" role="menu"><div class="profile-popover-heading"><strong>{{ user?.name || 'Օգտատեր' }}</strong><span>{{ user?.email }}</span><small>{{ user?.role?.title }}<template v-if="user?.branch?.name"> · {{ user.branch.name }}</template></small></div><button type="button" class="profile-menu-item" role="menuitem" @click="profileMenuOpen = false; accountDetailsOpen = true"><AppIcon name="accountSettings" /><span>Հաշվի կարգավորումներ</span></button><div class="profile-menu-divider"></div><button type="button" class="profile-menu-item profile-logout-item" role="menuitem" @click="profileMenuOpen = false; logout()"><AppIcon name="logout" /><span>Դուրս գալ</span></button></div></div></div></header>
            <section class="page-content"><RouterView :key="route.path" /></section>
        </main>
        <div v-if="accountDetailsOpen" class="modal-backdrop profile-details-backdrop" @click.self="accountDetailsOpen = false"><section class="modal-card profile-details-card" role="dialog" aria-modal="true" aria-labelledby="profile-details-title"><div class="modal-header"><div><p class="eyebrow">ԱՆՁՆԱԿԱՆ ՀԱՇԻՎ</p><h2 id="profile-details-title">Հաշվի կարգավորումներ</h2><p>Ձեր ընթացիկ օգտահաշվի տվյալները։</p></div><button class="icon-button close-button" type="button" aria-label="Փակել" @click="accountDetailsOpen = false"><AppIcon name="xmark" /></button></div><dl class="profile-details-list"><div><dt>Անուն</dt><dd>{{ user?.name || '—' }}</dd></div><div><dt>Էլ. փոստ</dt><dd>{{ user?.email || '—' }}</dd></div><div><dt>Դեր</dt><dd>{{ user?.role?.title || '—' }}</dd></div><div><dt>Մասնաճյուղ</dt><dd>{{ user?.branch?.name || 'Կենտրոնական պահեստ' }}</dd></div></dl><p class="profile-details-note">Հաշվի տվյալների փոփոխման համար դիմեք համակարգի ադմինիստրատորին։</p><div class="modal-actions"><button class="primary-button" type="button" @click="accountDetailsOpen = false">Փակել</button></div></section></div>
    </div>
</template>
