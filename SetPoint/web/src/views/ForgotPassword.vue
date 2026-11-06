<script setup>
import { ref } from 'vue'
import { api } from '../api'
import AuthShell from '../components/AuthShell.vue'

const email = ref('')
const sent = ref(false)
const err = ref('')
const busy = ref(false)

async function submit() {
  if (busy.value) return
  err.value = ''
  busy.value = true
  try {
    await api.forgotPassword(email.value.trim())
    sent.value = true
  } catch (e) {
    err.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AuthShell>
    <template v-if="!sent">
      <form @submit.prevent="submit">
        <h1>Esqueci minha senha</h1>
        <p class="muted sub">Informe o e-mail da sua conta e enviaremos um link para criar uma nova senha.</p>
        <div class="field"><label for="email">E-mail</label><input id="email" class="input" type="email" v-model="email" required autofocus autocomplete="username" placeholder="voce@empresa.com" /></div>
        <p v-if="err" class="error" role="alert">{{ err }}</p>
        <button class="btn primary full" :disabled="busy || !email">{{ busy ? 'Enviando…' : 'Enviar link' }}</button>
      </form>
    </template>

    <div v-else class="done">
      <div class="icon">✉</div>
      <h1>Verifique seu e-mail</h1>
      <p class="muted">Se <strong>{{ email }}</strong> estiver cadastrado, você receberá em instantes as instruções para redefinir a senha. O link vale por 60 minutos.</p>
      <p class="muted small">Não chegou? Confira a caixa de spam ou tente novamente em alguns minutos.</p>
    </div>

    <RouterLink to="/login" class="back">← Voltar para o login</RouterLink>
  </AuthShell>
</template>

<style scoped>
h1 { font-size: 28px; }
.sub { margin: 6px 0 24px; line-height: 1.5; }
.full { width: 100%; padding: 13px; font-size: 16px; }
.back { display: inline-block; margin-top: 22px; color: var(--muted); font-size: 14px; }
.back:hover { color: var(--neon); }
.done { text-align: center; padding: 8px 0; }
.done h1 { margin: 12px 0 10px; }
.done p { line-height: 1.6; }
.small { font-size: 13px; margin-top: 12px; }
.icon { width: 60px; height: 60px; margin: 0 auto; border-radius: 50%; display: grid; place-items: center; font-size: 26px; background: var(--neon-dim); color: var(--neon); border: 1px solid rgba(180, 255, 31, .4); }
</style>
