/** @type {import('tailwindcss').Config} */
export default {
  darkMode: ["class", '[data-theme="dark"]'],
  content: [
    "./resources/**/*.{vue,js,ts,jsx,tsx,blade.php}",
    "./resources/js/design-system/**/*.{vue,js,css,html}",
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ["Inter", "sans-serif"],
        mono: ["JetBrains Mono", "monospace"],
      },
      colors: {
        brand: {
          bg: "#0B0D10",
          surface: "#11151A",
          elevated: "#1A1F26",
          primary: "#F59E0B",
          "primary-hover": "#D97706",
          border: "#1F2937",
          text: "#E5E7EB",
          "text-secondary": "#9CA3AF",
          success: "#10B981",
          danger: "#EF4444",
          warning: "#F59E0B",
        },
        background: "var(--color-bg)",
        foreground: "var(--color-text-primary)",
        primary: {
          DEFAULT: "var(--color-primary)",
          foreground: "var(--color-bg)",
          hover: "var(--color-primary-hover)",
        },
        border: "var(--color-border)",
        ring: "var(--color-focus)",
        surface: {
          DEFAULT: "var(--color-surface)",
          elevated: "var(--color-surface-elevated)",
        },
        success: "var(--color-success)",
        danger: "var(--color-danger)",
        warning: "var(--color-warning)",
      },
      borderRadius: {
        DEFAULT: "4px",
        none: "0",
        sm: "4px",
        md: "4px",
        lg: "4px",
        xl: "4px",
        "2xl": "4px",
        full: "9999px",
        ds: "4px",
      },
      width: {
        sidebar: "260px",
      },
      spacing: {
        sidebar: "260px",
        "row-compact": "32px",
        "row-comfortable": "48px",
      },
    },
  },
  plugins: [],
}
