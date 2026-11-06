<script setup>
import { ref, computed, onMounted } from 'vue'
import { api, money, hh, fmtDate, SPORTS, STATUS } from '../api'
import { can } from '../auth'

const props = defineProps({ id: Number })
const emit = defineEmits(['close', 'changed'])

const b = ref(null)
const err = ref('')
const busy = ref(false)

onMounted(async () => {
  try { b.value = await api.booking(props.id) } catch (e) { err.value = e.message }
})

const origin = computed(() => (b.value?.source === 'online' && b.value?.origin === 'single' ? 'Reserva online' : { class: 'Aula de turma', fixed: 'Horário fixo (recorrente)', single: 'Reserva no balcão' }[b.value?.origin]))
const awaiting = computed(() => b.value?.status === 'awaiting_payment')
const remaining = computed(() => Math.max(0, Number(b.value?.price || 0) - Number(b.value?.paid_amount || 0)))
const who = computed(() => b.value?.class_group?.name ?? b.value?.customer?.name)
const actions = computed(() => {
  if (!b.value) return []
  return [
    can('bookings.confirm') && awaiting.value && { status: 'confirmed', label: 'Confirmar sinal recebido', primary: true },
    can('bookings.confirm') && b.value.status === 'pending' && { status: 'confirmed', label: 'Confirmar', primary: true },
    can('bookings.confirm') && b.value.status === 'confirmed' && { status: 'completed', label: 'Marcar como concluída', primary: true },
    can('bookings.cancel') && ['awaiting_payment', 'pending', 'confirmed'].includes(b.value.status) && { status: 'cancelled', label: 'Cancelar reserva', danger: true },
  ].filter(Boolean)
})

async function act(a) {
  if (a.status === 'cancelled' && !confirm('Cancelar esta reserva?')) return
  busy.value = true
  err.value = ''
  try {
    await api.setStatus(b.value.id, a.status)
    emit('changed')
  } catch (e) {
    err.value = e.message
    busy.value = false
  }
}
</script>

<template>
  <div class="modal-back" @mousedown.self="emit('close')">
    <div class="modal">
      <p v-if="err" class="error">{{ err }}</p>
      <p v-if="!b && !err" class="muted">Carregando…</p>
      <template v-if="b">
        <div class="head">
          <div>
            <h2>{{ who }}</h2>
            <p class="muted">{{ origin }}</p>
          </div>
          <span class="badge" :class="b.status">{{ STATUS[b.status] }}</span>
        </div>

        <dl>
          <div><dt>Esporte</dt><dd>{{ SPORTS[b.sport] }}</dd></div>
          <div><dt>Quadra</dt><dd>{{ b.court.name }}</dd></div>
          <div><dt>Data</dt><dd class="cap">{{ fmtDate(b.booking_date, { weekday: 'long', day: 'numeric', month: 'long' }) }}</dd></div>
          <div><dt>Horário</dt><dd class="mono">{{ hh(b.start_hour) }} – {{ hh(b.end_hour) }}</dd></div>
          <div v-if="b.class_group?.instructor"><dt>Professor</dt><dd>{{ b.class_group.instructor }}</dd></div>
          <div v-if="b.customer"><dt>Telefone</dt><dd class="mono">{{ b.customer.phone }}</dd></div>
          <div v-if="b.customer?.email"><dt>E-mail</dt><dd>{{ b.customer.email }}</dd></div>
          <div><dt>Valor</dt><dd class="mono price">{{ b.class_group ? 'Incluso na mensalidade' : money(b.price) }}</dd></div>
          <template v-if="b.source === 'online' && !b.class_group">
            <div><dt>Sinal pago</dt><dd class="mono">{{ money(b.paid_amount) }}</dd></div>
            <div><dt>Restante no balcão</dt><dd class="mono" :class="{ price: remaining > 0 }">{{ money(remaining) }}</dd></div>
            <div v-if="b.public_code"><dt>Código</dt><dd class="mono">{{ b.public_code }}</dd></div>
          </template>
          <div v-if="b.notes"><dt>Observações</dt><dd>{{ b.notes }}</dd></div>
        </dl>

        <p v-if="err" class="error">{{ err }}</p>
        <div class="foot">
          <button class="btn" @click="emit('close')">Fechar</button>
          <div class="acts">
            <button v-for="a in actions" :key="a.status" class="btn" :class="{ primary: a.primary, danger: a.danger }" :disabled="busy" @click="act(a)">{{ a.label }}</button>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 18px; }
dl { display: flex; flex-direction: column; border-top: 1px solid var(--border); }
dl > div { display: flex; justify-content: space-between; gap: 16px; padding: 11px 0; border-bottom: 1px solid var(--border); }
dt { color: var(--muted); font-size: 13px; }
dd { text-align: right; }
.price { color: var(--neon); }
.foot { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-top: 20px; flex-wrap: wrap; }
.acts { display: flex; gap: 8px; flex-wrap: wrap; }
</style>
