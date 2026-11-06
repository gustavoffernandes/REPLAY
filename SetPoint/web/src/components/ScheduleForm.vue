<script setup>
import { ref, computed } from 'vue'
import { hh, money, SPORTS, WEEKDAYS, fmtDate } from '../api'

// action(payload) => Promise<{ created, skipped }>; fixedSport esconde o seletor de esporte (turmas)
const props = defineProps({ courts: Array, action: Function, fixedSport: String })
const emit = defineEmits(['done'])

const f = ref({ court_id: props.courts?.[0]?.id ?? '', sport: props.fixedSport || 'volei', weekday: 1, start_hour: 18, duration: 1 })
const busy = ref(false)
const err = ref('')
const info = ref(null)

const court = computed(() => props.courts.find((c) => c.id === f.value.court_id))
const hoursRange = computed(() => {
  const c = court.value
  return c ? Array.from({ length: c.close_hour - c.open_hour }, (_, i) => c.open_hour + i) : []
})
const maxDur = computed(() => (court.value ? Math.min(4, court.value.close_hour - f.value.start_hour) : 1))

function onCourtChange() {
  const c = court.value
  if (c && (f.value.start_hour < c.open_hour || f.value.start_hour >= c.close_hour)) f.value.start_hour = c.open_hour
}

async function submit() {
  err.value = ''
  info.value = null
  busy.value = true
  try {
    const d = Math.min(f.value.duration, maxDur.value)
    const r = await props.action({
      court_id: f.value.court_id, sport: f.value.sport, weekday: f.value.weekday,
      start_hour: f.value.start_hour, end_hour: f.value.start_hour + d,
    })
    info.value = r
    emit('done')
  } catch (e) {
    err.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <form class="sf" @submit.prevent="submit">
    <div class="row">
      <div class="field"><label>Quadra</label>
        <select class="input" v-model.number="f.court_id" @change="onCourtChange">
          <option v-for="c in courts.filter((c) => c.active)" :key="c.id" :value="c.id">{{ c.name }} · {{ money(c.price_per_hour) }}/h</option>
        </select>
      </div>
      <div v-if="!fixedSport" class="field"><label>Esporte</label>
        <select class="input" v-model="f.sport"><option v-for="(l, k) in SPORTS" :key="k" :value="k">{{ l }}</option></select>
      </div>
    </div>
    <div class="row">
      <div class="field"><label>Dia da semana</label>
        <select class="input" v-model.number="f.weekday"><option v-for="(d, i) in WEEKDAYS" :key="i" :value="i">{{ d }}</option></select>
      </div>
      <div class="field"><label>Início</label>
        <select class="input" v-model.number="f.start_hour"><option v-for="h in hoursRange" :key="h" :value="h">{{ hh(h) }}</option></select>
      </div>
      <div class="field"><label>Duração</label>
        <select class="input" :value="Math.min(f.duration, maxDur)" @change="f.duration = Number($event.target.value)">
          <option v-for="n in maxDur" :key="n" :value="n">{{ n }}h</option>
        </select>
      </div>
    </div>
    <p v-if="err" class="error">{{ err }}</p>
    <p v-if="info" class="ok">
      {{ info.created }} reservas geradas nas próximas 12 semanas.
      <span v-if="info.skipped.length" class="warn">
        {{ info.skipped.length }} data(s) em conflito foram puladas: {{ info.skipped.slice(0, 4).map((d) => fmtDate(d)).join(', ') }}{{ info.skipped.length > 4 ? '…' : '' }}.
      </span>
    </p>
    <button class="btn" :disabled="busy || !court">{{ busy ? 'Gerando…' : '+ Adicionar horário' }}</button>
  </form>
</template>

<style scoped>
.sf { background: var(--bg); border: 1px dashed var(--border); border-radius: 12px; padding: 14px; }
.sf .field { margin-bottom: 10px; }
.ok { color: var(--neon); font-size: 14px; margin-bottom: 10px; }
.warn { color: var(--warn); }
</style>
