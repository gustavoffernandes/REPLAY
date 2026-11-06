<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api'
import { setToken, applySession } from '../auth'
import AuthShell from '../components/AuthShell.vue'
import PasswordField from '../components/PasswordField.vue'

const route = useRoute()
const router = useRouter()
const email = ref('')
const password = ref('')
const err = ref('')
const locked = ref(false)
const busy = ref(false)

async function submit() {
  if (busy.value) return
  err.value = ''
  locked.value = false
  busy.value = true
  try {
    const r = await api.login(email.value.trim(), password.value)
    setToken(r.token)
    applySession(r)
    router.replace(typeof route.query.next === 'string' && route.query.next.startsWith('/') ? route.query.next : '/')
  } catch (e) {
    err.value = e.message
    locked.value = e.status === 429
    password.value = ''
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AuthShell>
    <form @submit.prevent="submit" novalidate>
      <h1>Entrar</h1>
      <p class="muted sub">Acesse o painel de gestão das suas quadras.</p>

      <div class="field"><label for="email">E-mail</label><input id="email" class="input" type="email" v-model="email" required autofocus autocomplete="username" placeholder="voce@empresa.com" /></div>
      <PasswordField v-model="password" label="Senha" autocomplete="current-password" required placeholder="Sua senha">
        <template #aside><RouterLink to="/esqueci-senha" class="forgot" tabindex="0">Esqueci minha senha</RouterLink></template>
      </PasswordField>

      <p v-if="err" class="alert" :class="{ lock: locked }" role="alert">
        <strong>{{ locked ? 'Acesso temporariamente bloqueado' : 'Não foi possível entrar' }}</strong>{{ err }}
      </p>
      <button class="btn primary full" :disabled="busy || !email || !password">{{ busy ? 'Entrando…' : 'Entrar' }}</button>
    </form>
  </AuthShell>
</template>

<style scoped>
h1 { font-size: 30px; }
.sub { margin: 6px 0 26px; }
.full { width: 100%; padding: 13px; margin-top: 6px; font-size: 16px; }
.forgot { float: right; text-transform: none; letter-spacing: 0; font-size: 12px; color: var(--neon); }
.forgot:hover { text-decoration: underline; }
.alert { display: flex; flex-direction: column; gap: 2px; padding: 12px 14px; margin-bottom: 14px; border: 1px solid rgba(255, 90, 79, .4); background: rgba(255, 90, 79, .08); color: var(--danger); border-radius: 10px; font-size: 14px; }
.alert strong { font-size: 13px; }
.alert.lock { border-color: rgba(255, 194, 51, .45); background: rgba(255, 194, 51, .08); color: var(--warn); }
</style>
