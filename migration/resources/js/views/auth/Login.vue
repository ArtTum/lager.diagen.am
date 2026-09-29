<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import api from '@/services/api';
import { setCurrentUser } from '@/router';

const router = useRouter();
const email = ref('');
const password = ref('');
const loading = ref(false);
const error = ref('');

async function submit() {
    if (loading.value) return;
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.post('auth/login', { email: email.value, password: password.value });
        localStorage.setItem('lagerAuthToken', data.data.token);
        setCurrentUser(data.data.user);
        await router.replace('/dashboard');
    } catch (e) {
        error.value = e.response?.data?.message || e.response?.data?.errors?.email?.[0] || 'Մուտքը չհաջողվեց։ Ստուգեք տվյալները։';
    } finally { loading.value = false; }
}
</script>

<template>
    <main class="login-screen">
        <section class="login-brand"><div class="brand-mark large">Դ</div><p class="eyebrow">ԴԻԱԳԵՆ ՊԼՅՈՒՍ · ՊԱՀԵՍՏ</p><h1>Պահեստի հստակ կառավարում։</h1><p class="login-description">Ապրանքների շարժը, մասնաճյուղերի պահանջագրերը և մնացորդների վերահսկումը՝ մեկ անվտանգ համակարգում։</p><div class="login-decoration"><span></span><span></span><span></span></div></section>
        <section class="login-panel"><div class="login-form-wrap"><div class="form-kicker">Բարի վերադարձ</div><h2>Մուտք գործել</h2><p class="muted">Մուտքագրեք ձեր աշխատանքային հաշվի տվյալները։</p><form @submit.prevent="submit"><label>Էլ. փոստ<input v-model.trim="email" type="email" autocomplete="username" placeholder="name@diagen.am" required></label><label>Գաղտնաբառ<input v-model="password" type="password" autocomplete="current-password" placeholder="Մուտքագրեք գաղտնաբառը" required></label><p v-if="error" class="form-error" role="alert">{{ error }}</p><button class="primary-button full-button" :disabled="loading">{{ loading ? 'Մուտք է կատարվում…' : 'Մուտք գործել' }}<span>→</span></button></form><small class="login-footnote">Մուտքի իրավունքը սահմանվում է ձեր դերով։</small></div></section>
    </main>
</template>
