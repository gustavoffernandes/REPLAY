<script setup>
import { ref, computed, onMounted } from 'vue'
import { api, money, hh } from '../api'
import CompanyBranchPicker from '../components/CompanyBranchPicker.vue'
import { auth, can, showBranch } from '../auth'

const courts = ref([])
const form = ref(null)
const err = ref('')

const load = async () => (courts.value = await api.courts())
onMounted(load)

const branchOptions = computed(() => auth.branches.filter((b) => b.active && (!auth.branchId || b.id === auth.branchId)))
const blank = () => ({ branch_id: branchOptions.value[0]?.id ?? null, name: '', price_per_hour: 90, open_hour: 8, close_hour: 22, active: true, replay_url: '' })
const edit = (c) => { err.value = ''; newToken.value = ''; copied.value = false; form.value = c ? { ...c, active: !!c.active, replay_url: c.replay_url || '' } : blank() }

// Câmera de replay: o token aparece uma única vez, para colar no captura/.env da quadra
const newToken = ref('')
const copied = ref(false)
async function rotateToken() {
  if (form.value.replay_ready && !confirm('Gerar um novo token? O serviço de captura desta quadra para de funcionar até você atualizar o captura/.env.')) return
  err.value = ''
  try {
    newToken.value = (await api.rotateReplayToken(form.value.id)).token
    copied.value = false
    form.value.replay_ready = !!form.value.replay_url
    await load()
  } catch (e) { err.value = e.message }
}
async function copyToken() {
  try { await navigator.clipboard.writeText(newToken.value); copied.value = true } catch { /* sem clipboard: o usuário copia à mão */ }
}

async function save() {
  err.value = ''
  try {
    await api.saveCourt({ ...form.value, price_per_hour: Number(form.value.price_per_hour) })
    form.value = null
    await load()
  } catch (e) { err.value = e.message }
}
async function toggle(c) {
  await api.saveCourt({ ...c, active: !c.active })
  await load()
}
</script>

<template>
  <div class="page-head">
    <div><p class="muted">{{ courts.length }} quadras cadastradas</p><h1>Quadras</h1></div>
    <button v-if="can('courts.create')" class="btn primary" @click="edit(null)">+ Nova quadra</button>
  </div>

  <div class="cards">
    <div v-for="c in courts" :key="c.id" class="card court" :class="{ off: !c.active }">
      <div class="top">
        <span class="badge confirmed">{{ showBranch ? c.branch.name : 'Areia · multiesporte' }}</span>
        <span v-if="!c.active" class="badge">Inativa</span>
        <span v-else-if="c.replay_ready" class="badge" title="Câmera de replay configurada">● Câmera</span>
      </div>
      <h2>{{ c.name }}</h2>
      <strong class="mono price">{{ money(c.price_per_hour) }}<small class="muted">/h</small></strong>
      <p class="muted mono">{{ hh(c.open_hour) }} – {{ hh(c.close_hour) }}</p>
      <div v-if="can('courts.update')" class="acts">
        <button class="btn sm" @click="edit(c)">Editar</button>
        <button class="btn sm" @click="toggle(c)">{{ c.active ? 'Desativar' : 'Ativar' }}</button>
      </div>
    </div>
  </div>

  <div v-if="form" class="modal-back" @mousedown.self="form = null">
    <form class="modal" @submit.prevent="save">
      <h2 style="margin-bottom:18px">{{ form.id ? 'Editar quadra' : 'Nova quadra' }}</h2>
      <CompanyBranchPicker v-if="!form.id" v-model="form.branch_id" />
      <div class="field"><label>Nome</label><input class="input" v-model="form.name" required /></div>
      <div class="field"><label>Preço/hora (R$)</label><input class="input" type="number" min="0" step="0.01" v-model="form.price_per_hour" required /></div>
      <div class="row">
        <div class="field"><label>Abre às</label><input class="input" type="number" min="0" max="23" v-model.number="form.open_hour" /></div>
        <div class="field"><label>Fecha às</label><input class="input" type="number" min="1" max="24" v-model.number="form.close_hour" /></div>
      </div>

      <h3>Câmera de replay</h3>
      <div class="field">
        <label>Endereço do serviço de captura</label>
        <input class="input mono" v-model.trim="form.replay_url" placeholder="http://127.0.0.1:8765" />
        <small class="muted">Opcional. É o GATILHO_HOST e a GATILHO_PORTA do captura/.env desta quadra.</small>
      </div>
      <template v-if="form.id">
        <div class="field">
          <label>Token da câmera</label>
          <div v-if="newToken" class="token">
            <code class="mono">{{ newToken }}</code>
            <button type="button" class="btn sm" @click="copyToken">{{ copied ? 'Copiado' : 'Copiar' }}</button>
          </div>
          <small v-if="newToken" class="warn">Cole em REPLAY_TOKEN no captura/.env. Ele não será mostrado de novo.</small>
          <div v-else class="token-row">
            <span class="muted small">{{ form.replay_ready ? 'Token configurado' : 'Nenhum token gerado' }}</span>
            <button type="button" class="btn sm" @click="rotateToken">{{ form.replay_ready ? 'Gerar novo token' : 'Gerar token' }}</button>
          </div>
        </div>
      </template>
      <p v-else class="muted small">Salve a quadra para gerar o token da câmera.</p>
      <p v-if="err" class="error">{{ err }}</p>
      <div class="acts end">
        <button type="button" class="btn" @click="form = null">Cancelar</button>
        <button class="btn primary">Salvar</button>
      </div>
    </form>
  </div>
</template>

<style scoped>
.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
.court { display: flex; flex-direction: column; gap: 10px; }
.court.off { opacity: .55; }
.top { display: flex; justify-content: space-between; }
.price { font-size: 26px; color: var(--neon); }
.acts { display: flex; gap: 8px; margin-top: 6px; }
.acts.end { justify-content: flex-end; }
.small { font-size: 13px; }
.field small { font-size: 12px; }
.warn { color: var(--warn); }
.token { display: flex; align-items: center; gap: 8px; background: var(--bg); border: 1px solid var(--neon); border-radius: 10px; padding: 8px 8px 8px 12px; }
.token code { flex: 1; font-size: 12px; word-break: break-all; color: var(--neon); }
.token-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
</style>
