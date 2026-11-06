import { auth, clearSession } from './auth'
import { loading } from './loading'

// Criações/edições/exclusões idênticas em andamento: um segundo disparo (duplo clique, Enter repetido)
// reaproveita a mesma requisição em vez de enviar outra.
const inflight = new Map()

function request(path, opts = {}) {
  const method = (opts.method || 'GET').toUpperCase()
  const isWrite = method !== 'GET'
  const isPublic = path.startsWith('/public/') // agendamento online: sem sessão

  if (isWrite) {
    const key = `${method} ${path} ${JSON.stringify(opts.body ?? null)}`
    if (inflight.has(key)) return inflight.get(key)
    const p = send(path, opts, method, isWrite, isPublic).finally(() => inflight.delete(key))
    inflight.set(key, p)
    return p
  }
  return send(path, opts, method, isWrite, isPublic)
}

const writeLabel = (method, path) => (path.startsWith('/auth/login') ? 'Entrando…' : method === 'DELETE' ? 'Removendo…' : path.startsWith('/auth/') ? 'Processando…' : 'Salvando…')

async function send(path, opts, method, isWrite, isPublic) {
  const headers = { 'Content-Type': 'application/json', Accept: 'application/json' }
  if (auth.token && !isPublic) headers.Authorization = `Bearer ${auth.token}`
  // Escopo de filial (somente rotas de dados; a API valida se o usuário tem acesso)
  if (auth.branchId && !isPublic && !path.startsWith('/auth/')) headers['X-Branch-Id'] = String(auth.branchId)

  // Indicador global: escrita bloqueia a tela; leitura mostra só a barra (menos as consultas públicas
  // e as atualizações automáticas em segundo plano, marcadas com silent)
  const track = isWrite ? 'writes' : isPublic || opts.silent ? null : 'reads'
  if (track) {
    if (isWrite) loading.label = writeLabel(method, path)
    loading[track]++
  }
  try {
    const res = await fetch('/api' + path, {
      method,
      headers,
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    })
    const data = await res.json().catch(() => ({}))

    if (res.status === 401 && !isPublic && !path.startsWith('/auth/')) {
      clearSession()
      if (location.pathname !== '/login') location.assign('/login')
    }
    if (!res.ok) {
      let message = data.error || data.message || 'Erro na requisição'
      if (res.status === 429 && /too many/i.test(message)) {
        const wait = Number(res.headers.get('Retry-After')) || 0
        message = `Muitas requisições em pouco tempo. Aguarde${wait ? ` ${wait}s` : ' um instante'} e tente novamente.`
      }
      const err = new Error(message)
      err.status = res.status
      err.errors = data.errors
      throw err
    }
    return data
  } finally {
    if (track) loading[track]--
  }
}

const save = (base, item) => (item.id ? request(`${base}/${item.id}`, { method: 'PUT', body: item }) : request(base, { method: 'POST', body: item }))

export const api = {
  // sessão
  login: (email, password) => request('/auth/login', { method: 'POST', body: { email, password } }),
  me: () => request('/auth/me'),
  forgotPassword: (email) => request('/auth/forgot-password', { method: 'POST', body: { email } }),
  resetPassword: (b) => request('/auth/reset-password', { method: 'POST', body: b }),
  changePassword: (b) => request('/auth/password', { method: 'POST', body: b }),
  logout: () => request('/auth/logout', { method: 'POST' }),

  // operação
  dashboard: () => request('/dashboard'),
  courts: () => request('/courts'),
  saveCourt: (c) => save('/courts', c),
  availability: (date) => request(`/availability?date=${date}`),
  bookings: (params = {}) => request('/bookings?' + new URLSearchParams(params)),
  createBooking: (b) => request('/bookings', { method: 'POST', body: b }),
  booking: (id) => request(`/bookings/${id}`),
  customers: (params = {}) => request('/customers?' + new URLSearchParams(params)),
  customer: (id) => request(`/customers/${id}`),
  saveCustomer: (c) => save('/customers', c),
  addCustomerSchedule: (id, s) => request(`/customers/${id}/schedules`, { method: 'POST', body: s }),
  removeSchedule: (id) => request(`/schedules/${id}`, { method: 'DELETE' }),
  classes: () => request('/classes'),
  classGroup: (id) => request(`/classes/${id}`),
  saveClass: (c) => save('/classes', c),
  deleteClass: (id) => request(`/classes/${id}`, { method: 'DELETE' }),
  addStudent: (id, customer_id) => request(`/classes/${id}/students`, { method: 'POST', body: { customer_id } }),
  removeStudent: (id, customerId) => request(`/classes/${id}/students/${customerId}`, { method: 'DELETE' }),
  addClassSchedule: (id, s) => request(`/classes/${id}/schedules`, { method: 'POST', body: s }),
  setStatus: (id, status) => request(`/bookings/${id}/status`, { method: 'PATCH', body: { status } }),

  // replays (câmera por quadra)
  replays: (params = {}, silent = false) => request('/replays?' + new URLSearchParams(params), { silent }),
  replayCameras: () => request('/replay-cameras'),
  replayStatus: (courtId) => request(`/courts/${courtId}/replay-status`, { silent: true }),
  saveReplay: (courtId) => request(`/courts/${courtId}/replays`, { method: 'POST' }),
  deleteReplay: (id) => request(`/replays/${id}`, { method: 'DELETE' }),
  rotateReplayToken: (courtId) => request(`/courts/${courtId}/replay-token`, { method: 'POST' }),

  // agendamento online (público)
  publicCompany: (slug) => request(`/public/companies/${slug}`),
  publicAvailability: (branchId, date) => request(`/public/branches/${branchId}/availability?date=${date}`),
  publicBook: (branchId, b) => request(`/public/branches/${branchId}/bookings`, { method: 'POST', body: b }),
  publicOrder: (code) => request(`/public/bookings/${code}`),
  publicSimulatePayment: (code) => request(`/public/bookings/${code}/simulate-payment`, { method: 'POST' }),

  // administração
  roles: () => request('/roles'),
  assignableRoles: () => request('/roles/assignable'),
  saveRole: (r) => save('/roles', r),
  deleteRole: (id) => request(`/roles/${id}`, { method: 'DELETE' }),
  companies: () => request('/companies'),
  saveCompany: (c) => save('/companies', c),
  branches: () => request('/branches'),
  saveBranch: (b) => save('/branches', b),
  users: () => request('/users'),
  saveUser: (u) => save('/users', u),
}

export const SPORTS = { volei: 'Vôlei', futvolei: 'Futevôlei', beachtenis: 'Beach Tennis' }
export const WEEKDAYS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado']
export const REPLAY_SOURCES = { site: 'Botoeira virtual', botoeira: 'Botoeira', 'botoeira-simulada': 'Simulação' }
export const STATUS = { awaiting_payment: 'Aguardando sinal', confirmed: 'Confirmada', pending: 'Pendente', cancelled: 'Cancelada', completed: 'Concluída' }
export const money = (v) => Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
export const hh = (h) => String(h).padStart(2, '0') + ':00'
export const toISO = (d) => {
  const z = new Date(d.getTime() - d.getTimezoneOffset() * 60000)
  return z.toISOString().slice(0, 10)
}
export const fmtDate = (iso, opts = { day: '2-digit', month: 'short' }) =>
  new Date(iso + 'T12:00:00').toLocaleDateString('pt-BR', opts).replace('.', '')
