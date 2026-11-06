<script setup>
import { ref, computed, onMounted } from 'vue'
import { api, money, hh, fmtDate, SPORTS, STATUS } from '../api'
import { can } from '../auth'

const d = ref(null)
const err = ref('')
onMounted(async () => {
  try { d.value = await api.dashboard() } catch (e) { err.value = e.message }
})

const maxRev = computed(() => Math.max(1, ...d.value.daily.map((x) => Number(x.revenue))))
const maxPeak = computed(() => Math.max(1, ...d.value.peak_hours.map((x) => x.bookings)))
const sportTotal = computed(() => d.value.by_sport.reduce((s, x) => s + Number(x.revenue), 0) || 1)
const kpis = computed(() => {
  const k = d.value.kpis
  return [
    { label: 'Reservas hoje', value: k.bookings_today, sub: `${k.courts} quadras ativas` },
    { label: 'Ocupação hoje', value: k.occupancy_today + '%', sub: 'das horas disponíveis', bar: k.occupancy_today },
    { label: 'Receita hoje', value: money(k.revenue_today), sub: 'confirmadas + concluídas' },
    { label: 'Receita no mês', value: money(k.revenue_month), sub: `${k.pending} pendentes a confirmar` },
  ]
})
</script>

<template>
  <div class="page-head">
    <div>
      <p class="muted">{{ new Date().toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long' }) }}</p>
      <h1>Visão geral</h1>
    </div>
    <RouterLink v-if="can('bookings.create')" to="/agenda" class="btn primary">+ Nova reserva</RouterLink>
  </div>

  <p v-if="err" class="error">{{ err }}</p>
  <template v-if="d">
    <div class="kpis">
      <div v-for="k in kpis" :key="k.label" class="card kpi">
        <span class="muted lbl">{{ k.label }}</span>
        <strong class="mono val">{{ k.value }}</strong>
        <div v-if="k.bar !== undefined" class="meter"><i :style="{ width: k.bar + '%' }"></i></div>
        <span class="muted sub">{{ k.sub }}</span>
      </div>
    </div>

    <div class="grid">
      <section class="card span2">
        <h2>Receita · últimos 14 dias</h2>
        <div class="bars">
          <div v-for="x in d.daily" :key="x.date" class="bar" :title="`${fmtDate(x.date)} — ${money(x.revenue)} (${x.bookings} reservas)`">
            <i :style="{ height: (Number(x.revenue) / maxRev) * 100 + '%' }"></i>
            <span class="mono">{{ fmtDate(x.date, { day: '2-digit' }) }}</span>
          </div>
        </div>
      </section>

      <section class="card">
        <h2>Por esporte · mês</h2>
        <ul class="sports">
          <li v-for="s in d.by_sport" :key="s.sport">
            <div class="between"><span>{{ SPORTS[s.sport] }}</span><span class="mono">{{ money(s.revenue) }}</span></div>
            <div class="meter"><i :style="{ width: (Number(s.revenue) / sportTotal) * 100 + '%' }"></i></div>
            <small class="muted">{{ s.bookings }} reservas</small>
          </li>
          <li v-if="!d.by_sport.length" class="muted">Sem dados no mês.</li>
        </ul>
      </section>

      <section class="card">
        <h2>Horários de pico · 30 dias</h2>
        <div class="bars short">
          <div v-for="p in d.peak_hours" :key="p.hour" class="bar" :title="`${hh(p.hour)} — ${p.bookings} reservas`">
            <i :style="{ height: (p.bookings / maxPeak) * 100 + '%' }" :class="{ hot: p.bookings === maxPeak }"></i>
            <span class="mono">{{ p.hour }}h</span>
          </div>
        </div>
      </section>

      <section class="card span2">
        <div class="between"><h2>Próximas reservas</h2><RouterLink to="/reservas" class="muted">ver todas →</RouterLink></div>
        <table>
          <thead><tr><th>Quando</th><th>Quadra</th><th>Cliente</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="b in d.upcoming" :key="b.id">
              <td class="mono">{{ fmtDate(b.booking_date) }} · {{ hh(b.start_hour) }}–{{ hh(b.end_hour) }}</td>
              <td>{{ b.court_name }}</td>
              <td>{{ b.customer_name }}</td>
              <td><span class="badge" :class="b.status">{{ STATUS[b.status] }}</span></td>
            </tr>
            <tr v-if="!d.upcoming.length"><td colspan="4" class="muted">Nenhuma reserva futura.</td></tr>
          </tbody>
        </table>
      </section>
    </div>
  </template>
</template>

<style scoped>
.kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px; margin-bottom: 16px; }
.kpi { display: flex; flex-direction: column; gap: 8px; }
.lbl { font-size: 12px; text-transform: uppercase; letter-spacing: .08em; }
.val { font-size: 30px; font-weight: 600; letter-spacing: -0.02em; }
.sub { font-size: 12px; }
.meter { height: 6px; background: var(--surface-2); border-radius: 9px; overflow: hidden; }
.meter i { display: block; height: 100%; background: var(--neon); box-shadow: 0 0 10px var(--neon); border-radius: 9px; }
.grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
.grid > section { display: flex; flex-direction: column; gap: 16px; }
.grid > section:nth-child(1) { grid-column: span 2; }
.grid > section:nth-child(4) { grid-column: span 4; }
.grid > section:nth-child(2), .grid > section:nth-child(3) { grid-column: span 1; }
.between { display: flex; justify-content: space-between; align-items: center; }
.bars { display: flex; align-items: flex-end; gap: 6px; height: 180px; }
.bars.short { height: 140px; }
.bar { flex: 1; height: 100%; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; gap: 6px; }
.bar i { width: 100%; min-height: 3px; background: #2e2e2e; border-radius: 5px 5px 2px 2px; transition: background .15s; }
.bar:hover i, .bar i.hot { background: var(--neon); box-shadow: 0 0 14px rgba(180,255,31,.4); }
.bar span { font-size: 10px; color: var(--muted); }
.sports { list-style: none; display: flex; flex-direction: column; gap: 16px; }
.sports li { display: flex; flex-direction: column; gap: 6px; }
@media (max-width: 1100px) {
  .grid { grid-template-columns: 1fr 1fr; }
  .grid > section:nth-child(n) { grid-column: span 2; }
}
</style>
