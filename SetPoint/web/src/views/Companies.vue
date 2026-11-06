<script setup>
import { ref, onMounted } from 'vue'
import { api } from '../api'
import { can } from '../auth'
import { isStrong } from '../passwordRules'
import PasswordField from '../components/PasswordField.vue'

const list = ref([])
const form = ref(null)
const err = ref('')
const busy = ref(false)

const load = async () => (list.value = await api.companies())
onMounted(load)

const blank = () => ({ deposit_percent: 50, name: '', document: '', email: '', phone: '', active: true, branch_name: 'Matriz', owner_name: '', owner_email: '', owner_password: '' })
const edit = (c) => { err.value = ''; form.value = c ? { ...c, active: !!c.active } : blank() }

async function save() {
  err.value = ''
  if (form.value.owner_password && !isStrong(form.value.owner_password, form.value.owner_email)) {
    err.value = 'A senha inicial não atende a todos os requisitos de segurança.'
    return
  }
  busy.value = true
  try {
    const f = { ...form.value }
    // campos opcionais vazios não devem ir como string vazia
    for (const k of ['document', 'email', 'phone', 'owner_name', 'owner_email', 'owner_password']) if (!f[k]) f[k] = null
    await api.saveCompany(f)
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
    <div><p class="muted">{{ list.length }} empresas contratantes</p><h1>Empresas</h1></div>
    <button v-if="can('companies.create')" class="btn primary" @click="edit(null)">+ Nova empresa</button>
  </div>

  <div class="card" style="overflow-x:auto">
    <table>
      <thead><tr><th>Empresa</th><th>CNPJ</th><th>Filiais</th><th>Usuários</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <tr v-for="c in list" :key="c.id">
          <td>{{ c.name }}<br /><small class="muted">{{ c.email || '—' }}</small></td>
          <td class="mono">{{ c.document || '—' }}</td>
          <td class="mono">{{ c.active_branches_count }}/{{ c.branches_count }}</td>
          <td class="mono">{{ c.users_count }}</td>
          <td><span class="badge" :class="c.active ? 'confirmed' : 'cancelled'">{{ c.active ? 'Ativa' : 'Inativa' }}</span></td>
          <td style="text-align:right"><button v-if="can('companies.update')" class="btn sm" @click="edit(c)">Editar</button></td>
        </tr>
        <tr v-if="!list.length"><td colspan="6" class="muted">Nenhuma empresa cadastrada.</td></tr>
      </tbody>
    </table>
  </div>

  <div v-if="form" class="modal-back" @mousedown.self="form = null">
    <form class="modal wide" @submit.prevent="save">
      <h2 style="margin-bottom:16px">{{ form.id ? 'Editar empresa' : 'Nova empresa' }}</h2>
      <div class="field"><label>Razão / nome fantasia</label><input class="input" v-model="form.name" required /></div>
      <div class="row">
        <div class="field"><label>CNPJ</label><input class="input mono" v-model="form.document" placeholder="Somente números" /></div>
        <div class="field"><label>Telefone</label><input class="input" v-model="form.phone" /></div>
      </div>
      <div class="field"><label>Sinal do agendamento online (%)</label><input class="input mono" type="number" min="0" max="100" v-model.number="form.deposit_percent" required /><small class="muted">Percentual cobrado via Pix ao reservar. Use 0 para confirmar sem pagamento.</small></div>
      <div class="field"><label>E-mail de contato</label><input class="input" type="email" v-model="form.email" /></div>

      <label v-if="form.id" class="check"><input type="checkbox" v-model="form.active" /> Empresa ativa <small class="muted">(inativa: ninguém da empresa consegue entrar)</small></label>

      <template v-if="!form.id">
        <h3>Primeira filial</h3>
        <div class="field"><label>Nome da filial</label><input class="input" v-model="form.branch_name" /></div>
        <h3>Usuário proprietário <small class="muted">(opcional)</small></h3>
        <div class="field"><label>Nome</label><input class="input" v-model="form.owner_name" /></div>
        <div class="field"><label>E-mail de acesso</label><input class="input" type="email" v-model="form.owner_email" /></div>
        <PasswordField v-model="form.owner_password" label="Senha inicial" :email="form.owner_email" meter />
      </template>

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
</style>
