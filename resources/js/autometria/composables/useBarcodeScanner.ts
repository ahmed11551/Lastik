/**
 * AUTOMETRIA ERP — Barcode / DataMatrix scanner composable (Cosmic Navy).
 *
 * Strategy:
 *   1. Native BarcodeDetector API (Chrome/Android/Edge) — fastest, no deps.
 *   2. Fallback: @zxing/browser (lazy dynamic import) when BarcodeDetector
 *      is unavailable (older Safari/Firefox). The import is guarded so the
 *      bundle stays buildable even if the package is not installed; install
 *      it only when you need the fallback path.
 *   3. Graceful degradation: if no camera / permission denied / unsupported,
 *      the composable exposes `supported=false` and a manual entry path.
 *
 * The composable is UI-agnostic: it streams decoded raw strings via the
 * `onDetect` callback; parsing (GS1/DataMatrix) is done by gs1Parser.ts.
 */

import { onUnmounted, ref, type Ref } from 'vue'

export interface ScanState {
  supported: Ref<boolean>
  streaming: Ref<boolean>
  error: Ref<string | null>
  start: (video: HTMLVideoElement, onDetect: (raw: string) => void) => Promise<void>
  stop: () => void
}

function hasBarcodeDetector(): boolean {
  return typeof window !== 'undefined' && 'BarcodeDetector' in window
}

export function useBarcodeScanner(): ScanState {
  const supported = ref(false)
  const streaming = ref(false)
  const error = ref<string | null>(null)

  let detector: any = null
  let videoEl: HTMLVideoElement | null = null
  let zxingReader: any = null
  let rafId = 0
  let stream: MediaStream | null = null

  async function start(
    video: HTMLVideoElement,
    onDetect: (raw: string) => void,
  ): Promise<void> {
    error.value = null
    if (typeof navigator === 'undefined' || !navigator.mediaDevices?.getUserMedia) {
      supported.value = false
      error.value = 'Камера недоступна в этом окружении.'
      return
    }

    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'environment' },
        audio: false,
      })
      video.srcObject = stream
      await video.play()
      videoEl = video
      supported.value = true
    } catch (e) {
      supported.value = false
      error.value = 'Нет доступа к камере (разрешение отклонено или устройство занято).'
      return
    }

    if (hasBarcodeDetector()) {
      // @ts-ignore - BarcodeDetector is not yet in all TS DOM libs
      const BD: typeof BarcodeDetector = (window as any).BarcodeDetector
      detector = new BD({ formats: ['qr_code', 'data_matrix', 'ean_13', 'code_128', 'ean_8'] })
      streaming.value = true
      const tick = async () => {
        if (!videoEl || !detector) return
        try {
          const codes = await detector.detect(videoEl)
          for (const c of codes) {
            if (c.rawValue) onDetect(c.rawValue)
          }
        } catch {
          /* transient detect error — keep looping */
        }
        if (streaming.value) rafId = requestAnimationFrame(tick)
      }
      rafId = requestAnimationFrame(tick)
      return
    }

    // Fallback: ZXing (lazy, optional). Resolved at runtime only — not bundled.
    // If the package is not installed, the dynamic import throws and we degrade.
    try {
      const spec = '@zxing/browser'
      const mod = await import(/* @vite-ignore */ spec)
      zxingReader = new mod.BrowserMultiFormatReader()
      streaming.value = true
      const loop = async () => {
        if (!videoEl || !zxingReader) return
        try {
          const res = await zxingReader.decodeOnceFromVideoElement(videoEl)
          if (res?.getText()) onDetect(res.getText())
        } catch {
          /* no barcode this frame */
        }
        if (streaming.value) rafId = requestAnimationFrame(loop)
      }
      rafId = requestAnimationFrame(loop)
    } catch {
      supported.value = false
      error.value = 'BarcodeDetector не поддержан, ZXing не установлен. Используйте ручной ввод.'
      stop()
    }
  }

  function stop(): void {
    streaming.value = false
    if (rafId) cancelAnimationFrame(rafId)
    rafId = 0
    if (zxingReader?.stopContinuousDecode) {
      try {
        zxingReader.stopContinuousDecode()
      } catch {
        /* ignore */
      }
    }
    if (stream) {
      stream.getTracks().forEach((t) => t.stop())
      stream = null
    }
    if (videoEl) videoEl.srcObject = null
    detector = null
    zxingReader = null
  }

  onUnmounted(() => stop())

  return { supported, streaming, error, start, stop }
}
