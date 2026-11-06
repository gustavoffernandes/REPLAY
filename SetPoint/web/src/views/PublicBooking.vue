<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, SPORTS, money, hh, toISO, fmtDate } from '../api'

const route = useRoute()
const router = useRouter()

const info = ref(null) // { company, branches }
const notFound = ref(false)
const branch = ref(null)
const date = ref(toISO(new Date()))
const sport = ref('volei')
const avail = ref(null)
const loadingAvail = ref(false)
const pick = ref(null) // { court, hour }
const duration = ref(1)
const form = ref({ name: '', phone: '', email: '' })
const busy = ref(false)
const err = ref('')

const MAX_HOURS = 3
const days = Array.from({ length: 14 }, (_, i) => {
  const d = new Date()
  d.setDate(d.getDate() + i)
  return { iso: toISO(d), wd: d.toLocaleDateString('pt-BR', { weekday: 'short' }).replace('.', ''), n: d.getDate(), m: d.toLocaleDateString('pt-BR', { month: 'short' }).replace('.', '') }
})
const minDate = toISO(new Date())
const maxDate = (() => { const d = new Date(); d.setDate(d.getDate() + 60); return toISO(d) })()

onMounted(async () => {
  try {
    info.value = await api.publicCompany(route.params.company)
    const list = info.value.branches
    branch.value = list.find((b) => b.slug === route.params.branch) ?? (list.length === 1 ? list[0] : null)
    if (!list.length) notFound.value = true
  } catch {
    notFound.value = true
  }
})

async function loadAvail() {
  if (!branch.value) return
  loadingAvail.value = true
  err.value = ''
  try {
    avail.value = await api.publicAvailability(branch.value.id, date.value)
  } catch (e) {
    err.value = e.message
    avail.value = null
  } finally {
    loadingAvail.value = false
  }
}
watch([branch, date], () => { pick.value = null; loadAvail() })
watch(branch, (b) => { if (b && route.params.branch !== b.slug) router.replace(`/reservar/${route.params.company}/${b.slug}`) })

function closeSheet() {
  if (busy.value) return
  pick.value = null
  err.value = ''
}
const onKey = (e) => e.key === 'Escape' && closeSheet()
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => { window.removeEventListener('keydown', onKey); document.body.style.overflow = '' })
// Trava a rolagem da página enquanto o modal está aberto
watch(pick, (p) => { document.body.style.overflow = p ? 'hidden' : '' })

function choose(court, hour) {
  pick.value = { court, hour }
  duration.value = 1
  err.value = ''
}

// Horas consecutivas livres a partir do início escolhido (máx. 3)
const maxDur = computed(() => {
  if (!pick.value) return 1
  const slots = pick.value.court.slots
  let n = 0
  for (let h = pick.value.hour; n < MAX_HOURS; h++) {
    const s = slots.find((x) => x.hour === h)
    if (!s || !s.free) break
    n++
  }
  return Math.max(1, n)
})
watch(maxDur, (m) => { if (duration.value > m) duration.value = m })

const total = computed(() => (pick.value ? pick.value.court.price_per_hour * duration.value : 0))
const percent = computed(() => info.value?.company.deposit_percent ?? 50)
const deposit = computed(() => Math.round(total.value * percent.value) / 100)
const endHour = computed(() => (pick.value ? pick.value.hour + duration.value : 0))

function maskPhone(e) {
  const d = e.target.value.replace(/\D/g, '').slice(0, 11)
  let out = d
  if (d.length > 10) out = `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`
  else if (d.length > 6) out = `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`
  else if (d.length > 2) out = `(${d.slice(0, 2)}) ${d.slice(2)}`
  form.value.phone = out
  e.target.value = out
}

async function submit() {
  err.value = ''
  busy.value = true
  try {
    const r = await api.publicBook(branch.value.id, {
      court_id: pick.value.court.id, sport: sport.value, date: date.value,
      start_hour: pick.value.hour, end_hour: endHour.value,
      customer_name: form.value.name.trim(), customer_phone: form.value.phone, customer_email: form.value.email.trim() || null,
    })
    router.push(`/reserva/${r.code}`)
  } catch (e) {
    err.value = e.message
    await loadAvail() // o horário pode ter sido ocupado por outra pessoa
    // Se o horário foi ocupado por outra pessoa, fecha o modal e mantém o aviso na lista de horários
    if (pick.value && !avail.value?.courts.find((c) => c.id === pick.value.court.id)?.slots.find((s) => s.hour === pick.value.hour)?.free) pick.value = null
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="page">
    <header class="top">
      <div class="logo"><span class="dot"></span>SET<b>POINT</b></div>
      <p v-if="info" class="muted who">{{ info.company.name }}</p>
    </header>

    <main class="wrap">
      <div v-if="notFound" class="card center">
        <h1>Página não encontrada</h1>
        <p class="muted">Este endereço de agendamento não existe ou está indisponível no momento.</p>
      </div>

      <template v-else-if="info">
        <div class="hero">
          <h1>Reserve sua quadra</h1>
          <p class="muted">Escolha o horário e garanta com um sinal de <strong>{{ percent }}%</strong> via Pix. O restante você paga no local.</p>
        </div>

        <!-- Filial -->
        <section v-if="info.branches.length > 1" class="card">
          <h2><span class="n">1</span> Unidade</h2>
          <div class="chips">
            <button v-for="b in info.branches" :key="b.id" class="chip big" :class="{ on: branch?.id === b.id }" @click="branch = b">
              {{ b.name }}<small v-if="b.city">{{ b.city }}</small>
            </button>
          </div>
        </section>

        <template v-if="branch">
          <!-- Esporte e data -->
          <section class="card">
            <h2><span class="n">{{ info.branches.length > 1 ? 2 : 1 }}</span> Esporte e data</h2>
            <div class="chips">
              <button v-for="(l, k) in SPORTS" :key="k" class="chip" :class="{ on: sport === k }" @click="sport = k">{{ l }}</button>
            </div>
            <div class="days">
              <button v-for="d in days" :key="d.iso" class="day" :class="{ on: date === d.iso }" @click="date = d.iso">
                <small>{{ d.wd }}</small><strong>{{ d.n }}</strong><small>{{ d.m }}</small>
              </button>
            </div>
            <label class="other muted">Outra data <input type="date" class="input" v-model="date" :min="minDate" :max="maxDate" /></label>
          </section>

          <!-- Horários -->
          <section class="card">
            <h2><span class="n">{{ info.branches.length > 1 ? 3 : 2 }}</span> Escolha o horário</h2>
            <p class="muted cap dlabel">{{ fmtDate(date, { weekday: 'long', day: 'numeric', month: 'long' }) }}</p>
            <p v-if="loadingAvail" class="muted">Carregando horários…</p>
            <p v-else-if="err && !pick" class="error">{{ err }}</p>
            <template v-if="avail && !loadingAvail">
              <div v-for="c in avail.courts" :key="c.id" class="court">
                <div class="chead"><strong>{{ c.name }}</strong><span class="mono muted">{{ money(c.price_per_hour) }}/h</span></div>
                <div class="slots">
                  <button v-for="s in c.slots" :key="s.hour" class="slot" :class="{ on: pick?.court.id === c.id && pick.hour <= s.hour && s.hour < pick.hour + duration }"
                          :disabled="!s.free" @click="choose(c, s.hour)">{{ hh(s.hour) }}</button>
                </div>
              </div>
              <p v-if="!avail.courts.length" class="muted">Nenhuma quadra disponível nesta unidade.</p>
            </template>
          </section>
        </template>
      </template>
      <p v-else class="muted center">Carregando…</p>
    </main>

    <!-- Modal de confirmação (painel inferior no celular) -->
    <div v-if="pick" class="sheet-back" @mousedown.self="closeSheet">
      <form class="sheet" role="dialog" aria-modal="true" aria-label="Confirmar reserva" @submit.prevent="submit">
        <div class="sheet-head">
          <div>
            <h3>Confirmar reserva</h3>
            <p class="muted sub">{{ pick.court.name }} · <span class="cap">{{ fmtDate(date, { weekday: 'long', day: 'numeric', month: 'long' }) }}</span></p>
          </div>
          <button type="button" class="x" aria-label="Fechar" @click="closeSheet">✕</button>
        </div>

        <div class="summary">
          <div class="dur">
            <span class="muted">Duração</span>
            <div class="chips">
              <button v-for="n in maxDur" :key="n" type="button" class="chip" :class="{ on: duration === n }" @click="duration = n">{{ n }}h</button>
            </div>
          </div>
          <div><span class="muted">Horário</span><strong class="mono">{{ hh(pick.hour) }} – {{ hh(endHour) }}</strong></div>
          <div><span class="muted">Esporte</span><strong>{{ SPORTS[sport] }}</strong></div>
        </div>

        <div class="field"><label>Nome *</label><input class="input" v-model="form.name" required minlength="2" maxlength="120" autocomplete="name" placeholder="Seu nome" /></div>
        <div class="field"><label>Telefone / WhatsApp *</label><input class="input" :value="form.phone" required inputmode="tel" autocomplete="tel" placeholder="(11) 90000-0000" @input="maskPhone" /></div>
        <div class="field"><label>E-mail <span class="opt">(opcional)</span></label><input class="input" type="email" v-model="form.email" autocomplete="email" placeholder="voce@email.com" /></div>

        <div class="pay">
          <div class="row2"><span class="muted">Total da reserva</span><strong class="mono">{{ money(total) }}</strong></div>
          <div class="row2 hl"><span>Sinal agora ({{ percent }}%)</span><strong class="mono">{{ money(deposit) }}</strong></div>
          <div class="row2"><span class="muted">Restante no local</span><strong class="mono">{{ money(total - deposit) }}</strong></div>
        </div>

        <p v-if="err" class="error">{{ err }}</p>
        <button class="btn primary go" :disabled="busy">{{ busy ? 'Reservando…' : deposit > 0 ? `Pagar sinal de ${money(deposit)}` : 'Confirmar reserva' }}</button>
        <p class="muted note">Seu horário fica guardado por alguns minutos até o pagamento do sinal. Se não pagar a tempo, ele volta a ficar livre.</p>
      </form>
    </div>
  </div>
</template>

<style scoped>
.page { min-height: 100vh; background: radial-gradient(900px 480px at 50% -10%, rgba(180, 255, 31, 0.07), transparent 60%); padding-bottom: 60px; }
.top { max-width: 720px; margin: 0 auto; padding: 20px 16px 0; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
.logo { font-weight: 500; font-size: 18px; letter-spacing: .14em; display: flex; align-items: center; gap: 10px; }
.logo b { color: var(--neon); font-weight: 700; }
.dot { width: 10px; height: 10px; border-radius: 50%; background: var(--neon); box-shadow: 0 0 14px var(--neon); }
.who { font-size: 14px; text-align: right; }
.wrap { max-width: 720px; margin: 0 auto; padding: 24px 16px 0; display: flex; flex-direction: column; gap: 16px; }
.hero h1 { font-size: 30px; }
.hero p { margin-top: 8px; line-height: 1.5; }
.hero strong { color: var(--neon); }
.center { text-align: center; padding: 40px 20px; }
h2 { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; font-size: 16px; }
.n { width: 24px; height: 24px; border-radius: 50%; background: var(--neon-dim); color: var(--neon); display: grid; place-items: center; font-size: 13px; font-weight: 700; }
.chips { display: flex; gap: 8px; flex-wrap: wrap; }
.chip { background: var(--bg); border: 1px solid var(--border); border-radius: 99px; padding: 8px 16px; cursor: pointer; transition: .12s; }
.chip:hover { border-color: #3a3a3a; }
.chip.on { background: var(--neon-dim); border-color: var(--neon); color: var(--neon); }
.chip.big { border-radius: 14px; display: flex; flex-direction: column; text-align: left; padding: 10px 16px; }
.chip.big small { color: var(--muted); font-size: 12px; }
.days { display: flex; gap: 8px; overflow-x: auto; padding: 14px 0 6px; scrollbar-width: thin; }
.day { flex: none; width: 58px; background: var(--bg); border: 1px solid var(--border); border-radius: 12px; padding: 8px 0; display: flex; flex-direction: column; align-items: center; gap: 2px; cursor: pointer; text-transform: capitalize; transition: .12s; }
.day small { font-size: 11px; color: var(--muted); }
.day strong { font-size: 18px; }
.day:hover { border-color: #3a3a3a; }
.day.on { background: var(--neon); border-color: var(--neon); }
.day.on small, .day.on strong { color: #000; }
.other { display: flex; align-items: center; gap: 10px; font-size: 13px; margin-top: 10px; }
.other .input { width: auto; padding: 6px 10px; }
.dlabel { margin: -8px 0 14px; }
.court { padding: 14px 0; border-top: 1px solid var(--border); }
.court:first-of-type { border-top: none; padding-top: 0; }
.chead { display: flex; justify-content: space-between; margin-bottom: 10px; }
.slots { display: grid; grid-template-columns: repeat(auto-fill, minmax(72px, 1fr)); gap: 6px; }
.slot { background: var(--bg); border: 1px solid var(--border); border-radius: 9px; padding: 9px 0; font-family: var(--mono); font-size: 13px; cursor: pointer; transition: .1s; }
.slot:hover:not(:disabled):not(.on) { border-color: var(--neon); color: var(--neon); }
/* Selecionado: número sempre preto; no hover fica levemente translúcido */
.slot.on { background: var(--neon); border-color: var(--neon); color: #000; font-weight: 600; }
.slot.on:hover { opacity: .85; }
.slot:disabled { color: #4a4a47; background: #0d0d0d; border-color: #181818; text-decoration: line-through; cursor: not-allowed; }
.summary { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 12px; margin-bottom: 18px; padding: 14px; background: var(--bg); border: 1px solid var(--border); border-radius: 12px; align-items: start; }
.summary > div { display: flex; flex-direction: column; gap: 4px; }
.summary .dur { grid-column: span 2; }
.summary span { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; }
.opt { text-transform: none; letter-spacing: 0; }
.pay { margin: 6px 0 16px; padding: 14px; border: 1px solid var(--border); border-radius: 12px; display: flex; flex-direction: column; gap: 8px; }
.row2 { display: flex; justify-content: space-between; align-items: center; }
.row2.hl { padding: 10px 12px; margin: 2px -4px; background: var(--neon-dim); border-radius: 9px; color: var(--neon); }
.row2.hl strong { font-size: 20px; }
.go { width: 100%; padding: 14px; font-size: 16px; }
.note { font-size: 12px; margin-top: 12px; text-align: center; line-height: 1.5; }

/* ── Modal / painel inferior ── */
.sheet-back { position: fixed; inset: 0; z-index: 50; background: rgba(0, 0, 0, .74); backdrop-filter: blur(4px); display: grid; place-items: center; padding: 16px; overflow-y: auto; animation: fade .15s ease; }
.sheet { width: 100%; max-width: 480px; max-height: calc(100dvh - 32px); overflow-y: auto; background: var(--surface); border: 1px solid var(--border); border-radius: 18px; padding: 22px; box-shadow: 0 24px 60px rgba(0, 0, 0, .6); animation: pop .18s ease; overscroll-behavior: contain; }
.sheet-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 16px; }
.sheet-head h3 { font-size: 20px; }
.sheet-head .sub { margin-top: 4px; font-size: 14px; }
.x { flex: none; width: 36px; height: 36px; border-radius: 10px; border: 1px solid var(--border); background: var(--surface-2); color: var(--muted); font-size: 15px; cursor: pointer; display: grid; place-items: center; }
.x:hover { color: var(--text); border-color: #3a3a3a; }
@keyframes fade { from { opacity: 0; } }
@keyframes pop { from { opacity: 0; transform: translateY(10px) scale(.98); } }
@keyframes up { from { transform: translateY(100%); } }

@media (max-width: 600px) {
  .hero h1 { font-size: 24px; }
  .slots { grid-template-columns: repeat(auto-fill, minmax(64px, 1fr)); }
  /* no celular o modal vira um painel que sobe da base da tela */
  .sheet-back { place-items: end center; padding: 0; }
  .sheet { max-width: none; max-height: 92dvh; border-radius: 20px 20px 0 0; border-bottom: none; padding: 18px 16px calc(18px + env(safe-area-inset-bottom)); animation: up .24s ease; }
  .summary { grid-template-columns: 1fr 1fr; }
  .input { font-size: 16px; } /* evita o zoom automático do iOS ao focar */
}
</style>
