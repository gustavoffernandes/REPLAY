<script setup>
import { ref, watch, nextTick } from 'vue'
import { loading } from '../loading'

const box = ref(null)

// Ao bloquear, tira o foco do botão clicado: um Enter/Espaço seguinte não reenvia o formulário.
watch(() => loading.writes, async (n, old) => {
  if (n > 0 && old === 0) {
    document.activeElement?.blur?.()
    await nextTick()
    box.value?.focus()
  }
})
</script>

<template>
  <!-- Consultas: barra fina no topo, sem bloquear -->
  <div v-if="loading.reads > 0" class="bar" role="progressbar" aria-label="Carregando"><i></i></div>

  <!-- Criar/editar/excluir: bloqueia a tela até terminar (impede duplo clique) -->
  <div v-if="loading.writes > 0" ref="box" class="block" tabindex="-1" role="alertdialog" aria-modal="true" aria-live="assertive" aria-busy="true" aria-label="Processando">
    <div class="card">
      <span class="spin" aria-hidden="true"></span>
      <strong>{{ loading.label }}</strong>
      <small>Aguarde um instante</small>
    </div>
  </div>
</template>

<style scoped>
.bar { position: fixed; top: 0; left: 0; right: 0; height: 3px; z-index: 120; overflow: hidden; pointer-events: none; opacity: 0; animation: appear .1s .2s forwards; }
.bar i { display: block; height: 100%; width: 40%; background: var(--neon); box-shadow: 0 0 10px var(--neon); border-radius: 3px; animation: slide 1.1s ease-in-out infinite; }
.block { position: fixed; inset: 0; z-index: 130; display: grid; place-items: center; background: rgba(0, 0, 0, 0); cursor: progress; outline: none; animation: dim .2s .15s forwards; }
/* o bloqueio de cliques é imediato; o aviso visual só aparece se a ação demorar (evita "piscar") */
.card { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 24px 32px; min-width: 190px; background: var(--surface); border: 1px solid var(--border); border-radius: 16px; box-shadow: 0 24px 60px rgba(0, 0, 0, .6); opacity: 0; transform: scale(.96); animation: pop .2s .2s forwards; }
.card small { color: var(--muted); font-size: 12px; }
.spin { width: 30px; height: 30px; border-radius: 50%; border: 3px solid var(--border); border-top-color: var(--neon); animation: rot .8s linear infinite; margin-bottom: 4px; }
@keyframes rot { to { transform: rotate(360deg); } }
@keyframes slide { from { transform: translateX(-100%); } to { transform: translateX(260%); } }
@keyframes appear { to { opacity: 1; } }
@keyframes dim { to { background: rgba(0, 0, 0, .55); backdrop-filter: blur(2px); } }
@keyframes pop { to { opacity: 1; transform: scale(1); } }
@media (prefers-reduced-motion: reduce) { .spin, .bar i { animation-duration: 2.4s; } }
</style>
