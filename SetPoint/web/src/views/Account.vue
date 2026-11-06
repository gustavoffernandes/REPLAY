<script setup>
import { ref, computed } from 'vue'
import { api } from '../api'
import { auth } from '../auth'
import { isStrong } from '../passwordRules'
import PasswordField from '../components/PasswordField.vue'

const current = ref('')
const password = ref('')
const confirm = ref('')
const err = ref('')
const ok = ref(false)
const busy = ref(false)

const email = computed(() => auth.user?.email || '')
const matches = computed(() => password.value && password.value === confirm.value)
const canSubmit = computed(() => current.value && isStrong(password.value, email.value) && matches.value && password.value !== current.value && !busy.value)

async function submit() {
  if (!canSubmit.value) return
  err.value = ''
  ok.value = false
  busy.value = true
  try {
    await api.changePassword({ current_password: current.value, password: password.value, password_confirmation: confirm.value })
    ok.value = true
    current.value = password.value = confirm.value = ''
  } catch (e) {
    err.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="page-head">
    <div><p class="muted">Seus dados de acesso</p><h1>Minha conta</h1></div>
  </div>

  <div class="grid">
    <section class="card">
      <h2>Perfil</h2>
      <dl>
        <div><dt>Nome</dt><dd>{{ auth.user.name }}</dd></div>
        <div><dt>E-mail</dt><dd>{{ auth.user.email }}</dd></div>
        <div><dt>Papel</dt><dd><span class="badge confirmed">{{ auth.user.role_label }}</span></dd></div>
        <div v-if="auth.user.company"><dt>Empresa</dt><dd>{{ auth.user.company.name }}</dd></div>
      </dl>
    </section>

    <section class="card">
      <h2>Alterar senha</h2>
      <p class="muted sub">Ao trocar a senha, os acessos abertos em <strong>outros dispositivos</strong> são encerrados. Este continua ativo.</p>
      <form @submit.prevent="submit">
        <PasswordField v-model="current" label="Senha atual" autocomplete="current-password" required />
        <PasswordField v-model="password" label="Nova senha" :email="email" meter required />
        <PasswordField v-model="confirm" label="Confirmar nova senha" required />
        <p v-if="confirm && !matches" class="error">As senhas não conferem.</p>
        <p v-if="password && password === current" class="error">A nova senha precisa ser diferente da atual.</p>
        <p v-if="err" class="error" role="alert">{{ err }}</p>
        <p v-if="ok" class="okmsg" role="status">Senha alterada com sucesso.</p>
        <button class="btn primary" :disabled="!canSubmit">{{ busy ? 'Salvando…' : 'Alterar senha' }}</button>
      </form>
    </section>
  </div>
</template>

<style scoped>
.grid { display: grid; grid-template-columns: 1fr 1.4fr; gap: 16px; align-items: start; }
h2 { margin-bottom: 14px; }
.sub { margin: -6px 0 18px; line-height: 1.5; font-size: 14px; }
dl > div { display: flex; justify-content: space-between; gap: 16px; padding: 11px 0; border-bottom: 1px solid var(--border); }
dl > div:last-child { border-bottom: none; }
dt { color: var(--muted); font-size: 13px; }
dd { text-align: right; }
.okmsg { color: var(--neon); font-size: 14px; margin-bottom: 12px; }
@media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }
</style>
