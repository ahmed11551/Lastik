/**
 * Browser print fallback — window.print() on ReceiptTemplate host
 */
import type { PosReceipt, PrinterDriver } from './types'

export type BrowserPrintHost = {
  setReceipt: (receipt: PosReceipt) => void
  print: () => void
}

let host: BrowserPrintHost | null = null

export function registerBrowserPrintHost(h: BrowserPrintHost | null): void {
  host = h
}

export class BrowserDriver implements PrinterDriver {
  async printReceipt(receiptData: PosReceipt): Promise<boolean> {
    if (!host) {
      // Last-resort: open a minimal print window
      const w = window.open('', '_blank', 'width=400,height=600')
      if (!w) return false
      w.document.write(`<pre>${escapeHtml(JSON.stringify(receiptData, null, 2))}</pre>`)
      w.document.close()
      w.focus()
      w.print()
      return true
    }
    host.setReceipt(receiptData)
    await new Promise((r) => setTimeout(r, 50))
    host.print()
    return true
  }
}

function escapeHtml(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
}
