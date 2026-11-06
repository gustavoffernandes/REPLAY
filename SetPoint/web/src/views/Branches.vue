<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '../api'
import { companies, can } from '../auth'

const list = ref([])
const form = ref(null)
const err = ref('')
const busy = ref(false)

const load = async () => (list.value = await api.branches())
onMounted(load)

const copied = ref(null)
const link = (b) => `${location.origin}/reservar/${b.company.slug}/${b.slug}`
async function copyLink(b) {
  try { await navigator.clipboard.writeText(link(b)) } catch { prompt('Copie o link:', link(b)) }
  copied.value = b.id
  setTimeout(() => (copied.value = null), 2000)
}
const multiCompany = computed(() => companies.value.length > 1)
const blank = () => ({ company_id: companies.value[0]?.id ?? '', name: '', city: '', address: '', active: true })
const edit = (b) => { err.value = ''; form.value = b ? { ...b, active: !!b.active } : blank() }

async function save() {
  err.value = ''
  busy.value = true
  try {
    const f = { ...form.value, city: form.value.city || null, address: form.value.address || null }
    if (!multiCompany.value) delete f.company_id
    await api.saveBranch(f)
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
    <div><p class="muted">{{ list.length }} filiais</p><h1>Filiais</h1></div>
    <button v-if="can('branches.create')" class="btn primary" @click="edit(null)">+ Nova filial</button>
  </div>

  <div class="card" style="overflow-x:auto">
    <table>
      <thead><tr><th>Filial</th><th v-if="multiCompany">Empresa</th><th>Cidade</th><th>Quadras</th><th>Link de agendamento</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <tr v-for="b in list" :key="b.id">
          <td>{{ b.name }}<br /><small class="muted">{{ b.address || '—' }}</small></td>
          <td v-if="multiCompany">{{ b.company.name }}</td>
          <td>{{ b.city || '—' }}</td>
          <td class="mono">{{ b.courts_count }}</td>
          <td><button v-if="b.active" class="btn sm" @click="copyLink(b)">{{ copied === b.id ? 'Link copiado!' : 'Copiar link' }}</button><a v-if="b.active" :href="link(b)" target="_blank" rel="noopener" class="open muted">abrir ↗</a></td>
          <td><span class="badge" :class="b.active ? 'confirmed' : 'cancelled'">{{ b.active ? 'Ativa' : 'Inativa' }}</span></td>
          <td style="text-align:right"><button v-if="can('branches.update')" class="btn sm" @click="edit(b)">Editar</button></td>
        </tr>
        <tr v-if="!list.length"><td :colspan="multiCompany ? 7 : 6" class="muted">Nenhuma filial cadastrada.</td></tr>
      </tbody>
    </table>
  </div>

  <div v-if="form" class="modal-back" @mousedown.self="form = null">
    <form class="modal" @submit.prevent="save">
      <h2 style="margin-bottom:16px">{{ form.id ? 'Editar filial' : 'Nova filial' }}</h2>
      <div v-if="!multiCompany && !form.id" class="field"><label>Empresa</label><input class="input" :value="companies[0]?.name" disabled /></div>
      <div v-if="multiCompany && !form.id" class="field"><label>Empresa</label>
        <select class="input" v-model.number="form.company_id" required>
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <div class="field"><label>Nome</label><input class="input" v-model="form.name" required /></div>
      <div class="row">
        <div class="field"><label>Cidade</label><input class="input" v-model="form.city" /></div>
        <div class="field"><label>Endereço</label><input class="input" v-model="form.address" /></div>
      </div>
      <label v-if="form.id" class="check"><input type="checkbox" v-model="form.active" /> Filial ativa <small class="muted">(inativa: some do acesso dos usuários)</small></label>
      <p v-if="err" class="error">{{ err }}</p>
      <div class="acts">
        <button type="button" class="btn" @click="form = null">Cancelar</button>
        <button class="btn primary" :disabled="busy">{{ busy ? 'Salvando…' : 'Salvar' }}</button>
      </div>
    </form>
  </div>
</template>

<style scoped>
.open { margin-left: 10px; font-size: 13px; }
.acts { display: flex; justify-content: flex-end; gap: 8px; margin-top: 8px; }
.check { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; cursor: pointer; }
.check input { accent-color: var(--neon); }
</style>
