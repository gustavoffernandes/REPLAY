<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '../api'
import { auth, can, companies } from '../auth'
import { isStrong } from '../passwordRules'
import PasswordField from '../components/PasswordField.vue'

const list = ref([])
const branches = ref([])
const roles = ref([]) // papéis que o usuário logado pode atribuir
const form = ref(null)
const err = ref('')
const busy = ref(false)

async function load() {
  ;[list.value, branches.value, roles.value] = await Promise.all([api.users(), api.branches(), api.assignableRoles()])
}
onMounted(load)

const isAdmin = computed(() => auth.user.role_scope === 'all')
const isSelf = computed(() => form.value?.id === auth.user.id)
// Ao editar a si mesmo o papel fica travado, mas precisa aparecer na lista mesmo que não seja atribuível
const roleOptions = computed(() => {
  const opts = [...roles.value]
  if (isSelf.value && !opts.some((r) => r.key === form.value.role)) opts.push({ key: form.value.role, name: auth.user.role_label, scope: auth.user.role_scope })
  return opts
})
const roleScope = computed(() => roleOptions.value.find((r) => r.key === form.value?.role)?.scope)
const needsBranches = computed(() => roleScope.value === 'branches')
const needsCompany = computed(() => isAdmin.value && roleScope.value !== 'all')
const companyBranches = computed(() => branches.value.filter((b) => !needsCompany.value || b.company_id === form.value.company_id))
const canEdit = (u) => can('users.update') && (u.id === auth.user.id || isAdmin.value || u.role_scope === 'branches')

const blank = () => ({ name: '', email: '', password: '', role: roles.value.find((r) => r.key === 'staff')?.key ?? roles.value[0]?.key ?? '', company_id: companies.value[0]?.id ?? null, active: true, branch_ids: [] })
function edit(u) {
  err.value = ''
  form.value = u
    ? { id: u.id, name: u.name, email: u.email, password: '', role: u.role, company_id: u.company_id, active: !!u.active, branch_ids: [...u.branch_ids] }
    : blank()
}
const toggleBranch = (id) => {
  const s = new Set(form.value.branch_ids)
  s.has(id) ? s.delete(id) : s.add(id)
  form.value.branch_ids = [...s]
}
const onCompanyChange = () => (form.value.branch_ids = [])

async function save() {
  err.value = ''
  if (form.value.password && !isStrong(form.value.password, form.value.email)) {
    err.value = 'A senha não atende a todos os requisitos de segurança.'
    return
  }
  busy.value = true
  try {
    const f = { ...form.value }
    if (!f.password) delete f.password
    if (!needsBranches.value) f.branch_ids = []
    await api.saveUser(f)
    form.value = null
    await load()
  } catch (e) {
    err.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="page-head">
    <div><p class="muted">{{ list.length }} usuários</p><h1>Usuários</h1></div>
    <button v-if="can('users.create')" class="btn primary" @click="edit(null)">+ Novo usuário</button>
  </div>

  <div class="card" style="overflow-x:auto">
    <table>
      <thead><tr><th>Usuário</th><th>Papel</th><th v-if="isAdmin">Empresa</th><th>Filiais</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <tr v-for="u in list" :key="u.id">
          <td>{{ u.name }}<br /><small class="muted">{{ u.email }}</small></td>
          <td><span class="badge" :class="{ confirmed: u.role_scope !== 'branches' }">{{ u.role_label }}</span></td>
          <td v-if="isAdmin">{{ u.company?.name || '—' }}</td>
          <td class="muted">{{ u.role_scope === 'branches' ? u.branches.map((b) => b.name).join(', ') || '—' : 'Todas' }}</td>
          <td><span class="badge" :class="u.active ? 'confirmed' : 'cancelled'">{{ u.active ? 'Ativo' : 'Inativo' }}</span></td>
          <td style="text-align:right"><button v-if="canEdit(u)" class="btn sm" @click="edit(u)">Editar</button></td>
        </tr>
      </tbody>
    </table>
  </div>

  <div v-if="form" class="modal-back" @mousedown.self="form = null">
    <form class="modal wide" @submit.prevent="save">
      <h2 style="margin-bottom:16px">{{ form.id ? 'Editar usuário' : 'Novo usuário' }}</h2>
      <div class="row">
        <div class="field"><label>Nome</label><input class="input" v-model="form.name" required /></div>
        <div class="field"><label>E-mail</label><input class="input" type="email" v-model="form.email" required autocomplete="off" /></div>
      </div>
      <PasswordField v-model="form.password" :label="form.id ? 'Nova senha (deixe vazio para manter)' : 'Senha'" :email="form.email" meter :required="!form.id" />

      <div class="row">
        <div class="field"><label>Papel</label>
          <select class="input" v-model="form.role" :disabled="isSelf">
            <option v-for="r in roleOptions" :key="r.key" :value="r.key">{{ r.name }}</option>
          </select>
        </div>
        <div v-if="needsCompany" class="field"><label>Empresa</label>
          <select class="input" v-model.number="form.company_id" required :disabled="isSelf" @change="onCompanyChange">
            <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </div>
      </div>

      <div v-if="needsBranches" class="field">
        <label>Filiais com acesso</label>
        <div class="branches">
          <label v-for="b in companyBranches" :key="b.id" class="opt" :class="{ on: form.branch_ids.includes(b.id) }">
            <input type="checkbox" :checked="form.branch_ids.includes(b.id)" @change="toggleBranch(b.id)" /> {{ b.name }}
          </label>
          <p v-if="!companyBranches.length" class="muted">Nenhuma filial disponível.</p>
        </div>
      </div>
      <p v-else-if="roleScope === 'company'" class="muted note">Este papel acessa todas as filiais da empresa.</p>
      <p v-else class="muted note">Administradores acessam todas as empresas e filiais.</p>

      <label v-if="form.id" class="check"><input type="checkbox" v-model="form.active" :disabled="isSelf" /> Usuário ativo</label>
      <p v-if="err" class="error">{{ err }}</p>
      <div class="acts">
        <button type="button" class="btn" @click="form = null">Cancelar</button>
        <button class="btn primary" :disabled="busy">{{ busy ? 'Salvando…' : 'Salvar' }}</button>
      </div>
    </form>
  </div>
</template>

<style scoped>
.acts { display: flex; justify-content: flex-end; gap: 8px; margin-top: 8px; }
.check { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; cursor: pointer; }
.check input { accent-color: var(--neon); }
.note { font-size: 13px; margin-bottom: 14px; }
.branches { display: flex; gap: 8px; flex-wrap: wrap; }
.opt { display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: var(--bg); border: 1px solid var(--border); border-radius: 10px; cursor: pointer; text-transform: none; letter-spacing: 0; font-size: 14px; color: var(--text); }
.opt input { accent-color: var(--neon); }
.opt.on { border-color: var(--neon); background: var(--neon-dim); }
</style>
