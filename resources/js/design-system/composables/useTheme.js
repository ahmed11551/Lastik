export function useTheme() {
  const set = (theme) => {
    if (theme === 'system') {
      document.documentElement.removeAttribute('data-theme')
    } else {
      document.documentElement.setAttribute('data-theme', theme)
    }
  }

  const toggle = () => {
    const current = document.documentElement.getAttribute('data-theme')
    set(current === 'dark' ? 'light' : 'dark')
  }

  return { set, toggle }
}
