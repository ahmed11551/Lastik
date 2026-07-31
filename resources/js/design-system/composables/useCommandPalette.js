export function useCommandPalette() {
  const open = () => {
    window.dispatchEvent(new CustomEvent('command-palette:open'))
  }

  const close = () => {
    window.dispatchEvent(new CustomEvent('command-palette:close'))
  }

  const register = (id, label, action) => {
    window.addEventListener('command-palette:register', () => {
      window.__commandPaletteItems = window.__commandPaletteItems || []
      window.__commandPaletteItems.push({ id, label, action })
    })
  }

  return { open, close, register }
}
