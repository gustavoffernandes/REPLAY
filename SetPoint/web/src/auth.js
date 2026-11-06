import { reactive, computed } from 'vue'

const TOKEN_KEY = 'setpoint.token'
const BRANCH_KEY = 'setpoint.branch'

const store = {
  get: (k) => { try { return localStorage.getItem(k) } catch { return null } },
  set: (k, v) => { try { v == null ? localStorage.removeItem(k) : localStorage.setItem(k, v) } catch { /* sem storage */ } },
}

export const auth = reactive({
  token: store.get(TOKEN_KEY),
  user: null,
  permissions: [],
  branches: [], // filiais acessíveis (com company_name)
  branchId: Number(store.get(BRANCH_KEY)) || null, // filial selecionada; null = todas
})

export const can = (permission) => auth.permissions.includes('*') || auth.permissions.includes(permission)

/** Empresas distintas entre as filiais acessíveis. */
export const companies = computed(() => {
  const map = new Map()
  auth.branches.forEach((b) => map.set(b.company_id, { id: b.company_id, name: b.company_name }))
  return [...map.values()]
})

export const currentBranch = computed(() => auth.branches.find((b) => b.id === auth.branchId) ?? null)

/** Mostra o nome da filial nas listas quando há mais de uma no escopo atual. */
export const showBranch = computed(() => !auth.branchId && auth.branches.length > 1)

export function setToken(token) {
  auth.token = token
  store.set(TOKEN_KEY, token)
}

export function applySession(payload) {
  auth.user = payload.user
  auth.permissions = payload.permissions
  auth.branches = payload.branches
  // Descarta uma filial salva que o usuário não acessa mais
  if (auth.branchId && !payload.branches.some((b) => b.id === auth.branchId)) setBranch(null)
  // Quem tem uma única filial já fica nela
  if (!auth.branchId && payload.branches.length === 1) setBranch(payload.branches[0].id)
}

export function setBranch(id) {
  auth.branchId = id ? Number(id) : null
  store.set(BRANCH_KEY, auth.branchId)
}

export function clearSession() {
  auth.token = null
  auth.user = null
  auth.permissions = []
  auth.branches = []
  store.set(TOKEN_KEY, null)
}
