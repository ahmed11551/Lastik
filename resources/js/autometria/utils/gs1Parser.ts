/**
 * AUTOMETRIA ERP — GS1 / DataMatrix parser (SRP module).
 *
 * Parses GS1-128 / DataMatrix / QR payloads into structured Application
 * Identifiers (AI). Supported AIs:
 *   01  GTIN-14
 *   10  Batch / Lot number
 *   11  Production date (YYMMDD)
 *   17  Expiration date (YYMMDD)
 *   21  Serial number
 *   37  Count / quantity (variable, may be fractional — string / BCMath-safe)
 *
 * Precision note: numeric quantities (AI 37) are returned as strings, never
 * floats, so downstream BCMath / decimalString math stays exact. Dates are
 * normalised to ISO (YYYY-MM-DD) using GS1 YYMMDD rules (YY <= 49 -> 20YY, else 19YY).
 *
 * Input may use either parenthesis-grouped form  "(01)046...(21)AB12"
 * or FNC1-delimited form with a separator char (default GS \x1d).
 */
import { normalizeQuantityString } from './decimalString'

export interface Gs1Field {
  ai: string
  label: string
  value: string
}

export interface ParsedGs1 {
  gtin: string | null
  batch: string | null
  serial: string | null
  /** Exact decimal string (AI 37) — never a JS number/float */
  quantity: string | null
  productionDate: string | null // ISO YYYY-MM-DD
  expirationDate: string | null // ISO YYYY-MM-DD
  fields: Gs1Field[]
  raw: string
}

const AI_META: Record<string, { label: string; fixed?: number }> = {
  '01': { label: 'GTIN', fixed: 14 },
  '10': { label: 'Batch/Lot' },
  '11': { label: 'Production Date', fixed: 6 },
  '17': { label: 'Expiration Date', fixed: 6 },
  '21': { label: 'Serial' },
  '37': { label: 'Quantity' },
}

/** GS (FNC1) separator char. */
const GS = '\u001d'

function normalizeDate(yymmdd: string): string | null {
  if (!/^\d{6}$/.test(yymmdd)) return null
  const yy = parseInt(yymmdd.slice(0, 2), 10)
  const mm = yymmdd.slice(2, 4)
  const dd = yymmdd.slice(4, 6)
  const year = yy <= 49 ? 2000 + yy : 1900 + yy
  const d = new Date(year, parseInt(mm, 10) - 1, parseInt(dd, 10))
  if (
    d.getFullYear() !== year ||
    d.getMonth() + 1 !== parseInt(mm, 10) ||
    d.getDate() !== parseInt(dd, 10)
  ) {
    return null
  }
  return `${year}-${mm}-${dd}`
}

/**
 * Strip FNC1 / GS separators and convert to parenthesis-grouped form so the
 * parser has a single canonical shape to walk.
 */
function toGroupedForm(raw: string, _separator: string): string {
  const cleaned = raw.replace(/[\u001d]/g, '|')
  // Already parenthesis-grouped.
  if (cleaned.includes('(')) return cleaned
  // FNC1/GS-delimited: parts alternate as AI, value, AI, value...
  const parts = cleaned.split('|').filter(Boolean)
  if (parts.length <= 1) return raw
  const out: string[] = []
  for (let i = 0; i < parts.length; i += 2) {
    const ai = parts[i]
    const value = parts[i + 1] ?? ''
    if (/^\d{2}$/.test(ai)) out.push(`(${ai})${value}`)
    else out.push(parts[i])
  }
  return out.join('')
}

function coerceFieldValue(ai: string, rawValue: string): string {
  let value = rawValue.replace(/[|]+$/g, '')
  const meta = AI_META[ai]
  if (meta?.fixed && value.length > meta.fixed) {
    value = value.slice(0, meta.fixed)
  }
  if (ai === '37') {
    return normalizeQuantityString(value) ?? value
  }
  return value
}

export function parseGs1(input: string, separator: string = GS): ParsedGs1 {
  const raw = input.trim()
  const grouped = toGroupedForm(raw, separator)

  // Bare EAN/UPC (no AI groupings): treat pure 8–14 digit string as GTIN.
  if (!grouped.includes('(') && /^\d{8,14}$/.test(raw)) {
    return {
      gtin: raw,
      batch: null,
      serial: null,
      quantity: null,
      productionDate: null,
      expirationDate: null,
      fields: [{ ai: '01', label: 'GTIN', value: raw }],
      raw,
    }
  }

  const fields: Gs1Field[] = []
  const re = /\((\d{2})\)([^(]*)/g
  let match: RegExpExecArray | null
  while ((match = re.exec(grouped)) !== null) {
    const ai = match[1]
    const value = coerceFieldValue(ai, match[2])
    const meta = AI_META[ai]
    if (!meta) {
      fields.push({ ai, label: `AI-${ai}`, value })
      continue
    }
    fields.push({ ai, label: meta.label, value })
  }

  const get = (ai: string): string | null => {
    const f = fields.find((x) => x.ai === ai)
    return f && f.value.length > 0 ? f.value : null
  }

  return {
    gtin: get('01'),
    batch: get('10'),
    serial: get('21'),
    quantity: get('37'),
    productionDate: normalizeDate(get('11') ?? ''),
    expirationDate: normalizeDate(get('17') ?? ''),
    fields,
    raw,
  }
}

export function extractGtin(input: string, separator: string = GS): string | null {
  return parseGs1(input, separator).gtin
}

export function extractQuantity(input: string, separator: string = GS): string | null {
  return parseGs1(input, separator).quantity
}

export { normalizeQuantityString }
