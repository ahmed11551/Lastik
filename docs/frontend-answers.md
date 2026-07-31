# LASTIK Frontend — архитектурный ответ

## 1. Структура директорий

```
frontend/
├── public/
│   ├── vite.svg
│   └── manifest.webmanifest          # PWA-ready манифест
├── src/
│   ├── assets/                       # scss/svg/fonts
│   ├── components/
│   │   ├── layouts/
│   │   │   ├── AppLayout.vue
│   │   │   ├── AuthLayout.vue
│   │   │   └── TvLayout.vue
│   │   ├── ui/                       # shadcn/ui (Vue): Button, Input, Dialog...
│   │   ├── app/
│   │   │   ├── AppSidebar.vue
│   │   │   ├── AppHeader.vue
│   │   │   ├── OrgLocationSelect.vue
│   │   │   ├── Notifications.vue
│   │   │   └── MobileMenu.vue
│   │   ├── orders/
│   │   │   ├── OrdersTable.vue
│   │   │   ├── OrderForm.vue
│   │   │   ├── OrderFilters.vue
│   │   │   └── ScenarioSwitcher.vue
│   │   ├── cash/
│   │   │   ├── CashShiftPanel.vue
│   │   │   └── TvShiftBoard.vue
│   │   └── admin/
│   │       ├── UsersTable.vue
│   │       ├── WarehouseSelector.vue
│   │       └── ProductsEditor.vue
│   ├── composables/
│   │   ├── useAuth.ts
│   │   ├── useOrgLocation.ts
│   │   ├── useOrders.ts
│   │   ├── useCashShift.ts
│   │   ├── usePermissions.ts
│   │   └── useToast.ts
│   ├── layouts/                      # Inertia layout wrappers
│   │   ├── default.vue
│   │   ├── auth.vue
│   │   └── tv.vue
│   ├── pages/
│   │   ├── auth/
│   │   │   ├── Login.vue
│   │   │   ├── SelectOrganization.vue
│   │   │   └── SelectLocation.vue
│   │   ├── dashboard.vue
│   │   ├── orders.vue
│   │   ├── orders/create.vue
│   │   ├── orders/[id].vue
│   │   ├── customers.vue
│   │   ├── stock.vue
│   │   ├── warehouses.vue
│   │   ├── prices.vue
│   │   ├── cash-shifts.vue
│   │   ├── cash-shifts/tv.vue
│   │   ├── reports.vue
│   │   ├── users.vue
│   │   ├── roles.vue
│   │   ├── modules.vue
│   │   └── settings.vue
│   ├── types/
│   │   ├── auth.ts
│   │   ├── order.ts
│   │   ├── cash.ts
│   │   ├── catalog.ts
│   │   └── permission.ts
│   ├── translations/                  # Юнити-ключи, не хардкод строки
│   │   ├── ru.json
│   │   └── index.ts
│   ├── styles/
│   │   └── tokens.css                 # CSS-переменные + tailwind токены LASTIK
│   ├── utils/
│   │   ├── errors.ts                  # нормализация ошибок Inertia/axios
│   │   ├── formatters.ts
│   │   └── permissions.ts
│   ├── App.vue
│   └── main.ts
├── index.html
├── tailwind.config.ts
├── tsconfig.json
├── vite.config.ts
└── package.json
```

### shadcn/ui в Vue 3
- CLI: `npx shadcn-vue add` через конфиг `components.json` с `style: "new-york"` и `reactType: "vue"` где поддерживается.
- Для каждого компонента — копия адаптации под `<script setup>` + TypeScript.
- Иконки: `lucide-vue-next`.
- Без React-зависимостей.

## 2. Ключевые компоненты и страницы

### Layouts
- `AuthLayout` — центрированный контейнер без сайдбара, для логина/выбора организации/точки.
- `AppLayout` — сайдбар + хедер + контент, адаптив до 320px.
- `TvLayout` — упрощённый, без навигационных кликов, крупные карточки заказов/смены.

### AppShell
- `AppSidebar` — навигация по модулям, кнопка логаута.
- `AppHeader` — выбор организации/точки (`OrgLocationSelect`), уведомления, мобильный гамбургер.
- `MobileMenu` — выезжающая панель на `max-width: 768px`.
- `Notifications` — Inertia-стacks: `toast` поверх layout.

### Экраны
- Login, SelectOrganization, SelectLocation — только формат ввода, бизнес-валидации на бэкенде.
- Dashboard — KPI-карточки, графики, быстрые действия.
- Orders — таблица/карточки, фильтры, быстрый поиск, переход к созданию/редактированию.
- OrderForm — выбор покупателя, автомобиля, товара/услуги, сценарий, оплата.
- CashShifts — открытие/закрытие, инкассация, отчёт. TV-вариант: только просмотр.
- Admin-экраны — роли/права, склады/остатки, цены, модули/настройки.

## 3. Обработка ошибок / загрузки / пустых состояний

### Универсальные паттерны
- Все страницы используют `usePage()` (`@inertiajs/vue3`) для получения `props.errors`, `props.products` и т.д.
- Явные состояния для каждого экрана:
- `isLoading` — показ `shadcn/ui Skeleton`.
- `isEmpty` — пустой шаблон `Empty + EmptyHeader + EmptyMedia + EmptyTitle + EmptyDescription`.
- `isError` — блок `Alert variant="destructive"` с нормализованным сообщением.

### useToast
- Позиция `top-right`.
- Типы: `success`, `error`, `info`.
- Автоскрытие через 4с.
- Ошибки формы от Inertia подхватываются глобально и из `page.props.flash.message`.

### Ошибки Inertia
- Клиентская валидация только форматов: phone/email/number/date. Core-правила — backend только.
- Ошибки рендерятся под полями через `shadcn/ui FormMessage`.
- 4xx — показ текстовой ошибки в `Alert`.
- 5xx — текстовое сообщение + кнопка retry.

## 4. Адаптив и TV-режим

### Токены Tailwind LASTIK
```css
:root {
  --lastik-bg: #0b0f19;
  --lastik-surface: #111827;
  --lastik-border: #1f2937;
  --lastik-text: #f3f4f6;
  --lastik-muted: #9ca3af;
  --lastik-accent: #2563eb;
  --lastik-success: #22c55e;
  --lastik-danger: #ef4444;
  --lastik-radius: 0.75rem;
}
```

- Глобально не внедряются классы, токены лежат в `tailwind.config.ts` как произвольные значения.
- Основной брейкпоинт: `mobile-first` (`base`, `md:`, `lg:`, `xl:`). Минимальная ширина корректной работы: `320px`.

### Адаптивные решения
- Навигация: на `max-width: 768px` переходит в выезжающий `MobileMenu`.
- Карточки/таблицы: на телефоне `stack`, на планшете `2 col grid`, на десктопе таблица.
- Формы: на мобильных `full width`, на десктопе `grid gap-4`.
- Текст: `text-base` на телефоне, `text-sm` на десктопе. Длинные ФИО/номера — `truncate` + `title` для раскрытия.
- Touch targets не меньше `44x44px`.

### TV-режим
- Разделение: `TvLayout` + визуальный режим на основе размеров или роута `/tv/*`.
- Отключён: ховер-интерактив, модалки, контекстные меню.
- Показ: заказы текущей смены крупными карточками, статусы цветом, краткая сумма.
- Элементы управления только черезrouter-safe кнопки, без сложных hover-эффектов.
- Работает на `1920x1080` и `4K` через `clamp()` и `xl:`/`2xl:`.
- Нет микроскопических интерактов.

## 5. PWA-ready

- `manifest.webmanifest` в `public/`.
- Иконки: `icon-192.png`, `icon-512.png` в `public/`.
- Service Worker планово на втором этапе, на первом достаточно `meta`-тегов и манифеста для «Add to Home Screen».
- Без сложного offline на первом этапе.
- В `index.html` подключены:
- `<meta name="theme-color">`
- `<link rel="manifest">`
- `<meta name="apple-mobile-web-app-capable">`

## 6. Примеры форм заказа и смены

### OrderForm — сценарии

```vue
<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import { useToast } from '@/composables/useToast'
import ScenarioSwitcher from '@/components/orders/ScenarioSwitcher.vue'
import CustomerSelect from '@/components/orders/CustomerSelect.vue'
import VehicleSelect from '@/components/orders/VehicleSelect.vue'
import ProductsList from '@/components/orders/ProductsList.vue'
import PaymentBlock from '@/components/orders/PaymentBlock.vue'

const page = usePage()
const toast = useToast()
const scenario = ref<'sale_with_install' | 'sale_no_install'>('sale_with_install')

const form = useForm({
  organization_id: page.props.auth.user.organization_id,
  location_id: page.props.auth.user.location_id,
  customer_id: null,
  vehicle_id: null,
  items: [], // { product_id, service_id, qty, reserved }
  total: 0,
  paid: 0,
  scenario: scenario.value,
})

const canSubmit = computed(() => {
  return form.customer_id && form.vehicle_id && form.items.length > 0 && !form.processing
})

function submit() {
  form.post('/orders', {
    onSuccess: () => toast.success('Заказ создан'),
    onError: () => toast.error('Ошибка при создании заказа'),
  })
}
</script>

<template>
  <form @submit.prevent="submit" class="grid gap-4">
    <ScenarioSwitcher v-model="scenario" />

    <CustomerSelect v-model="form.customer_id" :error="form.errors.customer_id" />
    <VehicleSelect v-model="form.vehicle_id" :error="form.errors.vehicle_id" />

    <ProductsList
      v-model="form.items"
      :reservation-fn="(id) => $inertia.post('/reserve', { id })"
      :error="form.errors.items"
    />

    <PaymentBlock v-model:paid="form.paid" :total="form.total" />

    <div class="flex justify-end gap-2">
      <Button type="submit" :disabled="!canSubmit">Создать заказ</Button>
    </div>
  </form>
</template>
```

### CashShiftForm

```vue
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useToast } from '@/composables/useToast'

const toast = useToast()
const mode = ref<'open' | 'close'>('open')

const form = useForm({
  opening_amount: 0,
  closing_amount: 0,
  encashment: 0,
  removal: 0,
})

function submit() {
  form.post('/cash-shifts', {
    onSuccess: () => toast.success('Смена сохранена'),
    onError: () => toast.error('Ошибка операции'),
  })
}
</script>

<template>
  <form @submit.prevent="submit" class="grid gap-4">
    <RadioGroup v-model="mode">
      <RadioGroupItem value="open" label="Открытие" />
      <RadioGroupItem value="close" label="Закрытие" />
    </RadioGroup>

    <InputNumber v-model="form.opening_amount" label="Сумма на начало" />
    <InputNumber v-if="mode === 'close'" v-model="form.closing_amount" label="Сумма на конец" />
    <InputNumber v-model="form.encashment" label="Инкассация" />
    <InputNumber v-model="form.removal" label="Выемка" />

    <Textarea :value="page.props.shift?.report || ''" readonly label="Отчёт смены" />

    <div class="flex justify-end gap-2">
      <Button type="submit" :disabled="form.processing">Сохранить</Button>
    </div>
  </form>
</template>
```

## 7. Acceptance mapping

### 44. Доступность экранов
- Роуты `/auth/login`, `/auth/select-organization`, `/auth/select-location` защищены guest-редиректом.
- Основные экраны доступны через `AppLayout` после авторизации.
- Ошибки показываются красным `Alert`, обязательные поля подсвечены красной рамкой через `shadcn/ui Form`.

### 45. Создание заказа на телефоне
- Форма заказа адаптирована под 320px: поля на всю ширину, крупные touch targets.
- Быстрый поиск по ФИО/телефону/госномеру/номеру заказа — `Input` с debounce через `watch`.
- Поле оплаты доступно с экранной клавиатуры.
- Сценарии создаются через `ScenarioSwitcher` на первом экране формы.

### 46. Просмотр смены на телефоне
- `/cash-shifts` отображает текущую смену минимумом элементов.
- TV-вариант — отдельный роут `/cash-shifts/tv`, без интерактива, крупная типографика.

### 47. Передаваемость фронтенда
- Везде `<script setup>` + TypeScript.
- Компоненты атомарные: UI-слой (`components/ui/`), доменный (`components/orders/`, `components/cash/`).
- Композиции через `composables`, нет дублирования.
- Без личного стиля, без React, без обфускации.
- Формы — только формат, без бизнес-правил.

### 48. Документация UI
- В `docs/frontend-answers.md` — структура, паттерны, токены, TV-mode, PWA.
- Код самодокументирующий: типы в `types/`, поля форм явно именованные.
- Переводы — через `translations/ru.json`, ключи по контексту.

### 49.5/49.6/49.7/49.8 — прохождение через интерфейс
- Формы затягивают нужные данные через `Inertia.get/post`, все поля имеют дефолтные значения из бэкенда (организация/точка пользователя).
- Ошибки рендерятся под полями и глобально, без скрытых перезагрузок страницы.
- Обязательные поля валидируются форматом на клиенте, обязательность показана через `required`.
- TV-режим — отдельный layout, интерактивные элементы частично отключены, сценарий просмотра заказов покрыт без ручного SQL/Postman.
- Заказы можно открыть, посмотреть детали и сформировать печать/чек через кнопки основного экрана.

## Примечания
- Бэкенд логика не дублируется на клиенте: только формат, routing и рендер.
- Всё состояние привязано к Inertia-странице или composables, без сторонних сторов.
- Для командной разработки соблюдены единые структура и токены.
