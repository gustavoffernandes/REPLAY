<script setup>
import { ref, computed } from 'vue'
import { checkPassword, strength, STRENGTH_LABEL } from '../passwordRules'

// Campo de senha com botão mostrar/ocultar e, opcionalmente, medidor de força + requisitos (meter).
const props = defineProps({
  modelValue: { type: String, default: '' },
  label: { type: String, default: 'Senha' },
  email: { type: String, default: '' },
  meter: Boolean,
  required: Boolean,
  autocomplete: { type: String, default: 'new-password' },
  placeholder: { type: String, default: '' },
  autofocus: Boolean,
})
const emit = defineEmits(['update:modelValue'])

const show = ref(false)
const rules = computed(() => checkPassword(props.modelValue, props.email))
const level = computed(() => strength(props.modelValue, props.email))
</script>

<template>
  <div class="field">
    <label>{{ label }}<slot name="aside" /></label>
    <div class="wrap">
      <input class="input" :type="show ? 'text' : 'password'" :value="modelValue" :required="required" :autocomplete="autocomplete"
             :placeholder="placeholder" :autofocus="autofocus" maxlength="255" @input="emit('update:modelValue', $event.target.value)" />
      <button type="button" class="eye" :aria-label="show ? 'Ocultar senha' : 'Mostrar senha'" :aria-pressed="show" tabindex="-1" @click="show = !show">
        <svg v-if="!show" viewBox="0 0 24 24" width="18" height="18"><path d="M12 5c-5 0-9 4.5-10 7 1 2.5 5 7 10 7s9-4.5 10-7c-1-2.5-5-7-10-7zm0 11.5a4.5 4.5 0 1 1 0-9 4.5 4.5 0 0 1 0 9zm0-7a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z" fill="currentColor" /></svg>
        <svg v-else viewBox="0 0 24 24" width="18" height="18"><path d="M2.4 3.8 3.8 2.4l17.8 17.8-1.4 1.4-3.2-3.2A10.8 10.8 0 0 1 12 19C7 19 3 14.5 2 12c.6-1.5 2-3.3 3.9-4.8L2.4 3.8zM12 5c5 0 9 4.5 10 7-.5 1.2-1.4 2.6-2.7 3.9l-2.9-2.9a4.5 4.5 0 0 0-5.8-5.8L8.4 5.5C9.5 5.2 10.7 5 12 5z" fill="currentColor" /></svg>
      </button>
    </div>

    <div v-if="meter && modelValue" class="meter">
      <div class="segs" :data-level="level"><i v-for="n in 4" :key="n" :class="{ on: n <= level }"></i></div>
      <small class="lvl" :data-level="level">{{ STRENGTH_LABEL[level] }}</small>
    </div>
    <ul v-if="meter" class="req">
      <li v-for="r in rules" :key="r.id" :class="{ ok: r.ok }">
        <span aria-hidden="true">{{ r.ok ? '✓' : '○' }}</span>{{ r.label }}
      </li>
    </ul>
  </div>
</template>

<style scoped>
.field { margin-bottom: 14px; }
.wrap { position: relative; }
.wrap .input { padding-right: 44px; }
.eye { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); width: 34px; height: 34px; border: none; background: transparent; color: var(--muted); border-radius: 8px; cursor: pointer; display: grid; place-items: center; }
.eye:hover { color: var(--text); background: var(--surface-2); }
.meter { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
.segs { flex: 1; display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px; }
.segs i { height: 5px; border-radius: 4px; background: var(--surface-2); transition: background .2s; }
.segs[data-level='1'] i.on { background: var(--danger); }
.segs[data-level='2'] i.on { background: var(--warn); }
.segs[data-level='3'] i.on, .segs[data-level='4'] i.on { background: var(--neon); }
.lvl { font-size: 12px; min-width: 70px; text-align: right; color: var(--muted); }
.lvl[data-level='1'] { color: var(--danger); }
.lvl[data-level='2'] { color: var(--warn); }
.lvl[data-level='3'], .lvl[data-level='4'] { color: var(--neon); }
.req { list-style: none; display: grid; grid-template-columns: 1fr 1fr; gap: 4px 12px; margin-top: 10px; font-size: 12px; color: var(--muted); }
.req li { display: flex; gap: 6px; align-items: baseline; text-transform: none; letter-spacing: 0; }
.req li.ok { color: var(--neon); }
@media (max-width: 520px) { .req { grid-template-columns: 1fr; } }
</style>
