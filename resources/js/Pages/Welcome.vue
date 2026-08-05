<script setup>
/**
 * AUTOMETRIA ERP — B2B Landing
 */
import { Head, Link } from '@inertiajs/vue3'
import CosmicBackground from '@/Components/UI/CosmicBackground.vue'

defineProps({
  canLogin: { type: Boolean, default: false },
  canRegister: { type: Boolean, default: false },
  laravelVersion: { type: String, default: '' },
  phpVersion: { type: String, default: '' },
})
</script>

<template>
  <Head title="AUTOMETRIA — B2B ERP" />
  <div class="relative flex min-h-screen flex-col text-[#e8edf5]">
    <CosmicBackground />

    <div class="relative z-10 flex min-h-screen flex-col">
      <header class="flex items-center justify-between px-6 py-5 lg:px-10">
        <div class="flex items-center gap-3">
          <span
            class="inline-block h-2.5 w-2.5 rounded-sm"
            style="background: #1a3c8c; box-shadow: 0 0 0 3px color-mix(in srgb, #1a3c8c 35%, transparent)"
            aria-hidden="true"
          />
          <span class="text-xl font-semibold tracking-tight text-white sm:text-2xl">
            AUTOMETRIA
          </span>
        </div>
        <nav
          v-if="canLogin"
          class="flex items-center gap-2"
        >
          <Link
            v-if="$page.props.auth.user"
            :href="route('dashboard')"
            class="rounded border px-3 py-2 font-mono text-[11px] font-bold uppercase tracking-wide"
            style="border-color: #1e293b; color: #e8edf5; background: #0f172a"
          >
            Dashboard
          </Link>
          <template v-else>
            <Link
              :href="route('login')"
              class="rounded border px-3 py-2 font-mono text-[11px] font-bold uppercase tracking-wide"
              style="border-color: #1e293b; color: #e8edf5; background: #0f172a"
            >
              Войти
            </Link>
            <Link
              v-if="canRegister"
              :href="route('register')"
              class="rounded border px-3 py-2 font-mono text-[11px] font-bold uppercase tracking-wide"
              style="background: #f59e0b; color: #090d16; border-color: #f59e0b"
            >
              Регистрация
            </Link>
          </template>
        </nav>
      </header>

      <main class="flex flex-1 flex-col justify-center px-6 pb-16 pt-8 lg:px-10">
        <div class="mx-auto w-full max-w-3xl space-y-6">
          <p
            class="font-mono text-[10px] font-medium uppercase tracking-[0.18em]"
            style="color: #93c5fd"
          >
            B2B ERP · v1.1.0
          </p>
          <h1 class="max-w-2xl text-3xl font-semibold tracking-tight text-white sm:text-4xl lg:text-5xl">
            Операционная система для шинного и сервисного бизнеса
          </h1>
          <p
            class="max-w-xl text-base leading-relaxed sm:text-lg"
            style="color: #a8b3c7"
          >
            POS, WMS, заказы и мультитенантность в единой тёмной консоли — без внешних фоновых картинок, только градиент и данные.
          </p>
          <div class="flex flex-wrap items-center gap-3 pt-2">
            <Link
              v-if="canLogin && !$page.props.auth.user"
              :href="route('login')"
              class="inline-flex items-center border px-4 py-2.5 font-mono text-[11px] font-bold uppercase tracking-wide"
              style="background: #f59e0b; color: #090d16; border-color: #f59e0b; border-radius: 4px"
            >
              Открыть консоль
            </Link>
            <Link
              v-else-if="canLogin"
              :href="route('dashboard')"
              class="inline-flex items-center border px-4 py-2.5 font-mono text-[11px] font-bold uppercase tracking-wide"
              style="background: #f59e0b; color: #090d16; border-color: #f59e0b; border-radius: 4px"
            >
              К дашборду
            </Link>
            <span
              class="font-mono text-[11px]"
              style="color: #64748b"
            >
              Laravel {{ laravelVersion }} · PHP {{ phpVersion }}
            </span>
          </div>
        </div>
      </main>
    </div>
  </div>
</template>
