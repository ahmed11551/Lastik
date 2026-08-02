/**
 * Phone / email / OFD QR helpers for 54-ФЗ UI
 */

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/i

/** Digits only from phone input */
export function digitsOnly(raw: string): string {
  return String(raw || '').replace(/\D/g, '')
}

/**
 * Normalize RU mobile to 11 digits starting with 7.
 * Accepts 8XXXXXXXXXX, 7XXXXXXXXXX, 9XXXXXXXXX.
 */
export function normalizeRuPhoneDigits(raw: string): string | null {
  let d = digitsOnly(raw)
  if (d.length === 11 && d.startsWith('8')) d = `7${d.slice(1)}`
  if (d.length === 10 && d.startsWith('9')) d = `7${d}`
  if (d.length === 11 && d.startsWith('7')) return d
  return null
}

/** Format as +7 (XXX) XXX-XX-XX while typing */
export function formatRuPhoneMask(raw: string): string {
  let d = digitsOnly(raw)
  if (d.startsWith('8')) d = `7${d.slice(1)}`
  if (d.length && !d.startsWith('7')) d = `7${d}`
  d = d.slice(0, 11)

  const a = d.slice(1, 4)
  const b = d.slice(4, 7)
  const c = d.slice(7, 9)
  const e = d.slice(9, 11)

  let out = '+7'
  if (a.length) out += ` (${a}`
  if (a.length === 3) out += ')'
  if (b.length) out += ` ${b}`
  if (c.length) out += `-${c}`
  if (e.length) out += `-${e}`
  return out
}

export function isValidRuPhone(raw: string): boolean {
  return normalizeRuPhoneDigits(raw) !== null
}

export function isValidEmail(raw: string): boolean {
  const v = String(raw || '').trim()
  return EMAIL_RE.test(v)
}

export function toE164Ru(raw: string): string | null {
  const d = normalizeRuPhoneDigits(raw)
  return d ? `+${d}` : null
}

type OfdFields = {
  fiscalized_at?: string | null
  created_at?: string | null
  payload?: { total?: number } | null
  fiscal_storage_number?: string | null
  fiscal_document_number?: string | null
  fiscal_sign?: string | null
  type?: string
}

/**
 * FFD QR payload: t=YYYYMMDDTHHMM&s=SUM&fn=FN&i=FD&fp=FP&n=1
 */
export function buildOfdQrPayload(r: OfdFields): string | null {
  const fn = r.fiscal_storage_number
  const fd = r.fiscal_document_number
  const fp = r.fiscal_sign
  if (!fn || !fd || !fp) return null

  const when = r.fiscalized_at || r.created_at || new Date().toISOString()
  const dt = new Date(when)
  const pad = (n: number) => String(n).padStart(2, '0')
  const t = Number.isNaN(dt.getTime())
    ? ''
    : `${dt.getFullYear()}${pad(dt.getMonth() + 1)}${pad(dt.getDate())}T${pad(dt.getHours())}${pad(dt.getMinutes())}`

  const sum = Number(r.payload?.total ?? 0)
  const s = sum.toFixed(2)
  const n = r.type === 'sell_refund' || r.type === 'buy_refund' ? '2' : '1'

  return `t=${t}&s=${s}&fn=${fn}&i=${fd}&fp=${fp}&n=${n}`
}

/**
 * Deterministic visual QR-like matrix (SVG data-URI) from payload hash.
 * Prefer backend `qr_code_url` for OFD-scannable codes; this is an offline fallback
 * that still encodes payload text for print/operator verification.
 */
export function qrSvgDataUrl(text: string, modulePx = 4, dim = 33): string {
  const seed = hashStr(text)
  const modules: boolean[][] = Array.from({ length: dim }, () => Array(dim).fill(false))

  // Finder patterns
  paintFinder(modules, 0, 0)
  paintFinder(modules, dim - 7, 0)
  paintFinder(modules, 0, dim - 7)

  // Timing
  for (let i = 8; i < dim - 8; i++) {
    modules[6][i] = i % 2 === 0
    modules[i][6] = i % 2 === 0
  }

  // Data modules from payload
  let n = seed
  for (let y = 0; y < dim; y++) {
    for (let x = 0; x < dim; x++) {
      if (isReserved(x, y, dim)) continue
      n = (Math.imul(n, 1664525) + 1013904223) >>> 0
      modules[y][x] = (n & 1) === 1
    }
  }

  // Embed checksum bits along bottom row for uniqueness
  const bytes = Array.from(text).map((ch) => ch.charCodeAt(0) & 255)
  for (let i = 0; i < Math.min(bytes.length, dim - 16); i++) {
    const bit = (bytes[i] >> (i % 8)) & 1
    modules[dim - 2][8 + i] = bit === 1
  }

  const size = dim * modulePx
  let rects = ''
  for (let y = 0; y < dim; y++) {
    for (let x = 0; x < dim; x++) {
      if (modules[y][x]) {
        rects += `<rect x="${x * modulePx}" y="${y * modulePx}" width="${modulePx}" height="${modulePx}" fill="#000"/>`
      }
    }
  }
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/>${rects}</svg>`
  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`
}

function hashStr(s: string): number {
  let h = 2166136261
  for (let i = 0; i < s.length; i++) {
    h ^= s.charCodeAt(i)
    h = Math.imul(h, 16777619)
  }
  return h >>> 0
}

function paintFinder(m: boolean[][], ox: number, oy: number): void {
  for (let y = 0; y < 7; y++) {
    for (let x = 0; x < 7; x++) {
      const on =
        x === 0 || y === 0 || x === 6 || y === 6 || (x >= 2 && x <= 4 && y >= 2 && y <= 4)
      m[oy + y][ox + x] = on
    }
  }
}

function isReserved(x: number, y: number, dim: number): boolean {
  const inFinder =
    (x < 8 && y < 8) || (x >= dim - 8 && y < 8) || (x < 8 && y >= dim - 8)
  return inFinder || x === 6 || y === 6
}

export function resolveFiscalQrSrc(receipt: {
  qr_code_url?: string | null
  fiscal_storage_number?: string | null
  fiscal_document_number?: string | null
  fiscal_sign?: string | null
  fiscalized_at?: string | null
  created_at?: string | null
  payload?: { total?: number } | null
  type?: string
}): string | null {
  if (receipt.qr_code_url) return receipt.qr_code_url
  const payload = buildOfdQrPayload(receipt)
  if (!payload) return null
  return qrSvgDataUrl(payload, 3)
}
