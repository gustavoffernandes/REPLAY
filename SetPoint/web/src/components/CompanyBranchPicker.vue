<script setup>
import { ref, computed, onMounted } from 'vue'
import { auth } from '../auth'

// v-model = id da filial. A filial só lista as da empresa escolhida.
// Quem tem uma única empresa (não admin) vê a empresa fixa; se há uma filial selecionada na lateral, ela também fica fixa.
const props = defineProps({ modelValue: { type: Number, default: null } })
const emit = defineEmits(['update:modelValue'])

const scoped = computed(() => auth.branches.filter((b) => b.active && (!auth.branchId || b.id === auth.branchId)))
const companyList = computed(() => {
  const map = new Map()
  scoped.value.forEach((b) => map.set(b.company_id, { id: b.company_id, name: b.company_name }))
  return [...map.values()]
})
const companyId = ref(null)
const branchList = computed(() => scoped.value.filter((b) => b.company_id === companyId.value))

onMounted(() => {
  const current = scoped.value.find((b) => b.id === props.modelValue)
  companyId.value = current?.company_id ?? companyList.value[0]?.id ?? null
  if (!current) emit('update:modelValue', branchList.value[0]?.id ?? null)
})

// Trocar a empresa seleciona a primeira filial dela
function onCompany() {
  emit('update:modelValue', branchList.value[0]?.id ?? null)
}
</script>

<template>
  <div class="row">
    <div class="field">
      <label>Empresa</label>
      <select class="input" v-model.number="companyId" :disabled="companyList.length < 2" required @change="onCompany">
        <option v-for="c in companyList" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
    </div>
    <div class="field">
      <label>Filial</label>
      <select class="input" :value="modelValue" :disabled="branchList.length < 2" required @change="emit('update:modelValue', Number($event.target.value))">
        <option v-for="b in branchList" :key="b.id" :value="b.id">{{ b.name }}</option>
      </select>
    </div>
  </div>
</template>
