/**
 * ESC/POS raw binary driver (WebUSB / local bridge WebSocket)
 */
import type { PosReceipt, PrinterDriver } from './types'

const ESC = 0x1b
const GS = 0x1d

function encodeText(text: string): Uint8Array {
  // ASCII-safe fallback + translit-ish for RU terminals without codepage
  const normalized = text
    .replace(/₽/g, 'RUB')
    .replace(/[«»]/g, '"')
  const bytes: number[] = []
  for (let i = 0; i < normalized.length; i++) {
    const c = normalized.charCodeAt(i)
    bytes.push(c < 128 ? c : 0x3f)
  }
  return new Uint8Array(bytes)
}

function concat(...parts: Uint8Array[]): Uint8Array {
  const len = parts.reduce((n, p) => n + p.length, 0)
  const out = new Uint8Array(len)
  let o = 0
  for (const p of parts) {
    out.set(p, o)
    o += p.length
  }
  return out
}

function line(text: string): Uint8Array {
  return concat(encodeText(text), new Uint8Array([0x0a]))
}

/** Build ESC/POS baguette stream for thermal printer. */
export function buildEscPosBytes(receipt: PosReceipt): Uint8Array {
  const chunks: Uint8Array[] = []
  // Init
  chunks.push(new Uint8Array([ESC, 0x40]))
  // Center align
  chunks.push(new Uint8Array([ESC, 0x61, 0x01]))
  chunks.push(line(receipt.organization_name || 'AUTOMETRIA'))
  chunks.push(line(`INN ${receipt.inn || '—'}`))
  chunks.push(line(receipt.kkt_address || ''))
  chunks.push(line('--------------------------------'))
  // Left
  chunks.push(new Uint8Array([ESC, 0x61, 0x00]))
  chunks.push(line(`Shift ${receipt.shift_number}  Check ${receipt.receipt_number}`))
  chunks.push(line(`Cashier: ${receipt.cashier_name}`))
  chunks.push(line(receipt.datetime))
  chunks.push(line('--------------------------------'))
  for (const it of receipt.items || []) {
    chunks.push(line(it.title))
    chunks.push(line(`  ${it.qty} x ${it.price} = ${it.sum}  VAT ${it.vat_rate}`))
  }
  chunks.push(line('--------------------------------'))
  chunks.push(new Uint8Array([ESC, 0x61, 0x01]))
  chunks.push(line(`TOTAL ${receipt.total}`))
  if (receipt.cash_amount != null) chunks.push(line(`Cash ${receipt.cash_amount}`))
  if (receipt.card_amount != null) chunks.push(line(`Card ${receipt.card_amount}`))
  if (receipt.change != null) chunks.push(line(`Change ${receipt.change}`))
  chunks.push(line(`FN ${receipt.fn || '—'} FD ${receipt.fd || '—'}`))
  chunks.push(line(`FPD ${receipt.fpd || '—'}`))
  chunks.push(new Uint8Array([0x0a, 0x0a]))
  // Full cut
  chunks.push(new Uint8Array([GS, 0x56, 0x00]))
  return concat(...chunks)
}

export class EscPosDriver implements PrinterDriver {
  constructor(
    private readonly opts: {
      mode?: 'websocket' | 'webusb'
      websocketUrl?: string
    } = {},
  ) {}

  async printReceipt(receiptData: PosReceipt): Promise<boolean> {
    const bytes = buildEscPosBytes(receiptData)
    const mode = this.opts.mode || 'websocket'
    if (mode === 'websocket') {
      return this.sendViaWebsocket(bytes)
    }
    return this.sendViaWebUsb(bytes)
  }

  private sendViaWebsocket(bytes: Uint8Array): Promise<boolean> {
    const url = this.opts.websocketUrl || 'ws://localhost:14443'
    return new Promise((resolve) => {
      try {
        const ws = new WebSocket(url)
        ws.binaryType = 'arraybuffer'
        const timer = setTimeout(() => {
          try {
            ws.close()
          } catch {
            /* ignore */
          }
          resolve(false)
        }, 4000)
        ws.onopen = () => {
          ws.send(bytes)
          clearTimeout(timer)
          ws.close()
          resolve(true)
        }
        ws.onerror = () => {
          clearTimeout(timer)
          resolve(false)
        }
      } catch {
        resolve(false)
      }
    })
  }

  private async sendViaWebUsb(bytes: Uint8Array): Promise<boolean> {
    const nav = navigator as Navigator & {
      usb?: {
        requestDevice: (opts: { filters: Array<{ classCode?: number }> }) => Promise<{
          open: () => Promise<void>
          selectConfiguration: (n: number) => Promise<void>
          claimInterface: (n: number) => Promise<void>
          transferOut: (ep: number, data: BufferSource) => Promise<unknown>
          close: () => Promise<void>
        }>
      }
    }
    if (!nav.usb) return false
    try {
      const device = await nav.usb.requestDevice({ filters: [{ classCode: 7 }] })
      await device.open()
      await device.selectConfiguration(1)
      await device.claimInterface(0)
      await device.transferOut(1, bytes)
      await device.close()
      return true
    } catch {
      return false
    }
  }
}
