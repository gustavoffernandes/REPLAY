<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { api, toISO, fmtDate, hh, money, SPORTS } from '../api'
import BookingModal from '../components/BookingModal.vue'
import { showBranch, can } from '../auth'
import BookingDetailModal from '../components/BookingDetailModal.vue'

const date = ref(toISO(new Date()))
const data = ref(null)
const err = ref('')
const modal = ref(null)
const detailId = ref(null)

async function load() {
  err.value = ''
  try { data.value = await api.availability(date.value) } catch (e) { err.value = e.message }
}
onMounted(load)
watch(date, load)

function shift(n) {
  const d = new Date(date.value + 'T12:00:00')
  d.setDate(d.getDate() + n)
  date.value = toISO(d)
}

const hours = computed(() => {
  if (!data.value?.courts.length) return []
  const min = Math.min(...data.value.courts.map((c) => c.open_hour))
  const max = Math.max(...data.value.courts.map((c) => c.close_hour))
  return Array.from({ length: max - min }, (_, i) => min + i)
})
const slot = (court, h) => court.slots.find((s) => s.hour === h)

function pick(court, s) {
  if (!s || s.booking || s.past || !can('bookings.create')) return
  modal.value = { court, hour: s.hour }
}
function onSaved() { modal.value = null; load() }
function onChanged() { detailId.value = null; load() }
</script>

<template>
  <div class="page-head">
    <div>
      <p class="muted">Clique em um horário livre para reservar</p>
      <h1>Agenda</h1>
    </div>
    <div class="controls">
      <div class="datenav">
        <button class="btn" @click="shift(-1)">‹</button>
        <input type="date" v-model="date" class="input" />
        <button class="btn" @click="shift(1)">›</button>
        <button class="btn" @click="date = toISO(new Date())">Hoje</button>
      </div>
    </div>
  </div>
  <p v-if="err" class="error">{{ err }}</p>

  <div v-if="data" class="card sched">
    <p class="muted dtitle">{{ fmtDate(date, { weekday: 'long', day: 'numeric', month: 'long' }) }}</p>
    <div class="scroll">
      <table class="gridt">
        <thead>
          <tr>
            <th class="h"></th>
            <th v-for="c in data.courts" :key="c.id">
              {{ c.name }}<small>{{ showBranch ? c.branch.name + ' · ' : '' }}{{ money(c.price_per_hour) }}/h</small>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="h in hours" :key="h">
            <td class="h mono">{{ hh(h) }}</td>
            <td v-for="c in data.courts" :key="c.id" class="cell">
              <template v-if="slot(c, h)">
                <button v-if="slot(c, h).booking" class="s busy" :class="[slot(c, h).booking.status, { cls: slot(c, h).booking.is_class }]" @click="detailId = slot(c, h).booking.booking_id" :title="`${SPORTS[slot(c, h).booking.sport]} · ${slot(c, h).booking.customer}`">
                  <span class="sp">{{ SPORTS[slot(c, h).booking.sport] }}</span>
                  <span class="cu">{{ slot(c, h).booking.customer }}</span>
                </button>
                <button v-else class="s free" :disabled="slot(c, h).past || !can('bookings.create')" @click="pick(c, slot(c, h))">{{ slot(c, h).past ? '' : 'Livre' }}</button>
              </template>
              <div v-else class="s off"></div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-if="!data.courts.length" class="muted">Nenhuma quadra encontrada.</p>
  </div>

  <BookingDetailModal v-if="detailId" :id="detailId" @close="detailId = null" @changed="onChanged" />
  <BookingModal v-if="modal" :court="modal.court" :hour="modal.hour" :date="date" @close="modal = null" @saved="onSaved" />
</template>

<style scoped>
.controls { display: flex; gap: 12px; flex-wrap: wrap; }
.datenav { display: flex; gap: 6px; }
.datenav .input { width: auto; }
.sel { width: auto; }
.dtitle { display: block; margin-bottom: 14px; }
.dtitle::first-letter { text-transform: uppercase; }
.scroll { overflow-x: auto; }
.gridt { border-collapse: separate; border-spacing: 4px; min-width: 760px; }
.gridt th { border: none; text-align: center; text-transform: none; letter-spacing: 0; font-size: 13px; color: var(--text); padding: 4px 4px 10px; }
.gridt th small { display: block; color: var(--muted); font-weight: 400; font-size: 11px; margin-top: 2px; }
.gridt td { border: none; padding: 0; }
.h { width: 64px; color: var(--muted); font-size: 12px; text-align: right; padding-right: 8px !important; }
.s { width: 100%; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 12px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; padding: 0 6px; }
.free { background: transparent; border: 1px dashed #2f2f2f; color: var(--muted); cursor: pointer; transition: .12s; }
.free:hover:not(:disabled) { border-style: solid; border-color: var(--neon); color: var(--neon); background: var(--neon-dim); }
.free:disabled { background: #0e0e0e; border-color: #1a1a1a; cursor: default; }
.s.busy { border: none; cursor: pointer; font-family: inherit; transition: filter .12s, transform .1s; }
.s.busy:hover { filter: brightness(1.12); }
.s.busy:active { transform: translateY(1px); }
.busy.awaiting_payment { background: transparent; border: 1px dashed var(--warn); color: var(--warn); }
.busy.cls { background-image: repeating-linear-gradient(135deg, transparent 0 7px, rgba(0,0,0,.09) 7px 14px); }
.s.busy { flex-direction: column; gap: 1px; line-height: 1.15; }
.sp { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
.cu { font-size: 11px; font-weight: 400; opacity: .75; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
.busy { background: var(--neon); color: #000; font-weight: 600; }
.busy.pending { background: transparent; border: 1px solid var(--warn); color: var(--warn); }
.busy.completed { background: #2a2a2a; color: var(--muted); }
.off { background: #0c0c0c; }
</style>
