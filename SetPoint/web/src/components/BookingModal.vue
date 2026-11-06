<script setup>
import { ref, computed } from 'vue'
import { api, hh, money, fmtDate, SPORTS } from '../api'

const props = defineProps({ court: Object, hour: Number, date: String })
const emit = defineEmits(['close', 'saved'])

const sport = ref('volei')
const duration = ref(1)
const name = ref('')
const phone = ref('')
const notes = ref('')
const status = ref('confirmed')
const busy = ref(false)
const err = ref('')

const maxDur = computed(() => Math.min(4, props.court.close_hour - props.hour))
const end = computed(() => props.hour + duration.value)
const total = computed(() => Number(props.court.price_per_hour) * duration.value)

async function submit() {
  err.value = ''
  busy.value = true
  try {
    await api.createBooking({
      court_id: props.court.id, sport: sport.value, date: props.date, start_hour: props.hour, end_hour: end.value,
      customer_name: name.value, customer_phone: phone.value, notes: notes.value, status: status.value,
    })
    emit('saved')
  } catch (e) {
    err.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="modal-back" @mousedown.self="emit('close')">
    <form class="modal" @submit.prevent="submit">
      <h2>Nova reserva</h2>
      <p class="muted info">{{ court.name }}<br /><span class="cap">{{ fmtDate(date, { weekday: 'long', day: 'numeric', month: 'long' }) }}</span></p>

      <div class="field">
        <label>Esporte</label>
        <div class="sports">
          <button v-for="(l, k) in SPORTS" :key="k" type="button" class="chip" :class="{ on: sport === k }" @click="sport = k">{{ l }}</button>
        </div>
      </div>
      <div class="row">
        <div class="field"><label>Início</label><input class="input mono" :value="hh(hour)" disabled /></div>
        <div class="field">
          <label>Duração</label>
          <select class="input" v-model.number="duration">
            <option v-for="n in maxDur" :key="n" :value="n">{{ n }}h (até {{ hh(hour + n) }})</option>
          </select>
        </div>
      </div>
      <div class="field"><label>Cliente</label><input class="input" v-model="name" required placeholder="Nome completo" autofocus /></div>
      <div class="row">
        <div class="field"><label>Telefone</label><input class="input" v-model="phone" required placeholder="(11) 90000-0000" /></div>
        <div class="field">
          <label>Status</label>
          <select class="input" v-model="status"><option value="confirmed">Confirmada</option><option value="pending">Pendente</option></select>
        </div>
      </div>
      <div class="field"><label>Observações</label><input class="input" v-model="notes" placeholder="Opcional" /></div>

      <p v-if="err" class="error">{{ err }}</p>
      <div class="foot">
        <div><span class="muted">Total</span><br /><strong class="mono tot">{{ money(total) }}</strong></div>
        <div class="acts">
          <button type="button" class="btn" @click="emit('close')">Cancelar</button>
          <button class="btn primary" :disabled="busy">{{ busy ? 'Salvando…' : 'Reservar' }}</button>
        </div>
      </div>
    </form>
  </div>
</template>

<style scoped>
.info { margin-bottom: 20px; line-height: 1.5; }
.foot { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 8px; }
.sports { display: flex; gap: 8px; flex-wrap: wrap; }
.chip { background: var(--bg); border: 1px solid var(--border); border-radius: 99px; padding: 7px 14px; cursor: pointer; transition: .12s; }
.chip:hover { border-color: #3a3a3a; }
.chip.on { background: var(--neon-dim); border-color: var(--neon); color: var(--neon); }
.tot { font-size: 22px; color: var(--neon); }
.acts { display: flex; gap: 8px; }
</style>
