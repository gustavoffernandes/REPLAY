<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '../api'
import { can } from '../auth'

const roles = ref([])
const catalog = ref([])
const selectedId = ref(null)
const draft = ref(null) // { name, description, permissions: Set }
const err = ref('')
const ok = ref('')
const busy = ref(false)
const creating = ref(null) // formulário de novo papel
const editor = ref(null)

const SCOPES = {
  all: 'Toda a plataforma',
  company: 'Todas as filiais da empresa',
  branches: 'Somente filiais atribuídas',
}

const role = computed(() => roles.value.find((r) => r.id === selectedId.value))
const readonly = computed(() => !role.value || role.value.scope === 'all' || !can('roles.manage'))
// Grupos exclusivos do administrador só aparecem para o papel de plataforma
const groups = computed(() => catalog.value.filter((g) => !g.platform || role.value?.scope === 'all'))
const dirty = computed(() => {
  if (!role.value || !draft.value) return false
  const a = [...draft.value.permissions].sort().join()
  const b = [...role.value.permissions].sort().join()
  return a !== b || draft.value.name !== role.value.name || (draft.value.description || '') !== (role.value.description || '')
})

async function load(keepSelection = true) {
  const r = await api.roles()
  roles.value = r.roles
  catalog.value = r.catalog
  const target = keepSelection && roles.value.some((x) => x.id === selectedId.value) ? selectedId.value : roles.value[0]?.id
  select(target)
}
onMounted(() => load(false).catch((e) => (err.value = e.message)))

function select(id, scroll = false) {
  selectedId.value = id
  const r = roles.value.find((x) => x.id === id)
  draft.value = r ? { name: r.name, description: r.description || '', permissions: new Set(r.permissions) } : null
  err.value = ''
  ok.value = ''
  if (scroll) editor.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

const has = (key) => draft.value?.permissions.has(key)
const keysOf = (g) => g.items.map((i) => i[0])
const viewKey = (g) => `${g.key}.view`

// Marcar uma ação exige poder ver a área; desmarcar "ver" remove as demais ações da área
function toggle(g, key) {
  if (readonly.value) return
  const set = new Set(draft.value.permissions)
  if (set.has(key)) {
    set.delete(key)
    if (key === viewKey(g)) keysOf(g).forEach((k) => set.delete(k))
  } else {
    set.add(key)
    if (g.items.some((i) => i[0] === viewKey(g))) set.add(viewKey(g))
  }
  draft.value.permissions = set
}
function groupState(g) {
  const n = keysOf(g).filter(has).length
  return n === 0 ? 'none' : n === g.items.length ? 'all' : 'some'
}
function toggleGroup(g) {
  if (readonly.value) return
  const set = new Set(draft.value.permissions)
  const all = groupState(g) === 'all'
  keysOf(g).forEach((k) => (all ? set.delete(k) : set.add(k)))
  draft.value.permissions = set
}

async function save() {
  err.value = ''
  ok.value = ''
  busy.value = true
  try {
    const body = { id: role.value.id, description: draft.value.description || null, permissions: [...draft.value.permissions] }
    if (!role.value.is_system) body.name = draft.value.name
    await api.saveRole(body)
    await load()
    ok.value = 'Alterações salvas. Valem imediatamente para todos os usuários deste papel.'
  } catch (e) {
    err.value = e.message
  } finally {
    busy.value = false
  }
}

async function remove() {
  if (!confirm(`Excluir o papel "${role.value.name}"?`)) return
  err.value = ''
  try {
    await api.deleteRole(role.value.id)
    await load(false)
  } catch (e) {
    err.value = e.message
  }
}

async function create() {
  err.value = ''
  busy.value = true
  try {
    const r = await api.saveRole({ ...creating.value, permissions: [] })
    creating.value = null
    await load(false)
    select(r.id, true)
  } catch (e) {
    err.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="page-head">
    <div><p class="muted">Defina o que cada papel pode fazer no sistema</p><h1>Papéis e permissões</h1></div>
    <button v-if="can('roles.manage')" class="btn primary" @click="creating = { name: '', description: '', scope: 'branches' }; err = ''">+ Novo papel</button>
  </div>

  <div class="layout">
    <!-- Lista de papéis -->
    <aside class="list">
      <button v-for="r in roles" :key="r.id" class="rcard" :class="{ on: r.id === selectedId }" @click="select(r.id, true)">
        <div class="rtop">
          <strong>{{ r.name }}</strong>
          <span class="badge" :class="{ confirmed: r.is_system }">{{ r.is_system ? 'Sistema' : 'Personalizado' }}</span>
        </div>
        <small class="muted">{{ SCOPES[r.scope] }}</small>
        <small class="muted">{{ r.users_count }} usuário(s) · {{ r.scope === 'all' ? 'todas as permissões' : r.permissions.length + ' permissões' }}</small>
      </button>
    </aside>

    <!-- Editor -->
    <section v-if="role && draft" ref="editor" class="card editor">
      <div class="ehead">
        <div class="grow">
          <input class="input title" v-model="draft.name" :disabled="readonly || role.is_system" maxlength="80" aria-label="Nome do papel" />
          <input class="input desc" v-model="draft.description" :disabled="readonly" maxlength="255" placeholder="Descrição (opcional)" aria-label="Descrição" />
        </div>
        <button v-if="!role.is_system && can('roles.manage')" class="btn sm danger" @click="remove">Excluir</button>
      </div>

      <p v-if="role.scope === 'all'" class="note">Acesso total à plataforma, todas as empresas e filiais. Este papel não pode ser alterado.</p>
      <p v-else class="note muted">Alcance: <strong>{{ SCOPES[role.scope] }}</strong>. Marcar uma ação libera automaticamente o "ver" da área.</p>

      <div class="groups">
        <div v-for="g in groups" :key="g.key" class="group">
          <label class="ghead" :class="{ ro: readonly }">
            <input type="checkbox" :checked="role.scope === 'all' || groupState(g) === 'all'" :indeterminate.prop="role.scope !== 'all' && groupState(g) === 'some'" :disabled="readonly" @change="toggleGroup(g)" />
            <strong>{{ g.label }}</strong>
            <small v-if="g.platform" class="muted">exclusivo do administrador</small>
          </label>
          <div class="items">
            <label v-for="[key, label] in g.items" :key="key" class="item" :class="{ ro: readonly, on: role.scope === 'all' || has(key) }">
              <input type="checkbox" :checked="role.scope === 'all' || has(key)" :disabled="readonly" @change="toggle(g, key)" />
              <span>{{ label }}</span>
              <code class="mono">{{ key }}</code>
            </label>
          </div>
        </div>
      </div>

      <p v-if="err" class="error">{{ err }}</p>
      <p v-if="ok" class="okmsg">{{ ok }}</p>
      <div v-if="!readonly" class="bar">
        <span class="muted">{{ dirty ? 'Há alterações não salvas.' : 'Nenhuma alteração.' }}</span>
        <div class="acts">
          <button class="btn" :disabled="!dirty || busy" @click="select(role.id)">Descartar</button>
          <button class="btn primary" :disabled="!dirty || busy" @click="save">{{ busy ? 'Salvando…' : 'Salvar alterações' }}</button>
        </div>
      </div>
    </section>
  </div>

  <!-- Novo papel -->
  <div v-if="creating" class="modal-back" @mousedown.self="creating = null">
    <form class="modal" @submit.prevent="create">
      <h2 style="margin-bottom:16px">Novo papel</h2>
      <div class="field"><label>Nome</label><input class="input" v-model="creating.name" required minlength="2" maxlength="80" placeholder="Ex.: Recepção" autofocus /></div>
      <div class="field"><label>Descrição</label><input class="input" v-model="creating.description" maxlength="255" placeholder="Opcional" /></div>
      <div class="field">
        <label>Alcance do papel</label>
        <select class="input" v-model="creating.scope">
          <option value="branches">{{ SCOPES.branches }}</option>
          <option value="company">{{ SCOPES.company }}</option>
        </select>
        <small class="muted hint">{{ creating.scope === 'branches' ? 'O proprietário da empresa poderá atribuir este papel e escolher as filiais de cada usuário.' : 'Só o administrador poderá atribuir este papel. O usuário acessa todas as filiais da empresa.' }}</small>
      </div>
      <p class="muted hint">Depois de criar, marque as permissões no quadro.</p>
      <p v-if="err" class="error">{{ err }}</p>
      <div class="macts">
        <button type="button" class="btn" @click="creating = null">Cancelar</button>
        <button class="btn primary" :disabled="busy">{{ busy ? 'Criando…' : 'Criar papel' }}</button>
      </div>
    </form>
  </div>
</template>

<style scoped>
.layout { display: grid; grid-template-columns: 300px 1fr; gap: 16px; align-items: start; }
.list { display: flex; flex-direction: column; gap: 8px; position: sticky; top: 16px; }
.rcard { text-align: left; background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 14px 16px; display: flex; flex-direction: column; gap: 4px; cursor: pointer; transition: .12s; }
.rcard:hover { border-color: #3a3a3a; }
.rcard.on { border-color: var(--neon); background: var(--neon-dim); }
.rtop { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.editor { display: flex; flex-direction: column; gap: 16px; }
.ehead { display: flex; gap: 12px; align-items: flex-start; }
.grow { flex: 1; display: flex; flex-direction: column; gap: 8px; }
.title { font-size: 18px; font-weight: 600; }
.input:disabled { opacity: .75; cursor: not-allowed; }
.note { font-size: 14px; padding: 10px 12px; background: var(--bg); border: 1px solid var(--border); border-radius: 10px; }
.groups { display: flex; flex-direction: column; gap: 14px; }
.group { border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
.ghead { display: flex; align-items: center; gap: 10px; padding: 11px 14px; background: var(--surface-2); cursor: pointer; }
.ghead.ro, .item.ro { cursor: default; }
.items { display: flex; flex-direction: column; }
.item { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-top: 1px solid var(--border); cursor: pointer; font-size: 14px; transition: background .1s; }
.item:hover:not(.ro) { background: var(--surface-2); }
.item span { flex: 1; }
.item code { font-size: 11px; color: var(--muted); }
.item.on span { color: var(--text); }
.item:not(.on) span { color: var(--muted); }
input[type='checkbox'] { accent-color: var(--neon); width: 17px; height: 17px; flex: none; }
.bar { position: sticky; bottom: 0; display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 14px 0 2px; background: linear-gradient(to top, var(--surface) 70%, transparent); flex-wrap: wrap; }
.acts, .macts { display: flex; gap: 8px; }
.macts { justify-content: flex-end; margin-top: 6px; }
.okmsg { color: var(--neon); font-size: 14px; }
.hint { display: block; margin-top: 6px; line-height: 1.4; }
@media (max-width: 900px) {
  .layout { grid-template-columns: 1fr; }
  .list { position: static; flex-direction: row; overflow-x: auto; padding-bottom: 4px; }
  .rcard { min-width: 220px; flex: none; }
  .item code { display: none; }
}
</style>
