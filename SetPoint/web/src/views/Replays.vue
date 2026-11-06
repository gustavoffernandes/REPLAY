<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { api, REPLAY_SOURCES } from '../api'
import { can, showBranch } from '../auth'

const cameras = ref([])
const status = reactive({}) // courtId -> resposta de /replay-status
const notice = reactive({}) // courtId -> { text, kind }
const busy = reactive({})   // courtId -> true enquanto o clique é enviado
const list = ref([])
const courtId = ref('')
const date = ref('')
const err = ref('')
const freshIds = ref(new Set()) // replays que acabaram de chegar (destaque)

let waitingUntil = 0 // enquanto houver replay sendo montado, a lista atualiza mais rápido
let statusTimer, listTimer

const params = () => Object.fromEntries(Object.entries({ court_id: courtId.value, date: date.value }).filter(([, v]) => v))

async function loadList(silent = false) {
  try {
    const before = new Set(list.value.map((r) => r.id))
    const rows = await api.replays(params(), silent)
    if (silent && before.size) freshIds.value = new Set(rows.filter((r) => !before.has(r.id)).map((r) => r.id))
    if (silent && rows.some((r) => !before.has(r.id))) {
      Object.keys(notice).forEach((id) => notice[id]?.kind === 'wait' && (notice[id] = { text: 'Replay disponível!', kind: 'ok' }))
      waitingUntil = 0
    }
    list.value = rows
  } catch (e) { if (!silent) err.value = e.message }
}

async function refreshStatus() {
  await Promise.all(cameras.value.map(async (c) => {
    try { status[c.id] = await api.replayStatus(c.id) } catch { status[c.id] = { configured: true, online: false } }
  }))
}

function scheduleList() {
  clearTimeout(listTimer)
  listTimer = setTimeout(async () => {
    await loadList(true)
    scheduleList()
  }, Date.now() < waitingUntil ? 2000 : 15000)
}

onMounted(async () => {
  try { cameras.value = await api.replayCameras() } catch (e) { err.value = e.message }
  await Promise.all([loadList(), refreshStatus()])
  statusTimer = setInterval(refreshStatus, 5000)
  scheduleList()
})
onBeforeUnmount(() => { clearInterval(statusTimer); clearTimeout(listTimer) })
watch([courtId, date], () => loadList())

async function save(c) {
  notice[c.id] = null
  busy[c.id] = true
  try {
    await api.saveReplay(c.id)
    notice[c.id] = { text: 'Clique registrado. Montando o replay…', kind: 'wait' }
    waitingUntil = Date.now() + 45000
    scheduleList()
  } catch (e) {
    notice[c.id] = { text: e.message, kind: 'error' }
  } finally {
    busy[c.id] = false
  }
}

async function remove(r) {
  if (!confirm('Excluir este replay? O vídeo será apagado.')) return
  try { await api.deleteReplay(r.id); await loadList() } catch (e) { err.value = e.message }
}

// Estado da câmera para o indicador
const state = (id) => {
  const s = status[id]
  if (!s) return { cls: '', text: 'Verificando…' }
  if (!s.online) return { cls: 'off', text: 'Câmera offline' }
  if (!s.recording) return { cls: 'warn', text: 'Sem vídeo chegando' }
  return { cls: 'on', text: 'Gravando' }
}
const detail = (id) => {
  const s = status[id]
  if (!s) return ''
  if (!s.online) return 'Inicie o serviço de captura desta quadra (python main.py).'
  if (!s.recording) return s.last_warning || 'Verifique a câmera e a RTSP_URL.'
  return `Fonte: ${s.source === 'rtsp' ? 'câmera' : 'teste'} · botoeira física: ${s.button_mode === 'desativado' ? 'desligada' : s.button_mode}`
}

const when = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '')
const size = (b) => (b / 1048576).toFixed(1).replace('.', ',') + ' MB'
const filtered = computed(() => !!(courtId.value || date.value))
</script>

<template>
  <div class="page-head">
    <div><p class="muted">{{ list.length }} {{ list.length === 1 ? 'replay' : 'replays' }}{{ filtered ? ' no filtro' : ' recentes' }}</p><h1>Replays</h1></div>
    <div class="filters">
      <select class="input" v-model="courtId">
        <option value="">Todas as quadras</option>
        <option v-for="c in cameras" :key="c.id" :value="c.id">{{ c.name }}{{ showBranch ? ` · ${c.branch_name}` : '' }}</option>
      </select>
      <input type="date" class="input" v-model="date" title="Data do replay" />
    </div>
  </div>
  <p v-if="err" class="error">{{ err }}</p>

  <h3 class="sec">Câmeras</h3>
  <div v-if="cameras.length" class="cams">
    <div v-for="c in cameras" :key="c.id" class="card cam">
      <div class="top">
        <div>
          <h2>{{ c.name }}</h2>
          <small v-if="showBranch" class="muted">{{ c.branch_name }}</small>
        </div>
        <span class="live" :class="state(c.id).cls"><i></i>{{ state(c.id).text }}</span>
      </div>
      <p class="muted small">{{ detail(c.id) }}</p>
      <button v-if="can('replays.create')" class="trigger" :disabled="busy[c.id] || !status[c.id]?.online" @click="save(c)">
        <svg viewBox="0 0 24 24" width="22" height="22"><circle cx="12" cy="12" r="7" fill="currentColor" /></svg>
        Salvar replay
      </button>
      <p v-if="notice[c.id]" class="notice" :class="notice[c.id].kind">{{ notice[c.id].text }}</p>
    </div>
  </div>
  <div v-else class="card empty">
    <p>Nenhuma quadra com câmera de replay.</p>
    <p class="muted small">
      Configure em <RouterLink v-if="can('courts.update')" to="/quadras" class="link">Quadras</RouterLink><span v-else>Quadras</span>
      → Editar → Câmera de replay.
    </p>
  </div>

  <h3 class="sec">Vídeos</h3>
  <div v-if="list.length" class="grid">
    <article v-for="r in list" :key="r.id" class="card clip" :class="{ fresh: freshIds.has(r.id) }">
      <video :src="r.video_url" controls preload="metadata" playsinline></video>
      <div class="meta">
        <div>
          <strong>{{ r.court_name }}</strong>
          <small v-if="showBranch" class="muted"> · {{ r.branch_name }}</small>
          <p class="mono muted">{{ when(r.triggered_at) }}</p>
        </div>
        <span class="badge" :class="{ confirmed: r.source === 'botoeira' }">{{ REPLAY_SOURCES[r.source] || r.source }}</span>
      </div>
      <div class="acts">
        <a class="btn sm" :href="r.download_url">Baixar <small class="muted mono">{{ size(r.size_bytes) }}</small></a>
        <button v-if="can('replays.delete')" class="btn sm danger" @click="remove(r)">Excluir</button>
      </div>
    </article>
  </div>
  <div v-else class="card empty"><p class="muted">{{ filtered ? 'Nenhum replay neste filtro.' : 'Nenhum replay ainda. Use “Salvar replay” ou a botoeira da quadra.' }}</p></div>
</template>

<style scoped>
.filters { display: flex; gap: 10px; flex-wrap: wrap; }
.filters .input { width: auto; }
.sec { font-size: 12px; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); font-weight: 500; margin: 8px 0 12px; }
.small { font-size: 13px; }
.cams { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-bottom: 32px; }
.cam { display: flex; flex-direction: column; gap: 12px; }
.top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.live { display: inline-flex; align-items: center; gap: 7px; font-size: 12px; padding: 3px 10px; border-radius: 99px; border: 1px solid var(--border); color: var(--muted); white-space: nowrap; }
.live i { width: 8px; height: 8px; border-radius: 50%; background: var(--muted); }
.live.on { color: var(--neon); border-color: rgba(180,255,31,.4); background: var(--neon-dim); }
.live.on i { background: var(--neon); box-shadow: 0 0 10px var(--neon); animation: pulse 1.6s ease-in-out infinite; }
.live.warn { color: var(--warn); border-color: rgba(255,194,51,.4); }
.live.warn i { background: var(--warn); }
.live.off { color: var(--danger); border-color: rgba(255,90,79,.4); }
.live.off i { background: var(--danger); }
@keyframes pulse { 50% { opacity: .35; } }
.trigger { display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%; padding: 16px; border-radius: 12px; border: 1px solid var(--neon); background: var(--neon); color: #000; font-weight: 700; font-size: 16px; letter-spacing: .02em; cursor: pointer; transition: box-shadow .15s, transform .1s, opacity .15s; }
.trigger svg { color: #000; }
.trigger:hover:not(:disabled) { box-shadow: 0 0 28px rgba(180, 255, 31, 0.4); }
.trigger:active:not(:disabled) { transform: translateY(1px); }
.trigger:disabled { opacity: .35; cursor: not-allowed; }
.notice { font-size: 13px; color: var(--muted); }
.notice.ok { color: var(--neon); }
.notice.error { color: var(--danger); }
.empty { margin-bottom: 32px; display: flex; flex-direction: column; gap: 6px; }
.link { color: var(--neon); }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; }
.clip { padding: 0; overflow: hidden; display: flex; flex-direction: column; transition: border-color .4s; }
.clip.fresh { border-color: var(--neon); box-shadow: 0 0 0 3px var(--neon-dim); }
.clip video { width: 100%; aspect-ratio: 16 / 9; display: block; background: #000; }
.meta { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; padding: 14px 16px 0; }
.meta p { font-size: 13px; margin-top: 4px; }
.acts { display: flex; gap: 8px; padding: 12px 16px 16px; }
@media (prefers-reduced-motion: reduce) { .live.on i { animation: none; } }
</style>
