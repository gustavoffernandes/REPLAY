<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import { api, SPORTS, money, hh, fmtDate } from '../api'

const route = useRoute()
const o = ref(null)
const err = ref('')
const qr = ref('')
const copied = ref(false)
const busy = ref(false)
const now = ref(Date.now())
let poll, tick

async function load() {
  try {
    o.value = await api.publicOrder(route.params.code)
    err.value = ''
  } catch (e) {
    err.value = e.message
  }
}

const awaiting = computed(() => o.value?.status === 'awaiting_payment')
const expiresAt = computed(() => (o.value?.expires_at ? Date.parse(o.value.expires_at) : 0))
const left = computed(() => Math.max(0, Math.floor((expiresAt.value - now.value) / 1000)))
const clock = computed(() => `${String(Math.floor(left.value / 60)).padStart(2, '0')}:${String(left.value % 60).padStart(2, '0')}`)

// QR do Pix gerado no navegador a partir do código "copia e cola"
watch(() => o.value?.pix_code, async (code) => {
  if (!code) { qr.value = ''; return }
  const QRCode = (await import('qrcode')).default
  qr.value = await QRCode.toDataURL(code, { width: 260, margin: 1, color: { dark: '#000000', light: '#ffffff' } })
})
watch(left, (s) => { if (awaiting.value && s === 0) load() }) // prazo acabou: confirma com o servidor

onMounted(async () => {
  await load()
  tick = setInterval(() => (now.value = Date.now()), 1000)
  poll = setInterval(() => { if (awaiting.value) load() }, 4000) // detecta o pagamento sozinho
})
onBeforeUnmount(() => { clearInterval(poll); clearInterval(tick) })

async function copy() {
  try {
    await navigator.clipboard.writeText(o.value.pix_code)
  } catch {
    const t = document.createElement('textarea')
    t.value = o.value.pix_code
    document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove()
  }
  copied.value = true
  setTimeout(() => (copied.value = false), 2000)
}

async function simulate() {
  busy.value = true
  try { o.value = await api.publicSimulatePayment(route.params.code) } catch (e) { err.value = e.message } finally { busy.value = false }
}
</script>

<template>
  <div class="page">
    <header class="top"><div class="logo"><span class="dot"></span>SET<b>POINT</b></div></header>

    <main class="wrap">
      <div v-if="err && !o" class="card center">
        <h1>Reserva não encontrada</h1>
        <p class="muted">Confira o código ou faça uma nova reserva.</p>
      </div>

      <template v-else-if="o">
        <!-- Aguardando pagamento -->
        <section v-if="awaiting" class="card">
          <div class="state"><span class="pulse"></span> Aguardando pagamento do sinal</div>
          <h1>Pague {{ money(o.deposit) }} via Pix</h1>
          <p class="muted">Seu horário está guardado por <strong class="clock mono">{{ clock }}</strong>. A confirmação é automática após o pagamento.</p>

          <div class="pixbox">
            <img v-if="qr" :src="qr" alt="QR Code Pix" class="qr" />
            <div v-else class="qr ph muted">Gerando QR…</div>
            <div class="code mono">{{ o.pix_code }}</div>
            <button class="btn primary" @click="copy">{{ copied ? 'Código copiado!' : 'Copiar código Pix' }}</button>
          </div>

          <ol class="steps muted">
            <li>Abra o app do seu banco e escolha pagar com Pix.</li>
            <li>Escaneie o QR Code ou use o "Pix copia e cola".</li>
            <li>Pronto! Esta página confirma sozinha.</li>
          </ol>

          <div v-if="o.simulation" class="sim">
            <strong>Ambiente de teste</strong>
            <span class="muted">O Pix acima é simulado. Use o botão para testar a confirmação.</span>
            <button class="btn" :disabled="busy" @click="simulate">{{ busy ? '…' : 'Simular pagamento' }}</button>
          </div>
        </section>

        <!-- Confirmada -->
        <section v-else-if="['confirmed', 'completed'].includes(o.status)" class="card ok">
          <div class="check">✓</div>
          <h1>Reserva confirmada!</h1>
          <p class="muted">Guarde o código abaixo. Apresente-o no balcão ao chegar.</p>
          <div class="codebig mono">{{ o.code }}</div>
        </section>

        <!-- Expirada / cancelada -->
        <section v-else class="card center">
          <h1>{{ o.status === 'expired' ? 'O prazo para pagar o sinal acabou' : 'Reserva cancelada' }}</h1>
          <p class="muted">O horário foi liberado. Você pode escolher outro e tentar novamente.</p>
          <RouterLink :to="`/reservar/${o.company.slug}/${o.branch.slug}`" class="btn primary again">Fazer nova reserva</RouterLink>
        </section>

        <!-- Detalhes -->
        <section v-if="awaiting || ['confirmed', 'completed'].includes(o.status)" class="card">
          <dl>
            <div><dt>Local</dt><dd>{{ o.company.name }} · {{ o.branch.name }}<small v-if="o.branch.address" class="muted"><br />{{ o.branch.address }}{{ o.branch.city ? ' — ' + o.branch.city : '' }}</small></dd></div>
            <div><dt>Quadra</dt><dd>{{ o.court }} · {{ SPORTS[o.sport] }}</dd></div>
            <div><dt>Data</dt><dd class="cap">{{ fmtDate(o.date, { weekday: 'long', day: 'numeric', month: 'long' }) }}</dd></div>
            <div><dt>Horário</dt><dd class="mono">{{ hh(o.start_hour) }} – {{ hh(o.end_hour) }}</dd></div>
            <div><dt>Nome</dt><dd>{{ o.customer_name }}</dd></div>
            <div><dt>Total</dt><dd class="mono">{{ money(o.total) }}</dd></div>
            <div v-if="o.paid > 0"><dt>Sinal pago</dt><dd class="mono ok-t">{{ money(o.paid) }}</dd></div>
            <div><dt>{{ o.paid > 0 ? 'Restante no local' : 'A pagar' }}</dt><dd class="mono">{{ money(o.remaining) }}</dd></div>
          </dl>
          <p class="muted ref">Código da reserva: <strong class="mono">{{ o.code }}</strong></p>
        </section>
      </template>
      <p v-else class="muted center">Carregando…</p>
    </main>
  </div>
</template>

<style scoped>
.page { min-height: 100vh; background: radial-gradient(900px 480px at 50% -10%, rgba(180, 255, 31, 0.07), transparent 60%); padding-bottom: 60px; }
.top { max-width: 560px; margin: 0 auto; padding: 20px 16px 0; }
.logo { font-weight: 500; font-size: 18px; letter-spacing: .14em; display: flex; align-items: center; gap: 10px; }
.logo b { color: var(--neon); font-weight: 700; }
.dot { width: 10px; height: 10px; border-radius: 50%; background: var(--neon); box-shadow: 0 0 14px var(--neon); }
.wrap { max-width: 560px; margin: 0 auto; padding: 24px 16px 0; display: flex; flex-direction: column; gap: 16px; }
.center { text-align: center; padding: 36px 20px; }
.center p { margin: 10px 0 20px; }
h1 { font-size: 24px; margin: 6px 0 8px; }
.state { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; color: var(--warn); text-transform: uppercase; letter-spacing: .06em; }
.pulse { width: 8px; height: 8px; border-radius: 50%; background: var(--warn); animation: pulse 1.4s infinite; }
@keyframes pulse { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: .35; transform: scale(.7); } }
.clock { color: var(--neon); font-size: 18px; }
.pixbox { margin: 20px 0; padding: 18px; background: var(--bg); border: 1px solid var(--border); border-radius: 14px; display: flex; flex-direction: column; align-items: center; gap: 14px; }
.qr { width: 220px; height: 220px; border-radius: 10px; background: #fff; padding: 6px; }
.qr.ph { display: grid; place-items: center; background: var(--surface-2); }
.code { width: 100%; font-size: 11px; color: var(--muted); word-break: break-all; max-height: 64px; overflow: hidden; text-align: center; opacity: .8; }
.steps { padding-left: 20px; display: flex; flex-direction: column; gap: 6px; font-size: 14px; }
.sim { margin-top: 18px; padding: 14px; border: 1px dashed var(--warn); border-radius: 12px; display: flex; flex-direction: column; gap: 8px; align-items: flex-start; font-size: 14px; }
.sim strong { color: var(--warn); }
.ok { text-align: center; border-color: rgba(180, 255, 31, .4); }
.check { width: 56px; height: 56px; border-radius: 50%; background: var(--neon); color: #000; font-size: 28px; font-weight: 700; display: grid; place-items: center; margin: 0 auto 10px; box-shadow: 0 0 30px rgba(180, 255, 31, .4); }
.codebig { display: inline-block; margin-top: 16px; font-size: 28px; letter-spacing: .2em; padding: 10px 20px; border: 1px dashed var(--neon); border-radius: 12px; color: var(--neon); }
dl { display: flex; flex-direction: column; }
dl > div { display: flex; justify-content: space-between; gap: 16px; padding: 11px 0; border-bottom: 1px solid var(--border); }
dl > div:last-child { border-bottom: none; }
dt { color: var(--muted); font-size: 13px; }
dd { text-align: right; }
dd small { font-size: 12px; }
.ok-t { color: var(--neon); }
.ref { margin-top: 14px; font-size: 13px; text-align: center; }
.again { display: inline-block; }
</style>
