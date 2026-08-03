<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { usePortalStore } from '@/autometria/stores/usePortalStore'
import PortalLogin from '@/Pages/Portal/PortalLogin.vue'
import PortalDashboard from '@/Pages/Portal/PortalDashboard.vue'
import PortalBooking from '@/Pages/Portal/PortalBooking.vue'

const store = usePortalStore()
const route = ref(location.hash.replace('#/', '') || (store.token ? 'dashboard' : 'login'))
const page = computed(() => (['login', 'dashboard', 'booking'].includes(route.value) ? route.value : 'login'))

function navigate(next) {
  location.hash = `#/${next}`
}

function syncRoute() {
  route.value = location.hash.replace('#/', '') || (store.token ? 'dashboard' : 'login')
}

function logout() {
  store.logout()
  navigate('login')
}

onMounted(() => window.addEventListener('hashchange', syncRoute))
onUnmounted(() => window.removeEventListener('hashchange', syncRoute))
</script>

<template>
  <main class="portal-shell">
    <header v-if="page !== 'login'" class="portal-header">
      <a href="#/dashboard" class="brand">LASTIK <span>Клиент</span></a>
      <nav>
        <button type="button" @click="navigate('dashboard')">Мои записи</button>
        <button type="button" @click="navigate('booking')">Записаться</button>
        <button type="button" @click="logout">Выйти</button>
      </nav>
    </header>
    <PortalLogin v-if="page === 'login'" @authenticated="navigate('dashboard')" />
    <PortalDashboard v-else-if="page === 'dashboard'" @book="navigate('booking')" />
    <PortalBooking v-else @done="navigate('dashboard')" />
  </main>
</template>

<style scoped>
.portal-shell { min-height: 100vh; color: #172033; font-family: Inter, system-ui, sans-serif; }
.portal-header { align-items: center; background: #fff; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; padding: 16px max(20px, calc((100% - 960px) / 2)); }
.brand { color: #172033; font-weight: 800; letter-spacing: .04em; text-decoration: none; }
.brand span { color: #2563eb; }
nav { display: flex; gap: 8px; }
button { background: transparent; border: 0; color: #334155; cursor: pointer; font: inherit; padding: 8px; }
button:hover { color: #2563eb; }
</style>
