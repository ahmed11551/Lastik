declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<Record<string, unknown>, Record<string, unknown>, unknown>
  export default component
}

declare module '@/design-system' {
  import type { DefineComponent } from 'vue'
  export const DsBadge: DefineComponent
  export const DsTable: DefineComponent
  export const DsShiftWidget: DefineComponent
  export const DsCommandPalette: DefineComponent
  export const DsSidebar: DefineComponent
  export function useTheme(): { set: (t: string) => void; toggle: () => void }
}
