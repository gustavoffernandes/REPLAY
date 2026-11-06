<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { api, money, hh, fmtDate, SPORTS, STATUS } from '../api'
import { can } from '../auth'

const list = ref([])
const status = ref('')
const from = ref('')
const err = ref('')

async function load() {
  err.value = ''
  const p = {}
  if (status.value) p.status = status.value
  if (from.value) p.from = from.value
  try { list.value = await api.bookings(p) } catch (e) { err.value = e.message }
}
onMounted(load)
watch([status, from], load)

// Menu de ações (posição fixa para não ser cortado pelo scroll da tabela)
const menu = ref(null) // { booking, top, left }
const actionsFor = (b) => [
  can('bookings.confirm') && b.status === 'awaiting_payment' && { status: 'confirmed', label: 'Confirmar sinal' },
  can('bookings.confirm') && b.status === 'pending' && { status: 'confirmed', label: 'Confirmar' },
  can('bookings.confirm') && b.status === 'confirmed' && { status: 'completed', label: 'Concluir' },
  can('bookings.cancel') && ['awaiting_payment', 'pending', 'confirmed'].includes(b.status) && { status: 'cancelled', label: 'Cancelar', danger: true },
].filter(Boolean)

function toggleMenu(b, e) {
  if (menu.value?.booking.id === b.id) return (menu.value = null)
  const r = e.currentTarget.getBoundingClientRect()
  const h = actionsFor(b).length * 38 + 12
  const top = r.bottom + h > window.innerHeight ? r.top - h - 4 : r.bottom + 4
  menu.value = { booking: b, top, left: Math.max(8, r.right - 170) }
}
const close = () => (menu.value = null)
const onKey = (e) => e.key === 'Escape' && close()
onMounted(() => { window.addEventListener('click', close); window.addEventListener('scroll', close, true); window.addEventListener('resize', close); window.addEventListener('keydown', onKey) })
onBeforeUnmount(() => { window.removeEventListener('click', close); window.removeEventListener('scroll', close, true); window.removeEventListener('resize', close); window.removeEventListener('keydown', onKey) })

async function setStatus(b, s) {
  close()
  if (s === 'cancelled' && !confirm('Cancelar esta reserva?')) return
  try { await api.setStatus(b.id, s); await load() } catch (e) { err.value = e.message }
}
</script>

<template>
  <div class="page-head">
    <div><p class="muted">{{ list.length }} resultados</p><h1>Reservas</h1></div>
    <div class="filters">
      <select class="input" v-model="status">
        <option value="">Todos os status</option>
        <option v-for="(l, k) in STATUS" :key="k" :value="k">{{ l }}</option>
      </select>
      <input type="date" class="input" v-model="from" title="A partir de" />
      <RouterLink v-if="can('bookings.create')" to="/agenda" class="btn primary">+ Nova</RouterLink>
    </div>
  </div>
  <p v-if="err" class="error">{{ err }}</p>
  <div class="card" style="overflow-x:auto">
    <table>
      <thead><tr><th>Data</th><th>Horário</th><th>Quadra</th><th>Cliente</th><th>Valor</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <tr v-for="b in list" :key="b.id">
          <td class="mono">{{ fmtDate(b.booking_date) }}</td>
          <td class="mono">{{ hh(b.start_hour) }}–{{ hh(b.end_hour) }}</td>
          <td>{{ b.court_name }}<br /><small class="muted">{{ SPORTS[b.sport] }}</small></td>
          <td>{{ b.customer_name }} <span v-if="b.class_group_id" class="badge">Turma</span><span v-else-if="b.schedule_id" class="badge">Fixo</span><span v-else-if="b.source === 'online'" class="badge">Online</span><br /><small class="muted">{{ b.customer_phone }}</small></td>
          <td class="mono">{{ money(b.price) }}</td>
          <td><span class="badge" :class="b.status">{{ STATUS[b.status] }}</span></td>
          <td class="act-cell">
            <button v-if="actionsFor(b).length" class="burger" :class="{ open: menu?.booking.id === b.id }" aria-label="Ações da reserva" aria-haspopup="menu" @click.stop="toggleMenu(b, $event)">
              <svg viewBox="0 0 24 24" width="18" height="18"><path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none" /></svg>
            </button>
          </td>
        </tr>
        <tr v-if="!list.length"><td colspan="7" class="muted">Nenhuma reserva encontrada.</td></tr>
      </tbody>
    </table>
  </div>

  <div v-if="menu" class="menu" role="menu" :style="{ top: menu.top + 'px', left: menu.left + 'px' }" @click.stop>
    <button v-for="a in actionsFor(menu.booking)" :key="a.status" role="menuitem" class="item" :class="{ danger: a.danger }" @click="setStatus(menu.booking, a.status)">
      {{ a.label }}
    </button>
  </div>
</template>

<style scoped>
.filters { display: flex; gap: 10px; flex-wrap: wrap; }
.filters .input { width: auto; }
.act-cell { width: 56px; text-align: right; }
.burger { background: transparent; border: 1px solid transparent; border-radius: 9px; width: 34px; height: 34px; display: inline-grid; place-items: center; cursor: pointer; color: var(--muted); transition: .12s; }
.burger:hover, .burger.open { color: var(--neon); border-color: var(--border); background: var(--surface-2); }
.menu { position: fixed; z-index: 40; width: 170px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 12px; padding: 6px; box-shadow: 0 16px 40px rgba(0,0,0,.6); display: flex; flex-direction: column; gap: 2px; }
.item { background: transparent; border: none; text-align: left; padding: 9px 12px; border-radius: 8px; cursor: pointer; }
.item:hover { background: var(--neon-dim); color: var(--neon); }
.item.danger { color: var(--danger); }
.item.danger:hover { background: rgba(255,90,79,.12); color: var(--danger); }
</style>
