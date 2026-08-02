/**
 * AUTOMETRIA ERP — Browser print fallback driver.
 *
 * Renders the receipt via the browser's native print dialog (@media print CSS in
 * ReceiptTemplate.vue). Also exposes a lightweight host registry so ReceiptTemplate
 * can register itself and be triggered programmatically from other modules.
 */

import type { PosReceipt, PrinterDriver } from '../types'

export interface BrowserPrintHost {
  setReceipt: (r: PosReceipt) => void
  print: () => void
}

let host: BrowserPrintHost | null = null

/** Register (or clear with null) the active ReceiptTemplate print host. */
export function registerBrowserPrintHost(h: BrowserPrintHost | null): void {
  host = h
}

/** Trigger print on the registered host, if any. */
export function triggerBrowserPrint(receipt?: PosReceipt): boolean {
  if (!host) return false
  if (receipt) host.setReceipt(receipt)
  host.print()
  return true
}

export class BrowserDriver implements PrinterDriver {
  async printReceipt(_receipt: PosReceipt): Promise<boolean> {
    try {
      if (typeof window !== 'undefined') {
        window.print()
        return true
      }
    } catch (e) {
      console.error('[BrowserDriver] print failed', e)
    }
    return false
  }
}
