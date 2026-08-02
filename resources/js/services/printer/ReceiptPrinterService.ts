/**
 * AUTOMETRIA ERP — unified receipt printer facade
 */
import { BrowserDriver } from './BrowserDriver'
import { EscPosDriver } from './EscPosDriver'
import type { PosReceipt, PrinterDriver, PrinterMode } from './types'

const SETTINGS_KEY = 'autometria_pos_printer_mode'

export function getPrinterMode(): PrinterMode {
  try {
    const v = localStorage.getItem(SETTINGS_KEY)
    if (v === 'escpos' || v === 'browser' || v === 'websocket') return v
  } catch {
    /* ignore */
  }
  return 'browser'
}

export function setPrinterMode(mode: PrinterMode): void {
  try {
    localStorage.setItem(SETTINGS_KEY, mode)
  } catch {
    /* ignore */
  }
}

export class ReceiptPrinterService {
  private driver: PrinterDriver

  constructor(mode?: PrinterMode) {
    const m = mode || getPrinterMode()
    if (m === 'escpos' || m === 'websocket') {
      this.driver = new EscPosDriver({
        mode: m === 'escpos' ? 'webusb' : 'websocket',
        websocketUrl: 'ws://localhost:14443',
      })
    } else {
      this.driver = new BrowserDriver()
    }
  }

  async printReceipt(receiptData: PosReceipt): Promise<boolean> {
    try {
      const ok = await this.driver.printReceipt(receiptData)
      if (!ok && !(this.driver instanceof BrowserDriver)) {
        // Fallback to browser print
        return new BrowserDriver().printReceipt(receiptData)
      }
      return ok
    } catch (e) {
      console.error('Receipt print failed', e)
      return new BrowserDriver().printReceipt(receiptData)
    }
  }
}

export function createReceiptPrinter(mode?: PrinterMode): ReceiptPrinterService {
  return new ReceiptPrinterService(mode)
}

export type { PosReceipt, PrinterMode, PrinterDriver }
