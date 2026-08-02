/**
 * AUTOMETRIA ERP — ESC/POS binary command builder & transport.
 *
 * Generates raw ESC/POS byte streams (CP866) for thermal receipt printers
 * (Атол / Штрих-М / generic ESC/POS) and sends them via WebUSB or WebSocket
 * to a local KKT agent (e.g. Atol WebServer / Штрих Driver API @ ws://localhost:14443).
 */

import type { PosReceipt, PrinterDriver } from '../types'

const ESC = 0x1b
const GS = 0x1d

/** CP866 encode a JS string into Uint8Array (Russian thermal-printer codepage). */
function toCp866(input: string): Uint8Array {
  const out: number[] = []
  for (const ch of input) {
    const code = ch.codePointAt(0) ?? 0x20
    if (code < 0x80) {
      out.push(code)
      continue
    }
    // Map common Cyrillic + punctuation to CP866.
    const cp866 = CP866_MAP[ch]
    out.push(cp866 ?? 0x20)
  }
  return new Uint8Array(out)
}

// Minimal CP866 mapping (covers А-Я, а-я, Ёё, punctuation used on receipts).
const CP866_MAP: Record<string, number> = buildCp866Map()

function buildCp866Map(): Record<string, number> {
  const map: Record<string, number> = {}
  const upper = 'АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ'
  const lower = 'абвгдеёжзийклмнопрстуфхцчшщъыьэюя'
  for (let i = 0; i < upper.length; i++) {
    // CP866 uppercase starts at 0x80
    map[upper[i]] = 0x80 + i
    map[lower[i]] = 0xa0 + i
  }
  map['№'] = 0x4e // approximated
  map['—'] = 0x2d
  map['•'] = 0x95
  map['₽'] = 0x50 // approximated (P)
  return map
}

export type EscPosTransport = 'webusb' | 'websocket'

export interface EscPosDriverOptions {
  mode: EscPosTransport
  websocketUrl?: string
  vendorId?: number
  productId?: number
}

export class EscPosDriver implements PrinterDriver {
  private mode: EscPosTransport
  private websocketUrl: string
  private vendorId: number
  private productId: number

  constructor(opts: EscPosDriverOptions) {
    this.mode = opts.mode
    this.websocketUrl = opts.websocketUrl || 'ws://localhost:14443'
    this.vendorId = opts.vendorId ?? 0x0416 // default Atol-class VID (override if needed)
    this.productId = opts.productId ?? 0x0035
  }

  /** Build a full ESC/POS byte stream for the receipt. */
  buildBytes(receipt: PosReceipt): Uint8Array {
    const chunks: Uint8Array[] = []

    const cmd = (...bytes: number[]) => chunks.push(new Uint8Array(bytes))
    const text = (s: string) => chunks.push(toCp866(s + '\n'))

    // Initialize / reset.
    cmd(ESC, 0x40)
    // Center align header.
    cmd(ESC, 0x61, 0x01)
    text(receipt.organization_name.toUpperCase())
    text(`ИНН ${receipt.inn}`)
    if (receipt.kkt_address) text(receipt.kkt_address)
    // Left align for body.
    cmd(ESC, 0x61, 0x00)
    text('--------------------------------')
    text(`Смена №${receipt.shift_number}`)
    text(`Чек №${receipt.receipt_number}`)
    text(`Кассир: ${receipt.cashier_name}`)
    text(receipt.datetime)
    text('--------------------------------')

    for (const it of receipt.items) {
      text(it.title)
      text(`${it.price.toFixed(2)} x ${it.qty} · НДС ${it.vat_rate}`)
      text(`= ${it.sum.toFixed(2)}`)
    }

    text('--------------------------------')
    // Double-height/width TOTAL.
    cmd(GS, 0x21, 0x11)
    text(`ИТОГО: ${receipt.total.toFixed(2)} руб.`)
    cmd(GS, 0x21, 0x00)
    if (receipt.cash_amount != null) text(`НАЛИЧНЫМИ: ${receipt.cash_amount.toFixed(2)} руб.`)
    if (receipt.card_amount != null) text(`КАРТОЙ: ${receipt.card_amount.toFixed(2)} руб.`)
    if (receipt.change != null) text(`СДАЧА: ${receipt.change.toFixed(2)} руб.`)
    text('--------------------------------')
    if (receipt.fn) text(`ФН ${receipt.fn}`)
    if (receipt.fd) text(`ФД ${receipt.fd}`)
    if (receipt.fpd) text(`ФПД ${receipt.fpd}`)
    if (receipt.qr_payload) {
      // Render QR payload as text (real QR rendering needs a bitmap module).
      text('Проверка чека:')
      text(receipt.qr_payload)
    }
    // Feed + full cut.
    cmd(ESC, 0x64, 0x05)
    cmd(GS, 0x56, 0x00)

    // Concatenate.
    const total = chunks.reduce((n, c) => n + c.length, 0)
    const out = new Uint8Array(total)
    let offset = 0
    for (const c of chunks) {
      out.set(c, offset)
      offset += c.length
    }
    return out
  }

  async printReceipt(receipt: PosReceipt): Promise<boolean> {
    const bytes = this.buildBytes(receipt)
    try {
      if (this.mode === 'webusb') {
        return await this.sendWebUsb(bytes)
      }
      return await this.sendWebSocket(bytes)
    } catch (e) {
      console.error('[EscPosDriver] send failed', e)
      return false
    }
  }

  private async sendWebUsb(bytes: Uint8Array): Promise<boolean> {
    if (typeof navigator === 'undefined' || !('usb' in navigator)) {
      throw new Error('WebUSB unavailable in this browser/context')
    }
    // @ts-expect-error - navigator.usb typings may be 'unknown' in this tsconfig
    const device = await navigator.usb.requestDevice({
      filters: [{ vendorId: this.vendorId, productId: this.productId }],
    })
    await device.open()
    if (device.configuration === null) await device.selectConfiguration(1)
    await device.claimInterface(0)
    const MAX = 64
    for (let i = 0; i < bytes.length; i += MAX) {
      const slice = bytes.subarray(i, i + MAX)
      await device.transferOut(1, slice)
    }
    return true
  }

  private async sendWebSocket(bytes: Uint8Array): Promise<boolean> {
    return await new Promise<boolean>((resolve) => {
      const ws = new WebSocket(this.websocketUrl)
      const timeout = setTimeout(() => {
        ws.close()
        resolve(false)
      }, 5000)
      ws.binaryType = 'arraybuffer'
      ws.onopen = () => {
        ws.send(bytes)
      }
      ws.onmessage = (ev) => {
        clearTimeout(timeout)
        const ok = typeof ev.data === 'string' ? ev.data.includes('OK') : true
        ws.close()
        resolve(ok)
      }
      ws.onerror = () => {
        clearTimeout(timeout)
        resolve(false)
      }
    })
  }
}
