<script setup>
import { ref, computed, onMounted } from 'vue'
import { api, hh, SPORTS, WEEKDAYS } from '../api'
import ScheduleForm from './ScheduleForm.vue'
import { auth, can, companies } from '../auth'

const props = defineProps({ customerId: Number })
const emit = defineEmits(['close', 'saved'])

const form = ref({ name: '', phone: '', email: '', notes: '', is_fixed: false, company_id: null })
// Empresa só precisa ser escolhida ao criar quando o escopo atual tem mais de uma
const needsCompany = computed(() => !props.customerId && !id.value && !auth.branchId && companies.value.length > 1)
const readonly = computed(() => !can(id.value ? 'customers.update' : 'customers.create'))
const id = ref(props.customerId ?? null)
const companyId = ref(null)
const schedules = ref([])
const classGroups = ref([])
const courts = ref([])
const err = ref('')
const busy = ref(false)

async function load() {
  if (!id.value) return
  const c = await api.customer(id.value)
  form.value = { name: c.name, phone: c.phone, email: c.email ?? '', notes: c.notes ?? '', is_fixed: c.is_fixed }
  schedules.value = c.schedules
  companyId.value = c.company_id
  classGroups.value = c.class_groups
}
onMounted(async () => {
  courts.value = await api.courts()
  try { await load() } catch (e) { err.value = e.message }
})

async function save() {
  err.value = ''
  busy.value = true
  try {
    const payload = { id: id.value, ...form.value, email: form.value.email || null, notes: form.value.notes || null }
    if (!needsCompany.value) delete payload.company_id
    const r = await api.saveCustomer(payload)
    if (!id.value) id.value = r.id
    emit('saved')
    await load()
  } catch (e) {
    err.value = e.message
  } finally {
    busy.value = false
  }
}

async function removeSchedule(s) {
  if (!confirm('Remover este horário fixo? As reservas futuras dele serão canceladas.')) return
  try { await api.removeSchedule(s.id); await load(); emit('saved') } catch (e) { err.value = e.message }
}
const addSchedule = (payload) => api.addCustomerSchedule(id.value, payload)
</script>

<template>
  <div class="modal-back" @mousedown.self="emit('close')">
    <div class="modal wide">
      <h2>{{ customerId ? 'Cliente' : id ? 'Cliente' : 'Novo cliente' }}</h2>
      <form @submit.prevent="save" style="margin-top:16px">
        <fieldset :disabled="readonly" class="fs">
        <div v-if="!needsCompany && !id && companies.length === 1" class="field"><label>Empresa</label><input class="input" :value="companies[0].name" disabled /></div>
        <div v-if="needsCompany" class="field"><label>Empresa</label>
          <select class="input" v-model.number="form.company_id" required>
            <option :value="null" disabled>Selecione…</option>
            <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </div>
        <div class="field"><label>Nome</label><input class="input" v-model="form.name" required /></div>
        <div class="row">
          <div class="field"><label>Telefone</label><input class="input" v-model="form.phone" required /></div>
          <div class="field"><label>E-mail</label><input class="input" type="email" v-model="form.email" /></div>
        </div>
        <div class="field"><label>Observações</label><input class="input" v-model="form.notes" placeholder="Opcional" /></div>
        <label class="toggle">
          <input type="checkbox" v-model="form.is_fixed" />
          <span class="box"></span>
          <span><strong>Cliente fixo</strong><small class="muted"> — mensalista com horários semanais recorrentes</small></span>
        </label>
        </fieldset>
        <p v-if="err" class="error">{{ err }}</p>
        <div class="acts">
          <button type="button" class="btn" @click="emit('close')">Fechar</button>
          <button v-if="!readonly" class="btn primary" :disabled="busy">{{ busy ? 'Salvando…' : 'Salvar' }}</button>
        </div>
      </form>

      <template v-if="id && form.is_fixed">
        <h3>Horários fixos</h3>
        <ul v-if="schedules.length" class="list">
          <li v-for="s in schedules" :key="s.id">
            <span><strong>{{ WEEKDAYS[s.weekday] }}</strong> · <span class="mono">{{ hh(s.start_hour) }}–{{ hh(s.end_hour) }}</span> · {{ s.court.name }} · {{ SPORTS[s.sport] }}</span>
            <button v-if="can('customers.recurring')" class="btn sm danger" @click="removeSchedule(s)">Remover</button>
          </li>
        </ul>
        <p v-else class="muted empty">Nenhum horário fixo ainda.</p>
        <ScheduleForm v-if="can('customers.recurring')" :courts="courts.filter((c) => c.branch?.company_id === companyId)" :action="addSchedule" @done="load(); emit('saved')" />
      </template>

      <template v-if="classGroups.length">
        <h3>Turmas</h3>
        <div class="chips"><span v-for="g in classGroups" :key="g.id" class="badge confirmed">{{ g.name }}</span></div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.toggle { display: flex; align-items: center; gap: 12px; cursor: pointer; margin: 4px 0 16px; }
.toggle input { display: none; }
.box { width: 40px; height: 22px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 99px; position: relative; transition: .15s; flex: none; }
.box::after { content: ''; position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; border-radius: 50%; background: var(--muted); transition: .15s; }
.toggle input:checked + .box { background: var(--neon-dim); border-color: var(--neon); }
.toggle input:checked + .box::after { left: 20px; background: var(--neon); box-shadow: 0 0 10px var(--neon); }
.fs { border: none; padding: 0; margin: 0; min-width: 0; }
.acts { display: flex; justify-content: flex-end; gap: 8px; }
.list { list-style: none; display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px; }
.list li { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 10px 12px; background: var(--bg); border: 1px solid var(--border); border-radius: 10px; font-size: 14px; }
.empty { margin-bottom: 12px; font-size: 14px; }
.chips { display: flex; gap: 8px; flex-wrap: wrap; }
</style>
