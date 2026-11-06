// Espelha a política do back-end (App\Rules\StrongPassword). O back-end é quem decide; aqui é só orientação ao usuário.
export const MIN = 10
export const MAX = 64
const COMMON = ['password', 'senha', 'qwerty', '12345678', '123456789', 'abcdefgh', 'admin', 'setpoint', 'letmein', 'iloveyou']

export function checkPassword(pw = '', email = '') {
  const lower = pw.toLowerCase()
  const local = email.includes('@') ? email.split('@')[0].toLowerCase() : ''
  return [
    { id: 'len', label: `Pelo menos ${MIN} caracteres`, ok: pw.length >= MIN && pw.length <= MAX },
    { id: 'lower', label: 'Uma letra minúscula', ok: /\p{Ll}/u.test(pw) },
    { id: 'upper', label: 'Uma letra maiúscula', ok: /\p{Lu}/u.test(pw) },
    { id: 'digit', label: 'Um número', ok: /\d/.test(pw) },
    { id: 'symbol', label: 'Um símbolo (! @ # $ %)', ok: /[^\p{L}\d]/u.test(pw) },
    {
      id: 'guess',
      label: 'Sem palavras comuns, repetições ou seu e-mail',
      ok: pw.length > 0 && !COMMON.some((w) => lower.includes(w)) && !/(.)\1{3,}/u.test(pw) && !(local.length >= 4 && lower.includes(local)),
    },
  ]
}

export const isStrong = (pw, email = '') => checkPassword(pw, email).every((r) => r.ok)

/** 0 a 4, para o medidor visual. */
export function strength(pw, email = '') {
  if (!pw) return 0
  const r = checkPassword(pw, email)
  const ok = r.filter((x) => x.ok).length
  if (ok === r.length) return pw.length >= 14 ? 4 : 3
  return Math.min(2, Math.ceil((ok / r.length) * 2))
}
export const STRENGTH_LABEL = ['', 'Fraca', 'Média', 'Forte', 'Muito forte']
