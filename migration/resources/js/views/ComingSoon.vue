<script setup>
import { useRoute } from 'vue-router';
const route = useRoute();
</script>

<template><div class="page-heading"><div><p class="eyebrow">ՏԵՂԱՓՈԽՄԱՆ ԸՆԹԱՑՔՈՒՄ</p><h1>{{ route.meta.title }}</h1><p class="muted">Այս բաժնի գործողությունները տեղափոխվում են Laravel API և Vue միջավայր՝ հին համակարգի կանոնները պահպանելով։</p></div></div><article class="welcome-card"><div class="metric-icon violet">↗</div><div><h2>Բաժինը դեռ չի տեղափոխվել</h2><p class="muted">Մինչև ամբողջ գործառույթի ստուգումը, այն շարունակում է հասանելի մնալ գործող համակարգում։</p></div></article></template>
