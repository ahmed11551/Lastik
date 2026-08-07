/**
 * AUTOMETRIA ERP — Decimal string math (BCMath-style, no binary float).
 * Used by GS1 AI 37 quantities and offline stock sync payloads.
 */

export function normalizeDecimalInput(raw: string): string {
  let s = String(raw ?? '').trim().replace(/\s+/g, '').replace(',', '.')
  if (!s || s === '-' || s === '+' || s === '.') return '0'
  const neg = s.startsWith('-')
  if (neg || s.startsWith('+')) s = s.slice(1)
  if (!/^\d+(\.\d+)?$/.test(s)) return '0'
  if (s.includes('.')) {
    const [i, f] = s.split('.')
    s = `${i.replace(/^0+(?=\d)/, '') || '0'}.${f}`
  } else {
    s = s.replace(/^0+(?=\d)/, '') || '0'
  }
  if (neg && s !== '0') return `-${s}`
  return s
}

function toScaledBigInt(raw: string, scale: number): { value: bigint; neg: boolean } {
  const n = normalizeDecimalInput(raw)
  const neg = n.startsWith('-')
  const body = neg ? n.slice(1) : n
  const [intPart, fracPart = ''] = body.split('.')
  const digits = `${intPart}${fracPart.padEnd(scale, '0').slice(0, scale)}`
  return { value: BigInt(digits || '0'), neg }
}

function fromScaledBigInt(value: bigint, scale: number, neg: boolean): string {
  const abs = value < 0n ? -value : value
  const raw = abs.toString().padStart(scale + 1, '0')
  const intPart = raw.slice(0, raw.length - scale) || '0'
  const fracPart = scale > 0 ? raw.slice(raw.length - scale) : ''
  let out = scale > 0 ? `${intPart}.${fracPart}` : intPart
  // trim trailing zeros in fraction only
  if (scale > 0) out = out.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '')
  if ((neg || value < 0n) && out !== '0') out = `-${out.replace(/^-/, '')}`
  return out
}

/** Add two decimal strings exactly. */
export function decimalAdd(a: string, b: string): string {
  const scale = Math.max(
    (normalizeDecimalInput(a).split('.')[1] || '').length,
    (normalizeDecimalInput(b).split('.')[1] || '').length,
  )
  const A = toScaledBigInt(a, scale)
  const B = toScaledBigInt(b, scale)
  let av = A.neg ? -A.value : A.value
  let bv = B.neg ? -B.value : B.value
  const sum = av + bv
  return fromScaledBigInt(sum < 0n ? -sum : sum, scale, sum < 0n)
}

/** Subtract b from a (a − b) using exact decimal strings. */
export function decimalSub(a: string, b: string): string {
  const nb = normalizeDecimalInput(b)
  if (nb === '0') return normalizeDecimalInput(a)
  return decimalAdd(a, nb.startsWith('-') ? nb.slice(1) : `-${nb}`)
}

/** Absolute value as decimal string. */
export function decimalAbs(a: string): string {
  const n = normalizeDecimalInput(a)
  return n.startsWith('-') ? n.slice(1) : n
}

/**
 * Normalize quantity for GS1 AI 37 / stock payloads.
 * Keeps exact digit string (e.g. "10.500"), never Number().
 */
export function normalizeQuantityString(raw: string | null | undefined): string | null {
  if (raw == null) return null
  const s = String(raw).trim()
  if (!s) return null
  const asDot = s.replace(',', '.')
  if (/^-?\d+(\.\d+)?$/.test(asDot)) {
    return asDot
  }
  return normalizeDecimalInput(s)
}
