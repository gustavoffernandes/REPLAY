<script setup>
import { ref, computed, onMounted } from 'vue'
import { api, money, hh, SPORTS, WEEKDAYS } from '../api'
import ClassModal from '../components/ClassModal.vue'
import { can, showBranch } from '../auth'

// Abre o painel da turma quem puder editar, matricular ou gerenciar horários
const canOpen = computed(() => ['classes.update', 'classes.students', 'classes.schedules'].some(can))
const list = ref([])
const err = ref('')
const modal = ref(null) // { id } | {}

async function load() {
  try { list.value = await api.classes() } catch (e) { err.value = e.message }
}
onMounted(load)
</script>

<template>
  <div class="page-head">
    <div><p class="muted">{{ list.filter((g) => g.active).length }} turmas ativas</p><h1>Turmas</h1></div>
    <button v-if="can('classes.create')" class="btn primary" @click="modal = {}">+ Nova turma</button>
  </div>
  <p v-if="err" class="error">{{ err }}</p>

  <div class="cards">
    <article v-for="g in list" :key="g.id" class="card group" :class="{ off: !g.active, readonly: !canOpen }" @click="canOpen && (modal = { id: g.id })">
      <div class="top">
        <span class="badge confirmed">{{ SPORTS[g.sport] }}</span>
        <span v-if="showBranch" class="badge">{{ g.branch.name }}</span>
        <span v-if="!g.active" class="badge">Inativa</span>
      </div>
      <h2>{{ g.name }}</h2>
      <p class="muted">{{ g.instructor ? 'Prof. ' + g.instructor : 'Sem professor definido' }}</p>

      <ul class="slots">
        <li v-for="s in g.schedules" :key="s.id">
          <strong>{{ WEEKDAYS[s.weekday].slice(0, 3) }}</strong>
          <span class="mono">{{ hh(s.start_hour) }}–{{ hh(s.end_hour) }}</span>
          <span class="muted">{{ s.court.name }}</span>
        </li>
        <li v-if="!g.schedules.length" class="muted">Sem horários</li>
      </ul>

      <div class="cap">
        <div class="between"><span class="muted">Alunos</span><span class="mono">{{ g.students_count }}/{{ g.capacity }}</span></div>
        <div class="meter"><i :style="{ width: Math.min(100, (g.students_count / g.capacity) * 100) + '%' }"></i></div>
      </div>
      <p class="mono fee">{{ money(g.monthly_fee) }}<small class="muted">/mês</small></p>
    </article>
    <p v-if="!list.length && !err" class="muted">Nenhuma turma criada ainda.</p>
  </div>

  <ClassModal v-if="modal" :group-id="modal.id" @close="modal = null; load()" @saved="load" />
</template>

<style scoped>
.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; }
.group { display: flex; flex-direction: column; gap: 10px; cursor: pointer; transition: border-color .15s, transform .15s; }
.group:hover { border-color: rgba(180, 255, 31, .45); transform: translateY(-2px); }
.group.off { opacity: .55; }
.group.readonly { cursor: default; }
.group.readonly:hover { border-color: var(--border); transform: none; }
.top { gap: 8px; }
.top { display: flex; justify-content: space-between; }
.slots { list-style: none; display: flex; flex-direction: column; gap: 6px; margin: 6px 0; font-size: 14px; }
.slots li { display: flex; gap: 10px; align-items: baseline; }
.slots strong { color: var(--neon); width: 30px; }
.between { display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 13px; }
.meter { height: 6px; background: var(--surface-2); border-radius: 9px; overflow: hidden; }
.meter i { display: block; height: 100%; background: var(--neon); box-shadow: 0 0 10px var(--neon); border-radius: 9px; }
.fee { font-size: 20px; margin-top: auto; }
</style>
