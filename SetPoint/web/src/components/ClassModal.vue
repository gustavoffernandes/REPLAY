<script setup>
import { ref, computed, onMounted } from 'vue'
import { api, hh, money, SPORTS, WEEKDAYS } from '../api'
import { auth, can } from '../auth'
import ScheduleForm from './ScheduleForm.vue'
import CompanyBranchPicker from './CompanyBranchPicker.vue'

const props = defineProps({ groupId: Number })
const emit = defineEmits(['close', 'saved'])

const id = ref(props.groupId ?? null)
const canEdit = computed(() => can(id.value ? 'classes.update' : 'classes.create'))
const branchOptions = computed(() => auth.branches.filter((b) => b.active && (!auth.branchId || b.id === auth.branchId)))
const form = ref({ branch_id: branchOptions.value[0]?.id ?? null, name: '', sport: 'futvolei', instructor: '', capacity: 10, monthly_fee: 0, active: true })
const group = ref(null)
const courts = ref([])
const customers = ref([])
const pick = ref('')
const err = ref('')
const busy = ref(false)

async function load() {
  if (!id.value) return
  group.value = await api.classGroup(id.value)
  const g = group.value
  form.value = { branch_id: g.branch_id, name: g.name, sport: g.sport, instructor: g.instructor ?? '', capacity: g.capacity, monthly_fee: Number(g.monthly_fee), active: g.active }
}
onMounted(async () => {
  ;[courts.value, customers.value] = await Promise.all([api.courts(), api.customers()])
  try { await load() } catch (e) { err.value = e.message }
})

const available = computed(() => {
  const enrolled = new Set(group.value?.students.map((s) => s.id))
  const company = group.value?.branch?.company_id
  return customers.value.filter((c) => !enrolled.has(c.id) && (!company || c.company_id === company))
})
const groupCourts = computed(() => courts.value.filter((c) => c.branch_id === group.value?.branch_id))
const full = computed(() => group.value && group.value.students.length >= group.value.capacity)

async function run(fn) {
  err.value = ''
  try { await fn(); await load(); emit('saved') } catch (e) { err.value = e.message }
}
async function save() {
  busy.value = true
  await run(async () => {
    const r = await api.saveClass({ id: id.value, ...form.value, instructor: form.value.instructor || null })
    if (!id.value) id.value = r.id
  })
  busy.value = false
}
const addStudent = () => pick.value && run(async () => { await api.addStudent(id.value, Number(pick.value)); pick.value = '' })
const removeStudent = (s) => run(() => api.removeStudent(id.value, s.id))
const removeSchedule = (s) => confirm('Remover este horário? As aulas futuras dele serão canceladas.') && run(() => api.removeSchedule(s.id))
async function removeGroup() {
  const n = group.value?.students.length ?? 0
  const msg = `Excluir a turma "${form.value.name}"?

As aulas futuras serão canceladas${n ? ` e os ${n} aluno(s) serão desvinculados` : ''}. O histórico das aulas já realizadas é mantido.`
  if (!confirm(msg)) return
  err.value = ''
  try {
    await api.deleteClass(id.value)
    emit('saved')
    emit('close')
  } catch (e) {
    err.value = e.message
  }
}
const addSchedule = (payload) => api.addClassSchedule(id.value, payload)
</script>

<template>
  <div class="modal-back" @mousedown.self="emit('close')">
    <div class="modal wide">
      <div class="mhead">
        <h2>{{ id ? form.name || 'Turma' : 'Nova turma' }}</h2>
        <button v-if="id && can('classes.delete')" type="button" class="btn sm danger" @click="removeGroup">Excluir turma</button>
      </div>

      <form @submit.prevent="save" style="margin-top:16px">
        <fieldset :disabled="!canEdit" class="fs">
        <CompanyBranchPicker v-if="!id" v-model="form.branch_id" />
        <div class="row">
          <div class="field"><label>Nome da turma</label><input class="input" v-model="form.name" required placeholder="Ex.: Futevôlei Iniciante" /></div>
          <div class="field"><label>Esporte</label>
            <select class="input" v-model="form.sport" :disabled="!!id"><option v-for="(l, k) in SPORTS" :key="k" :value="k">{{ l }}</option></select>
          </div>
        </div>
        <div class="row">
          <div class="field"><label>Professor</label><input class="input" v-model="form.instructor" /></div>
          <div class="field"><label>Vagas</label><input class="input" type="number" min="1" max="60" v-model.number="form.capacity" required /></div>
          <div class="field"><label>Mensalidade (R$)</label><input class="input" type="number" min="0" step="0.01" v-model.number="form.monthly_fee" /></div>
        </div>
        <label v-if="id" class="check"><input type="checkbox" v-model="form.active" /> Turma ativa <small class="muted">(inativar cancela as aulas futuras)</small></label>
        </fieldset>
        <p v-if="err" class="error">{{ err }}</p>
        <div class="acts">
          <button type="button" class="btn" @click="emit('close')">Fechar</button>
          <button v-if="canEdit" class="btn primary" :disabled="busy">{{ id ? 'Salvar' : 'Criar turma' }}</button>
        </div>
      </form>

      <template v-if="group">
        <h3>Horários da turma</h3>
        <ul v-if="group.schedules.length" class="list">
          <li v-for="s in group.schedules" :key="s.id">
            <span><strong>{{ WEEKDAYS[s.weekday] }}</strong> · <span class="mono">{{ hh(s.start_hour) }}–{{ hh(s.end_hour) }}</span> · {{ s.court.name }}</span>
            <button v-if="can('classes.schedules')" class="btn sm danger" @click="removeSchedule(s)">Remover</button>
          </li>
        </ul>
        <p v-else class="muted empty">Nenhum horário definido.</p>
        <p v-if="err" class="error">{{ err }}</p>
        <ScheduleForm v-if="group.active && can('classes.schedules')" :courts="groupCourts" :fixed-sport="group.sport" :action="addSchedule" @done="load(); emit('saved')" />

        <h3>Alunos · {{ group.students.length }}/{{ group.capacity }}</h3>
        <ul v-if="group.students.length" class="list">
          <li v-for="s in group.students" :key="s.id">
            <span>{{ s.name }} <small class="muted mono">{{ s.phone }}</small></span>
            <button v-if="can('classes.students')" class="btn sm danger" @click="removeStudent(s)">Remover</button>
          </li>
        </ul>
        <p v-else class="muted empty">Nenhum aluno matriculado.</p>
        <div v-if="can('classes.students')" class="add">
          <select class="input" v-model="pick" :disabled="full">
            <option value="">{{ full ? 'Turma lotada' : 'Selecionar cliente…' }}</option>
            <option v-for="c in available" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
          <button class="btn" :disabled="!pick || full" @click="addStudent">Matricular</button>
        </div>
        <p v-if="err" class="error" style="margin-top:10px">{{ err }}</p>
        <p class="muted fee" v-if="Number(group.monthly_fee) > 0">Receita potencial: <span class="mono">{{ money(Number(group.monthly_fee) * group.students.length) }}</span>/mês</p>
      </template>
    </div>
  </div>
</template>

<style scoped>
.mhead { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
.fs { border: none; padding: 0; margin: 0; min-width: 0; }
.acts { display: flex; justify-content: flex-end; gap: 8px; }
.check { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; cursor: pointer; }
.check input { accent-color: var(--neon); }
.list { list-style: none; display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px; }
.list li { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 10px 12px; background: var(--bg); border: 1px solid var(--border); border-radius: 10px; font-size: 14px; }
.empty { margin-bottom: 12px; font-size: 14px; }
.add { display: flex; gap: 8px; }
.fee { margin-top: 12px; font-size: 13px; }
</style>
