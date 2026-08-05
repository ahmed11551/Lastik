<script setup>
/**
 * AUTOMETRIA ERP — Guest auth shell (Login / Register)
 */
import ApplicationLogo from '@/Components/ApplicationLogo.vue'
import CosmicBackground from '@/Components/UI/CosmicBackground.vue'
import { Link } from '@inertiajs/vue3'
</script>

<template>
  <div class="relative flex min-h-screen flex-col items-center justify-center px-4 py-8">
    <CosmicBackground />
    <div class="relative z-10 flex w-full max-w-md flex-col items-center">
      <Link
        href="/"
        class="mb-6 inline-flex items-center gap-2 text-white"
      >
        <ApplicationLogo class="h-12 w-12 fill-current text-brand-accent" />
        <span class="text-lg font-semibold tracking-tight">AUTOMETRIA</span>
      </Link>

      <div
        class="w-full overflow-hidden border px-6 py-5 shadow-xl"
        style="background: #0f172a; border-color: #1e293b; border-radius: 4px; color: #e8edf5"
      >
        <slot />
      </div>
    </div>
  </div>
</template>
