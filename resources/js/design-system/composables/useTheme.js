export function useTheme() {
  const current = () => document.documentElement.getAttribute('data-theme') || 'dark'

  const set = (theme) => {
    if (theme === 'system') {
      document.documentElement.removeAttribute('data-theme')
    } else {
      document.documentElement.setAttribute('data-theme', theme)
    }
  }

  const toggle = () => {
    set(current() === 'dark' ? 'light' : 'dark')
  }

  return { set, toggle, current }
}
