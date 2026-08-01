export function useCommandPalette() {
  const open = () => {
    window.dispatchEvent(new CustomEvent('command-palette:open'))
  }

  const close = () => {
    window.dispatchEvent(new CustomEvent('command-palette:close'))
  }

  const register = (id, label, action, meta = {}) => {
    window.__commandPaletteItems = window.__commandPaletteItems || []
    const existing = window.__commandPaletteItems.findIndex((i) => i.id === id)
    const entry = {
      id,
      label,
      action,
      hint: meta.hint,
      type: meta.type,
      keywords: meta.keywords || [],
    }
    if (existing >= 0) {
      window.__commandPaletteItems[existing] = entry
    } else {
      window.__commandPaletteItems.push(entry)
    }
  }

  return { open, close, register }
}
