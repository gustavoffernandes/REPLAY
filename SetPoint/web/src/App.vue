<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from './api'
import GlobalLoading from './components/GlobalLoading.vue'
import { auth, can, clearSession, setBranch, companies } from './auth'

const route = useRoute()
const router = useRouter()

const links = [
  { to: '/', label: 'Dashboard', perm: 'dashboard.view', icon: 'M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z' },
  { to: '/agenda', label: 'Agenda', perm: 'bookings.view', icon: 'M7 2v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2V2h-2v2H9V2H7zm12 8v10H5V10h14z' },
  { to: '/reservas', label: 'Reservas', perm: 'bookings.view', icon: 'M4 6h16v2H4V6zm0 5h16v2H4v-2zm0 5h10v2H4v-2z' },
  { to: '/clientes', label: 'Clientes', perm: 'customers.view', icon: 'M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5C15 14.17 10.33 13 8 13zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z' },
  { to: '/turmas', label: 'Turmas', perm: 'classes.view', icon: 'M12 3L1 9l11 6 9-4.91V17h2V9L12 3zM5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82z' },
  { to: '/quadras', label: 'Quadras', perm: 'courts.view', icon: 'M3 5h18v14H3V5zm2 2v10h6V7H5zm8 0v10h6V7h-6z' },
  { to: '/replays', label: 'Replays', perm: 'replays.view', icon: 'M17 10.5V7a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-3.5l4 4v-11l-4 4z' },
]
const adminLinks = [
  { to: '/admin/empresas', label: 'Empresas', perm: 'companies.view', icon: 'M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10z' },
  { to: '/admin/filiais', label: 'Filiais', perm: 'branches.view', icon: 'M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 0 1 0-5 2.5 2.5 0 0 1 0 5z' },
  { to: '/admin/usuarios', label: 'Usuários', perm: 'users.view', icon: 'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z' },
  { to: '/admin/papeis', label: 'Papéis', perm: 'roles.view', icon: 'M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z' },
]
const visible = computed(() => links.filter((l) => can(l.perm)))
const visibleAdmin = computed(() => adminLinks.filter((l) => can(l.perm)))

// Seletor de filial: agrupa por empresa quando há mais de uma
const groups = computed(() => companies.value.map((c) => ({ ...c, branches: auth.branches.filter((b) => b.company_id === c.id) })))
const onBranch = (e) => setBranch(e.target.value || null)

async function logout() {
  try { await api.logout() } catch { /* token já inválido */ }
  clearSession()
  router.replace('/login')
}
</script>

<template>
  <GlobalLoading />
  <RouterView v-if="route.meta.public" />

  <div v-else-if="auth.user" class="shell">
    <aside class="side">
      <div class="logo"><span class="dot"></span>SET<b>POINT</b></div>

      <div class="scope">
        <label class="muted">{{ companies.length > 1 ? 'Empresa / filial' : auth.user.company?.name || 'Filial' }}</label>
        <select v-if="auth.branches.length > 1" class="input" :value="auth.branchId ?? ''" @change="onBranch">
          <option value="">Todas as filiais</option>
          <template v-if="companies.length > 1">
            <optgroup v-for="g in groups" :key="g.id" :label="g.name">
              <option v-for="b in g.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
            </optgroup>
          </template>
          <template v-else>
            <option v-for="b in auth.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
          </template>
        </select>
        <p v-else-if="auth.branches.length" class="only">{{ auth.branches[0].name }}</p>
        <p v-else class="only muted">Sem filiais atribuídas</p>
      </div>

      <nav>
        <RouterLink v-for="l in visible" :key="l.to" :to="l.to" class="nav">
          <svg viewBox="0 0 24 24" width="18" height="18"><path :d="l.icon" fill="currentColor" /></svg>
          <span>{{ l.label }}</span>
        </RouterLink>
        <template v-if="visibleAdmin.length">
          <p class="sec muted">Administração</p>
          <RouterLink v-for="l in visibleAdmin" :key="l.to" :to="l.to" class="nav">
            <svg viewBox="0 0 24 24" width="18" height="18"><path :d="l.icon" fill="currentColor" /></svg>
            <span>{{ l.label }}</span>
          </RouterLink>
        </template>
      </nav>

      <div class="me">
        <RouterLink to="/conta" class="who" title="Minha conta e senha">
          <strong>{{ auth.user.name }}</strong>
          <small class="muted">{{ auth.user.role_label }}</small>
        </RouterLink>
        <button class="out" title="Sair" aria-label="Sair" @click="logout">
          <svg viewBox="0 0 24 24" width="18" height="18"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5-5-5zM4 5h8V3H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8v-2H4V5z" fill="currentColor" /></svg>
        </button>
      </div>
    </aside>
    <main class="main"><RouterView :key="auth.branchId ?? 'all'" /></main>
  </div>
</template>

<style scoped>
.shell { display: grid; grid-template-columns: 240px 1fr; min-height: 100vh; }
.side { border-right: 1px solid var(--border); padding: 24px 16px; display: flex; flex-direction: column; gap: 22px; position: sticky; top: 0; height: 100vh; overflow-y: auto; }
.logo { font-weight: 500; font-size: 20px; letter-spacing: .14em; display: flex; align-items: center; gap: 10px; padding: 0 8px; }
.logo b { color: var(--neon); font-weight: 700; }
.dot { width: 10px; height: 10px; border-radius: 50%; background: var(--neon); box-shadow: 0 0 14px var(--neon); }
.scope { display: flex; flex-direction: column; gap: 6px; padding: 0 4px; }
.scope label { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; padding: 0 4px; }
.scope .input { padding: 8px 10px; font-size: 14px; }
.only { padding: 8px 10px; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; font-size: 14px; }
nav { display: flex; flex-direction: column; gap: 4px; }
.sec { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; padding: 14px 12px 4px; }
.nav { display: flex; align-items: center; gap: 12px; padding: 11px 12px; border-radius: 10px; color: var(--muted); transition: .15s; }
.nav:hover { color: var(--text); background: var(--surface); }
.nav.router-link-exact-active { color: var(--neon); background: var(--neon-dim); }
.me { margin-top: auto; display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 12px; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; }
.who { display: flex; flex-direction: column; min-width: 0; flex: 1; border-radius: 8px; }
.who:hover strong { color: var(--neon); }
.who strong { font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.out { background: transparent; border: none; color: var(--muted); cursor: pointer; padding: 6px; border-radius: 8px; display: grid; place-items: center; }
.out:hover { color: var(--danger); background: var(--surface-2); }
.main { padding: 32px 40px 60px; max-width: 1400px; width: 100%; }
@media (max-width: 800px) {
  .shell { grid-template-columns: 1fr; }
  .side { position: static; height: auto; flex-direction: row; align-items: center; overflow-x: auto; padding: 12px; gap: 12px; border-right: none; border-bottom: 1px solid var(--border); }
  .logo span:not(.dot), .scope label, .sec, .who { display: none; }
  .scope { min-width: 160px; }
  nav { flex-direction: row; }
  .nav span { display: none; }
  .me { margin-top: 0; padding: 6px; }
  .main { padding: 20px 16px 40px; }
}
</style>
