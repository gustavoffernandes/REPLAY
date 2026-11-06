import { reactive } from 'vue'

// Estado global das requisições em andamento (alimentado por api.js).
// writes: criações/edições/exclusões (bloqueiam a tela); reads: consultas (só uma barra de progresso).
export const loading = reactive({ writes: 0, reads: 0, label: 'Salvando…' })
