import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import App from './App.vue'
import { api } from './api'
import { auth, can, applySession, clearSession } from './auth'
import './style.css'

// meta.perm: permissão exigida; meta.public: não exige login
const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', component: () => import('./views/Login.vue'), meta: { public: true } },
    { path: '/esqueci-senha', component: () => import('./views/ForgotPassword.vue'), meta: { public: true } },
    { path: '/redefinir-senha', component: () => import('./views/ResetPassword.vue'), meta: { public: true } },
    // Agendamento online para clientes avulsos (sem login)
    { path: '/reservar/:company/:branch?', component: () => import('./views/PublicBooking.vue'), meta: { public: true } },
    { path: '/reserva/:code', component: () => import('./views/PublicOrder.vue'), meta: { public: true } },
    { path: '/', component: () => import('./views/Dashboard.vue'), meta: { perm: 'dashboard.view' } },
    { path: '/agenda', component: () => import('./views/Schedule.vue'), meta: { perm: 'bookings.view' } },
    { path: '/reservas', component: () => import('./views/Bookings.vue'), meta: { perm: 'bookings.view' } },
    { path: '/clientes', component: () => import('./views/Customers.vue'), meta: { perm: 'customers.view' } },
    { path: '/turmas', component: () => import('./views/Classes.vue'), meta: { perm: 'classes.view' } },
    { path: '/quadras', component: () => import('./views/Courts.vue'), meta: { perm: 'courts.view' } },
    { path: '/replays', component: () => import('./views/Replays.vue'), meta: { perm: 'replays.view' } },
    { path: '/conta', component: () => import('./views/Account.vue') }, // qualquer usuário logado
    { path: '/admin/empresas', component: () => import('./views/Companies.vue'), meta: { perm: 'companies.view' } },
    { path: '/admin/filiais', component: () => import('./views/Branches.vue'), meta: { perm: 'branches.view' } },
    { path: '/admin/usuarios', component: () => import('./views/Users.vue'), meta: { perm: 'users.view' } },
    { path: '/admin/papeis', component: () => import('./views/Roles.vue'), meta: { perm: 'roles.view' } },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  if (to.meta.public) {
    return auth.token && to.path === '/login' && (await ensureSession()) ? '/' : true
  }
  if (!auth.token) return { path: '/login', query: to.fullPath !== '/' ? { next: to.fullPath } : {} }
  if (!(await ensureSession())) return '/login'
  if (to.meta.perm && !can(to.meta.perm)) return '/'
  return true
})

async function ensureSession() {
  if (auth.user) return true
  try {
    applySession(await api.me())
    return true
  } catch {
    clearSession()
    return false
  }
}

createApp(App).use(router).mount('#app')
