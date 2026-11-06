<script setup>
import { ref, watch, onMounted } from 'vue'
import { api, money } from '../api'
import CustomerModal from '../components/CustomerModal.vue'
import { can, companies } from '../auth'

const list = ref([])
const q = ref('')
const kind = ref('') // '' | '1' (fixos) | '0' (avulsos)
const err = ref('')
const modal = ref(null) // { id } | {}

async function load() {
  err.value = ''
  const p = {}
  if (q.value.trim()) p.q = q.value.trim()
  if (kind.value !== '') p.fixed = kind.value
  try { list.value = await api.customers(p) } catch (e) { err.value = e.message }
}
let t
onMounted(load)
watch(kind, load)
watch(q, () => { clearTimeout(t); t = setTimeout(load, 250) })
</script>

<template>
  <div class="page-head">
    <div><p class="muted">{{ list.length }} clientes</p><h1>Clientes</h1></div>
    <button v-if="can('customers.create')" class="btn primary" @click="modal = {}">+ Novo cliente</button>
  </div>

  <div class="bar">
    <input class="input search" v-model="q" placeholder="Buscar por nome ou telefone…" />
    <div class="tabs">
      <button :class="{ on: kind === '' }" @click="kind = ''">Todos</button>
      <button :class="{ on: kind === '1' }" @click="kind = '1'">Fixos</button>
      <button :class="{ on: kind === '0' }" @click="kind = '0'">Avulsos</button>
    </div>
  </div>
  <p v-if="err" class="error">{{ err }}</p>

  <div class="card" style="overflow-x:auto">
    <table>
      <thead><tr><th>Cliente</th><th v-if="companies.length > 1">Empresa</th><th>Telefone</th><th>Tipo</th><th>Reservas</th><th>Total gasto</th></tr></thead>
      <tbody>
        <tr v-for="c in list" :key="c.id" class="row-click" @click="modal = { id: c.id }">
          <td>{{ c.name }}<br /><small class="muted">{{ c.email || '—' }}</small></td>
          <td v-if="companies.length > 1">{{ c.company?.name }}</td>
          <td class="mono">{{ c.phone }}</td>
          <td><span class="badge" :class="{ confirmed: c.is_fixed }">{{ c.is_fixed ? 'Fixo' : 'Avulso' }}</span></td>
          <td class="mono">{{ c.bookings_count }}</td>
          <td class="mono">{{ money(c.total_spent) }}</td>
        </tr>
        <tr v-if="!list.length"><td colspan="6" class="muted">Nenhum cliente encontrado.</td></tr>
      </tbody>
    </table>
  </div>

  <CustomerModal v-if="modal" :customer-id="modal.id" @close="modal = null" @saved="load" />
</template>

<style scoped>
.bar { display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
.search { max-width: 340px; }
.tabs { display: flex; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 3px; }
.tabs button { background: transparent; border: none; padding: 7px 16px; border-radius: 8px; cursor: pointer; color: var(--muted); }
.tabs button.on { background: var(--neon-dim); color: var(--neon); }
.row-click { cursor: pointer; transition: background .12s; }
.row-click:hover { background: var(--surface-2); }
</style>
