<script setup>
import { ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '../api'
import { isStrong } from '../passwordRules'
import AuthShell from '../components/AuthShell.vue'
import PasswordField from '../components/PasswordField.vue'

const route = useRoute()
const router = useRouter()
const token = String(route.query.token || '')
const email = String(route.query.email || '')

const password = ref('')
const confirm = ref('')
const err = ref('')
const done = ref(false)
const busy = ref(false)

const validLink = computed(() => token && email)
const matches = computed(() => password.value && password.value === confirm.value)
const canSubmit = computed(() => isStrong(password.value, email) && matches.value && !busy.value)

async function submit() {
  if (!canSubmit.value) return
  err.value = ''
  busy.value = true
  try {
    await api.resetPassword({ token, email, password: password.value, password_confirmation: confirm.value })
    done.value = true
    setTimeout(() => router.replace('/login'), 2800)
  } catch (e) {
    err.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AuthShell>
    <div v-if="!validLink" class="state">
      <h1>Link inválido</h1>
      <p class="muted">Este endereço está incompleto. Solicite um novo link de redefinição.</p>
      <RouterLink to="/esqueci-senha" class="btn primary">Solicitar novo link</RouterLink>
    </div>

    <div v-else-if="done" class="state">
      <div class="icon">✓</div>
      <h1>Senha redefinida!</h1>
      <p class="muted">Sua nova senha já está valendo. Por segurança, os acessos abertos em outros dispositivos foram encerrados. Levando você ao login…</p>
      <RouterLink to="/login" class="btn primary">Ir para o login</RouterLink>
    </div>

    <form v-else @submit.prevent="submit">
      <h1>Criar nova senha</h1>
      <p class="muted sub">Conta: <strong>{{ email }}</strong></p>

      <PasswordField v-model="password" label="Nova senha" :email="email" meter required autofocus />
      <PasswordField v-model="confirm" label="Confirmar nova senha" required />
      <p v-if="confirm && !matches" class="error">As senhas não conferem.</p>

      <p v-if="err" class="error" role="alert">{{ err }}</p>
      <p v-if="err" class="muted small">Se o link expirou, <RouterLink to="/esqueci-senha" class="lnk">solicite um novo</RouterLink>.</p>
      <button class="btn primary full" :disabled="!canSubmit">{{ busy ? 'Salvando…' : 'Redefinir senha' }}</button>
      <RouterLink to="/login" class="back">← Voltar para o login</RouterLink>
    </form>
  </AuthShell>
</template>

<style scoped>
h1 { font-size: 28px; }
.sub { margin: 6px 0 22px; word-break: break-all; }
.full { width: 100%; padding: 13px; font-size: 16px; margin-top: 4px; }
.back { display: inline-block; margin-top: 20px; color: var(--muted); font-size: 14px; }
.back:hover, .lnk { color: var(--neon); }
.small { font-size: 13px; margin-bottom: 10px; }
.state { text-align: center; padding: 8px 0; display: flex; flex-direction: column; align-items: center; gap: 12px; }
.state p { line-height: 1.6; }
.icon { width: 60px; height: 60px; border-radius: 50%; display: grid; place-items: center; font-size: 28px; font-weight: 700; background: var(--neon); color: #000; box-shadow: 0 0 30px rgba(180, 255, 31, .4); }
</style>
