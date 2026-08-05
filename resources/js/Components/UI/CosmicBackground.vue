<script setup>
/**
 * AUTOMETRIA ERP — CosmicBackground
 * CSS gradient + film-grain SVG (feTurbulence). No external images.
 */
import { useId } from 'vue'

defineProps({
  /** When true, fills viewport as fixed layer behind content */
  fixed: { type: Boolean, default: true },
})

const grainId = `cosmic-film-grain-${useId().replace(/:/g, '')}`
</script>

<template>
  <div
    class="cosmic-bg pointer-events-none overflow-hidden"
    :class="fixed ? 'fixed inset-0 z-0' : 'absolute inset-0'"
    aria-hidden="true"
  >
    <div class="cosmic-bg__gradient absolute inset-0" />
    <div class="cosmic-bg__glow absolute inset-0" />
    <svg class="cosmic-bg__noise absolute inset-0 h-full w-full opacity-[0.035]" xmlns="http://www.w3.org/2000/svg">
      <filter :id="grainId">
        <feTurbulence
          type="fractalNoise"
          baseFrequency="0.85"
          numOctaves="4"
          stitchTiles="stitch"
        />
      </filter>
      <rect
        width="100%"
        height="100%"
        :filter="`url(#${grainId})`"
      />
    </svg>
  </div>
</template>

<style scoped>
.cosmic-bg__gradient {
  background: linear-gradient(
    145deg,
    #000000 0%,
    #050a1f 48%,
    #0d1b3d 100%
  );
}

.cosmic-bg__glow {
  background:
    radial-gradient(ellipse 80% 55% at 20% 10%, color-mix(in srgb, #1a3c8c 42%, transparent), transparent 60%),
    radial-gradient(ellipse 70% 50% at 85% 80%, color-mix(in srgb, #1a3c8c 28%, transparent), transparent 55%);
}
</style>
