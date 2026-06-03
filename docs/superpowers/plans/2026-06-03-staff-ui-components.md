# Bibliothèque de composants UI réutilisables — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extraire une bibliothèque de composants UI Vue réutilisables et refactorer les 11 pages staff existantes pour les consommer, sans aucun changement fonctionnel ni visuel.

**Architecture :** Nouveau dossier `resources/js/components/ui/` avec des primitives de formulaire (FormField, TextInput, PrimaryButton, ButtonLink, Card) et de liste (PageHeader, DataTable, StatusBadge, ColorDot, RowActions). Les pages importent ces composants via l'alias `@`. Refactor incrémental avec build + vérification visuelle Chrome entre chaque lot.

**Tech Stack :** Vue 3 `<script setup lang="ts">` · Inertia 2 · TailwindCSS 3 · Vite. Pas de tests unitaires de composants (décision du spec — vérification par build + Chrome + suite Pest backend inchangée).

**Conventions :**
- Reporter les classes Tailwind **à l'identique** depuis les pages actuelles (zéro changement visuel).
- `<script setup lang="ts">`, alias `@` = `resources/js`.
- Commandes depuis `backend/`. Build : `npm run build`. Suite backend (non-régression routes) : `php artisan test`.
- **Aucun changement backend, aucune route, aucun changement de comportement.**

**Vérification (chaque lot de pages) :**
1. `npm run build` réussit (compile TS/Vue).
2. Chrome : l'écran refactoré est **visuellement identique** à avant et les interactions (create/edit/delete + `confirm`, erreurs de validation) fonctionnent.
3. À la fin : `php artisan test` reste vert (82 tests).

---

## Structure des fichiers

**À créer** (`backend/resources/js/components/ui/`) :
- `FormField.vue`, `TextInput.vue`, `PrimaryButton.vue`, `ButtonLink.vue`, `Card.vue`
- `PageHeader.vue`, `DataTable.vue`, `StatusBadge.vue`, `ColorDot.vue`, `RowActions.vue`

**À modifier** (refactor, contenu complet fourni) :
- `Pages/Tenant/Services/Index.vue`, `Practitioners/Index.vue`, `Availabilities/Index.vue`, `Exceptions/Index.vue`
- `Pages/Tenant/Services/Form.vue`, `Practitioners/Form.vue`, `Availabilities/Form.vue`, `Exceptions/Form.vue`
- `Pages/Tenant/Dashboard.vue`

**Non touché** : `Appointments/AppointmentForm.vue`, `Appointments/Calendar.vue` (spécialisés — hors scope de ce refactor pour limiter le risque ; pourront adopter les primitives plus tard). `QrCode.vue` (sur la PR QR, follow-up après merge).

---

## Task 1 : Primitives de formulaire

**Files :**
- Create: `backend/resources/js/components/ui/FormField.vue`
- Create: `backend/resources/js/components/ui/TextInput.vue`
- Create: `backend/resources/js/components/ui/PrimaryButton.vue`
- Create: `backend/resources/js/components/ui/ButtonLink.vue`
- Create: `backend/resources/js/components/ui/Card.vue`

- [ ] **Step 1 : Créer `FormField.vue`**

```vue
<script setup lang="ts">
defineProps<{
  label: string
  error?: string
  required?: boolean
}>()
</script>

<template>
  <div>
    <label class="block text-sm font-medium mb-1">
      {{ label }}<span v-if="required"> *</span>
    </label>
    <slot />
    <div v-if="error" class="text-red-600 text-sm">{{ error }}</div>
  </div>
</template>
```

- [ ] **Step 2 : Créer `TextInput.vue`**

```vue
<script setup lang="ts">
defineProps<{ modelValue: string | number | null }>()
defineEmits<{ 'update:modelValue': [value: string | number] }>()
</script>

<template>
  <input
    :value="modelValue"
    class="w-full p-2 border rounded"
    @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
  />
</template>
```

- [ ] **Step 3 : Créer `PrimaryButton.vue`**

```vue
<script setup lang="ts">
withDefaults(defineProps<{
  type?: 'submit' | 'button'
  disabled?: boolean
}>(), { type: 'submit', disabled: false })
</script>

<template>
  <button
    :type="type"
    :disabled="disabled"
    class="bg-blue-700 text-white px-6 py-2 rounded hover:bg-blue-800 disabled:opacity-50"
  >
    <slot />
  </button>
</template>
```

- [ ] **Step 4 : Créer `ButtonLink.vue`**

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
defineProps<{ href: string }>()
</script>

<template>
  <Link :href="href" class="bg-blue-700 text-white px-4 py-2 rounded hover:bg-blue-800">
    <slot />
  </Link>
</template>
```

- [ ] **Step 5 : Créer `Card.vue`**

```vue
<script setup lang="ts">
withDefaults(defineProps<{ as?: 'div' | 'form' }>(), { as: 'div' })
</script>

<template>
  <component :is="as" class="bg-white p-6 rounded shadow space-y-4">
    <slot />
  </component>
</template>
```

- [ ] **Step 6 : Build**

Run (depuis `backend/`) : `npm run build`
Expected : build réussit, aucun composant ne casse la compilation.

- [ ] **Step 7 : Commit**

```bash
git add backend/resources/js/components/ui/FormField.vue backend/resources/js/components/ui/TextInput.vue backend/resources/js/components/ui/PrimaryButton.vue backend/resources/js/components/ui/ButtonLink.vue backend/resources/js/components/ui/Card.vue
git commit -m "feat(ui): form primitives (FormField, TextInput, PrimaryButton, ButtonLink, Card)"
```

---

## Task 2 : Primitives de liste

**Files :**
- Create: `backend/resources/js/components/ui/PageHeader.vue`
- Create: `backend/resources/js/components/ui/DataTable.vue`
- Create: `backend/resources/js/components/ui/StatusBadge.vue`
- Create: `backend/resources/js/components/ui/ColorDot.vue`
- Create: `backend/resources/js/components/ui/RowActions.vue`

- [ ] **Step 1 : Créer `PageHeader.vue`**

```vue
<script setup lang="ts">
defineProps<{ title: string }>()
</script>

<template>
  <div class="flex justify-between items-center mb-6">
    <h1 class="text-3xl font-bold">{{ title }}</h1>
    <slot name="action" />
  </div>
</template>
```

- [ ] **Step 2 : Créer `DataTable.vue`**

```vue
<script setup lang="ts"></script>

<template>
  <table class="w-full bg-white rounded shadow">
    <thead class="bg-slate-100">
      <slot name="head" />
    </thead>
    <tbody>
      <slot />
    </tbody>
  </table>
</template>
```

- [ ] **Step 3 : Créer `StatusBadge.vue`**

```vue
<script setup lang="ts">
defineProps<{ active: boolean }>()
</script>

<template>
  <span v-if="active" class="text-green-600">Aktiv</span>
  <span v-else class="text-slate-400">Inaktiv</span>
</template>
```

- [ ] **Step 4 : Créer `ColorDot.vue`**

```vue
<script setup lang="ts">
withDefaults(defineProps<{ color: string; size?: 'sm' | 'md' }>(), { size: 'md' })
</script>

<template>
  <span
    class="inline-block rounded-full"
    :class="size === 'sm' ? 'w-4 h-4' : 'w-6 h-6'"
    :style="{ background: color }"
  ></span>
</template>
```

- [ ] **Step 5 : Créer `RowActions.vue`**

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
defineProps<{ editHref: string }>()
defineEmits<{ delete: [] }>()
</script>

<template>
  <div class="text-right">
    <Link :href="editHref" class="text-blue-600 mr-3">Bearbeiten</Link>
    <button class="text-red-600" @click="$emit('delete')">Löschen</button>
  </div>
</template>
```

> **Décision :** la confirmation `confirm('Wirklich löschen?')` reste **dans la page parente**
> (qui possède `router.delete` et connaît l'URL). `RowActions` émet seulement `delete` —
> il ne connaît aucune route métier, donc reste réutilisable sur n'importe quelle entité.
> Note : l'original mettait `class="p-3 text-right"` sur le `<td>` ; ici le `<td>` garde
> `class="p-3"` et `RowActions` apporte `text-right` sur son `<div>` racine — rendu identique.

- [ ] **Step 6 : Build**

Run : `npm run build`
Expected : build réussit.

- [ ] **Step 7 : Commit**

```bash
git add backend/resources/js/components/ui/PageHeader.vue backend/resources/js/components/ui/DataTable.vue backend/resources/js/components/ui/StatusBadge.vue backend/resources/js/components/ui/ColorDot.vue backend/resources/js/components/ui/RowActions.vue
git commit -m "feat(ui): list primitives (PageHeader, DataTable, StatusBadge, ColorDot, RowActions)"
```

---

## Task 3 : Refactor des pages Index (listes)

**Files :**
- Modify: `backend/resources/js/Pages/Tenant/Services/Index.vue`
- Modify: `backend/resources/js/Pages/Tenant/Practitioners/Index.vue`
- Modify: `backend/resources/js/Pages/Tenant/Availabilities/Index.vue`
- Modify: `backend/resources/js/Pages/Tenant/Exceptions/Index.vue`

- [ ] **Step 1 : Refactor `Services/Index.vue` (contenu complet)**

```vue
<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import ButtonLink from '@/components/ui/ButtonLink.vue'
import DataTable from '@/components/ui/DataTable.vue'
import ColorDot from '@/components/ui/ColorDot.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import RowActions from '@/components/ui/RowActions.vue'
defineOptions({ layout: TenantLayout })

defineProps<{ services: Array<{
  id: number; name: string; duration_minutes: number;
  color: string; is_active: boolean;
}> }>()

const destroy = (id: number) => {
  if (confirm('Wirklich löschen?')) router.delete(`/leistungen/${id}`)
}
</script>

<template>
  <Head title="Leistungen" />
  <div class="p-8">
    <PageHeader title="Leistungen">
      <template #action>
        <ButtonLink href="/leistungen/create">+ Neue Leistung</ButtonLink>
      </template>
    </PageHeader>
    <DataTable>
      <template #head>
        <tr>
          <th class="p-3 text-left">Bezeichnung</th>
          <th class="p-3">Dauer</th>
          <th class="p-3">Farbe</th>
          <th class="p-3">Status</th>
          <th class="p-3"></th>
        </tr>
      </template>
      <tr v-for="s in services" :key="s.id" class="border-t">
        <td class="p-3">{{ s.name }}</td>
        <td class="p-3 text-center">{{ s.duration_minutes }} min</td>
        <td class="p-3 text-center"><ColorDot :color="s.color" /></td>
        <td class="p-3 text-center"><StatusBadge :active="s.is_active" /></td>
        <td class="p-3"><RowActions :edit-href="`/leistungen/${s.id}/edit`" @delete="destroy(s.id)" /></td>
      </tr>
    </DataTable>
  </div>
</template>
```

- [ ] **Step 2 : Refactor `Practitioners/Index.vue` (contenu complet)**

```vue
<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import ButtonLink from '@/components/ui/ButtonLink.vue'
import DataTable from '@/components/ui/DataTable.vue'
import ColorDot from '@/components/ui/ColorDot.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import RowActions from '@/components/ui/RowActions.vue'
defineOptions({ layout: TenantLayout })

defineProps<{ practitioners: Array<{
  id: number; first_name: string; last_name: string; title: string;
  email: string; color: string; is_active: boolean;
}> }>()

const destroy = (id: number) => {
  if (confirm('Wirklich löschen?')) router.delete(`/behandler/${id}`)
}
</script>

<template>
  <Head title="Behandler" />
  <div class="p-8">
    <PageHeader title="Behandler">
      <template #action>
        <ButtonLink href="/behandler/create">+ Neuer Behandler</ButtonLink>
      </template>
    </PageHeader>
    <DataTable>
      <template #head>
        <tr>
          <th class="p-3 text-left">Name</th>
          <th class="p-3 text-left">E-Mail</th>
          <th class="p-3">Farbe</th>
          <th class="p-3">Status</th>
          <th class="p-3"></th>
        </tr>
      </template>
      <tr v-for="p in practitioners" :key="p.id" class="border-t">
        <td class="p-3">{{ p.title }} {{ p.first_name }} {{ p.last_name }}</td>
        <td class="p-3">{{ p.email }}</td>
        <td class="p-3 text-center"><ColorDot :color="p.color" /></td>
        <td class="p-3 text-center"><StatusBadge :active="p.is_active" /></td>
        <td class="p-3"><RowActions :edit-href="`/behandler/${p.id}/edit`" @delete="destroy(p.id)" /></td>
      </tr>
    </DataTable>
  </div>
</template>
```

- [ ] **Step 3 : Refactor `Availabilities/Index.vue` (contenu complet)**

```vue
<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import ButtonLink from '@/components/ui/ButtonLink.vue'
import DataTable from '@/components/ui/DataTable.vue'
import ColorDot from '@/components/ui/ColorDot.vue'
import RowActions from '@/components/ui/RowActions.vue'
defineOptions({ layout: TenantLayout })

const days: Record<number, string> = {
  1: 'Mo', 2: 'Di', 3: 'Mi', 4: 'Do', 5: 'Fr', 6: 'Sa', 7: 'So',
}

defineProps<{
  availabilities: Array<{
    id: number; day_of_week: number; start_time: string; end_time: string;
    practitioner: { first_name: string; last_name: string; color: string };
  }>;
}>()

const destroy = (id: number) => {
  if (confirm('Wirklich löschen?')) router.delete(`/sprechzeiten/${id}`)
}
</script>

<template>
  <Head title="Sprechzeiten" />
  <div class="p-8">
    <PageHeader title="Sprechzeiten">
      <template #action>
        <ButtonLink href="/sprechzeiten/create">+ Neue Sprechzeit</ButtonLink>
      </template>
    </PageHeader>
    <DataTable>
      <template #head>
        <tr>
          <th class="p-3 text-left">Behandler</th>
          <th class="p-3 text-left">Wochentag</th>
          <th class="p-3 text-left">Zeit</th>
          <th class="p-3"></th>
        </tr>
      </template>
      <tr v-for="a in availabilities" :key="a.id" class="border-t">
        <td class="p-3">
          <ColorDot :color="a.practitioner.color" size="sm" class="mr-2" />
          {{ a.practitioner.first_name }} {{ a.practitioner.last_name }}
        </td>
        <td class="p-3">{{ days[a.day_of_week] }}</td>
        <td class="p-3">{{ a.start_time }} – {{ a.end_time }}</td>
        <td class="p-3"><RowActions :edit-href="`/sprechzeiten/${a.id}/edit`" @delete="destroy(a.id)" /></td>
      </tr>
    </DataTable>
  </div>
</template>
```

> **Note ColorDot inline** : `class="mr-2"` est fusionné sur l'élément racine du composant
> (Vue fusionne les classes passées). Combiné à `size="sm"` → `w-4 h-4 mr-2`, identique à l'original.

- [ ] **Step 4 : Refactor `Exceptions/Index.vue` (contenu complet)**

```vue
<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import ButtonLink from '@/components/ui/ButtonLink.vue'
import DataTable from '@/components/ui/DataTable.vue'
import ColorDot from '@/components/ui/ColorDot.vue'
import RowActions from '@/components/ui/RowActions.vue'
defineOptions({ layout: TenantLayout })

const labels: Record<string, string> = {
  vacation: 'Urlaub', sick: 'Krankheit', block: 'Blockierung',
}

defineProps<{
  exceptions: Array<{
    id: number; starts_at: string; ends_at: string; type: string; reason: string | null;
    practitioner: { first_name: string; last_name: string; color: string };
  }>;
}>()

const destroy = (id: number) => {
  if (confirm('Wirklich löschen?')) router.delete(`/abwesenheiten/${id}`)
}
</script>

<template>
  <Head title="Abwesenheiten" />
  <div class="p-8">
    <PageHeader title="Abwesenheiten">
      <template #action>
        <ButtonLink href="/abwesenheiten/create">+ Neue Abwesenheit</ButtonLink>
      </template>
    </PageHeader>
    <DataTable>
      <template #head>
        <tr>
          <th class="p-3 text-left">Behandler</th>
          <th class="p-3 text-left">Typ</th>
          <th class="p-3 text-left">Von</th>
          <th class="p-3 text-left">Bis</th>
          <th class="p-3 text-left">Grund</th>
          <th class="p-3"></th>
        </tr>
      </template>
      <tr v-for="e in exceptions" :key="e.id" class="border-t">
        <td class="p-3">
          <ColorDot :color="e.practitioner.color" size="sm" class="mr-2" />
          {{ e.practitioner.first_name }} {{ e.practitioner.last_name }}
        </td>
        <td class="p-3">{{ labels[e.type] ?? e.type }}</td>
        <td class="p-3">{{ e.starts_at }}</td>
        <td class="p-3">{{ e.ends_at }}</td>
        <td class="p-3">{{ e.reason }}</td>
        <td class="p-3"><RowActions :edit-href="`/abwesenheiten/${e.id}/edit`" @delete="destroy(e.id)" /></td>
      </tr>
    </DataTable>
  </div>
</template>
```

- [ ] **Step 5 : Build + vérif visuelle**

Run : `npm run build` → réussit.
Chrome : ouvrir `/leistungen`, `/behandler`, `/sprechzeiten`, `/abwesenheiten`. Comparer à l'état d'origine : tables, pastilles couleur (tailles), badges Aktiv/Inaktiv, liens Bearbeiten + Löschen (avec `confirm`) **identiques**. Tester une suppression (confirm) sur une entrée.

- [ ] **Step 6 : Commit**

```bash
git add backend/resources/js/Pages/Tenant/Services/Index.vue backend/resources/js/Pages/Tenant/Practitioners/Index.vue backend/resources/js/Pages/Tenant/Availabilities/Index.vue backend/resources/js/Pages/Tenant/Exceptions/Index.vue
git commit -m "refactor(ui): Index pages use list primitives (PageHeader, DataTable, ColorDot, StatusBadge, RowActions)"
```

---

## Task 4 : Refactor des formulaires

**Files :**
- Modify: `backend/resources/js/Pages/Tenant/Services/Form.vue`
- Modify: `backend/resources/js/Pages/Tenant/Practitioners/Form.vue`
- Modify: `backend/resources/js/Pages/Tenant/Availabilities/Form.vue`
- Modify: `backend/resources/js/Pages/Tenant/Exceptions/Form.vue`

> **Approche** : remplacer chaque bloc `<div><label/><control/><error/></div>` par
> `<FormField :label :error :required>…control…</FormField>`, le `<form class="bg-white …">`
> par `<Card as="form" @submit.prevent="submit">`, le `<h1 class="text-3xl font-bold mb-6">`
> reste (titre simple de page de formulaire), et le bouton par `<PrimaryButton :disabled="form.processing">Speichern</PrimaryButton>`.
> Les `<input class="w-full p-2 border rounded">` simples deviennent `<TextInput v-model>`;
> les `<select>`, `<input type=color/time/datetime-local/number>`, checkboxes restent natifs
> (gardent leur classe) **dans** `FormField`.

- [ ] **Step 1 : Refactor `Services/Form.vue` (contenu complet)**

```vue
<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import Card from '@/components/ui/Card.vue'
import FormField from '@/components/ui/FormField.vue'
import TextInput from '@/components/ui/TextInput.vue'
import PrimaryButton from '@/components/ui/PrimaryButton.vue'
defineOptions({ layout: TenantLayout })

const props = defineProps<{
  service: null | {
    id: number; name: string; duration_minutes: number;
    color: string; description: string; is_active: boolean;
    practitioners?: Array<{ id: number }>;
  };
  practitioners: Array<{ id: number; first_name: string; last_name: string; title: string }>;
}>()

const form = useForm({
  name: props.service?.name ?? '',
  duration_minutes: props.service?.duration_minutes ?? 30,
  color: props.service?.color ?? '#0a6cb3',
  description: props.service?.description ?? '',
  is_active: props.service?.is_active ?? true,
  practitioner_ids: props.service?.practitioners?.map(p => p.id) ?? [],
})

const submit = () => {
  if (props.service) form.put(`/leistungen/${props.service.id}`)
  else form.post('/leistungen')
}
</script>

<template>
  <Head :title="service ? 'Leistung bearbeiten' : 'Neue Leistung'" />
  <div class="p-8 max-w-2xl">
    <h1 class="text-3xl font-bold mb-6">
      {{ service ? 'Leistung bearbeiten' : 'Neue Leistung' }}
    </h1>
    <Card as="form" @submit.prevent="submit">
      <FormField label="Bezeichnung" required :error="form.errors.name">
        <TextInput v-model="form.name" required />
      </FormField>
      <FormField label="Dauer (Minuten)" required :error="form.errors.duration_minutes">
        <input v-model.number="form.duration_minutes" type="number" min="5" max="480" required
               class="w-full p-2 border rounded">
      </FormField>
      <FormField label="Beschreibung">
        <textarea v-model="form.description" rows="3" class="w-full p-2 border rounded"></textarea>
      </FormField>
      <FormField label="Farbe im Kalender">
        <input v-model="form.color" type="color" class="h-10 w-20 border rounded">
      </FormField>
      <FormField label="Wird ausgeführt von">
        <div class="space-y-2">
          <label v-for="p in practitioners" :key="p.id" class="flex items-center gap-2">
            <input type="checkbox" :value="p.id" v-model="form.practitioner_ids">
            {{ p.title }} {{ p.first_name }} {{ p.last_name }}
          </label>
        </div>
      </FormField>
      <label class="flex items-center gap-2">
        <input v-model="form.is_active" type="checkbox"> Aktiv
      </label>
      <PrimaryButton :disabled="form.processing">Speichern</PrimaryButton>
    </Card>
  </div>
</template>
```

- [ ] **Step 2 : Refactor `Practitioners/Form.vue` (contenu complet)**

```vue
<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import Card from '@/components/ui/Card.vue'
import FormField from '@/components/ui/FormField.vue'
import TextInput from '@/components/ui/TextInput.vue'
import PrimaryButton from '@/components/ui/PrimaryButton.vue'
defineOptions({ layout: TenantLayout })

const props = defineProps<{
  practitioner: null | {
    id: number; first_name: string; last_name: string; title: string;
    email: string; color: string; is_active: boolean;
  }
}>()

const form = useForm({
  first_name: props.practitioner?.first_name ?? '',
  last_name: props.practitioner?.last_name ?? '',
  title: props.practitioner?.title ?? '',
  email: props.practitioner?.email ?? '',
  color: props.practitioner?.color ?? '#0a6cb3',
  is_active: props.practitioner?.is_active ?? true,
})

const submit = () => {
  if (props.practitioner) form.put(`/behandler/${props.practitioner.id}`)
  else form.post('/behandler')
}
</script>

<template>
  <Head :title="practitioner ? 'Behandler bearbeiten' : 'Neuer Behandler'" />
  <div class="p-8 max-w-2xl">
    <h1 class="text-3xl font-bold mb-6">
      {{ practitioner ? 'Behandler bearbeiten' : 'Neuer Behandler' }}
    </h1>
    <Card as="form" @submit.prevent="submit">
      <FormField label="Anrede">
        <TextInput v-model="form.title" placeholder="Dr., Zahnärztin, ..." />
      </FormField>
      <div class="grid grid-cols-2 gap-4">
        <FormField label="Vorname" required :error="form.errors.first_name">
          <TextInput v-model="form.first_name" required />
        </FormField>
        <FormField label="Nachname" required :error="form.errors.last_name">
          <TextInput v-model="form.last_name" required />
        </FormField>
      </div>
      <FormField label="E-Mail" :error="form.errors.email">
        <input v-model="form.email" type="email" class="w-full p-2 border rounded">
      </FormField>
      <FormField label="Farbe im Kalender">
        <input v-model="form.color" type="color" class="h-10 w-20 border rounded">
      </FormField>
      <label class="flex items-center gap-2">
        <input v-model="form.is_active" type="checkbox"> Aktiv
      </label>
      <PrimaryButton :disabled="form.processing">Speichern</PrimaryButton>
    </Card>
  </div>
</template>
```

> **Note `TextInput` + `placeholder`** : `placeholder` est passé en attribut « fallthrough »
> sur l'`<input>` interne du composant (Vue propage les attrs non déclarés). OK.

- [ ] **Step 3 : Refactor `Availabilities/Form.vue` (contenu complet)**

```vue
<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import Card from '@/components/ui/Card.vue'
import FormField from '@/components/ui/FormField.vue'
import PrimaryButton from '@/components/ui/PrimaryButton.vue'
defineOptions({ layout: TenantLayout })

const props = defineProps<{
  availability: null | {
    id: number; practitioner_id: number; day_of_week: number;
    start_time: string; end_time: string;
  };
  practitioners: Array<{ id: number; first_name: string; last_name: string; title: string }>;
}>()

const days = [
  { value: 1, label: 'Montag' }, { value: 2, label: 'Dienstag' },
  { value: 3, label: 'Mittwoch' }, { value: 4, label: 'Donnerstag' },
  { value: 5, label: 'Freitag' }, { value: 6, label: 'Samstag' },
  { value: 7, label: 'Sonntag' },
]

const form = useForm({
  practitioner_id: props.availability?.practitioner_id ?? props.practitioners[0]?.id ?? null,
  day_of_week: props.availability?.day_of_week ?? 1,
  start_time: props.availability?.start_time ?? '09:00',
  end_time: props.availability?.end_time ?? '17:00',
})

const submit = () => {
  if (props.availability) form.put(`/sprechzeiten/${props.availability.id}`)
  else form.post('/sprechzeiten')
}
</script>

<template>
  <Head :title="availability ? 'Sprechzeit bearbeiten' : 'Neue Sprechzeit'" />
  <div class="p-8 max-w-2xl">
    <h1 class="text-3xl font-bold mb-6">
      {{ availability ? 'Sprechzeit bearbeiten' : 'Neue Sprechzeit' }}
    </h1>
    <Card as="form" @submit.prevent="submit">
      <FormField label="Behandler" required :error="form.errors.practitioner_id">
        <select v-model.number="form.practitioner_id" class="w-full p-2 border rounded">
          <option v-for="p in practitioners" :key="p.id" :value="p.id">
            {{ p.title }} {{ p.first_name }} {{ p.last_name }}
          </option>
        </select>
      </FormField>
      <FormField label="Wochentag" required>
        <select v-model.number="form.day_of_week" class="w-full p-2 border rounded">
          <option v-for="d in days" :key="d.value" :value="d.value">{{ d.label }}</option>
        </select>
      </FormField>
      <div class="grid grid-cols-2 gap-4">
        <FormField label="Von" required>
          <input v-model="form.start_time" type="time" required class="w-full p-2 border rounded">
        </FormField>
        <FormField label="Bis" required :error="form.errors.end_time">
          <input v-model="form.end_time" type="time" required class="w-full p-2 border rounded">
        </FormField>
      </div>
      <PrimaryButton :disabled="form.processing">Speichern</PrimaryButton>
    </Card>
  </div>
</template>
```

- [ ] **Step 4 : Refactor `Exceptions/Form.vue` (contenu complet)**

```vue
<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import Card from '@/components/ui/Card.vue'
import FormField from '@/components/ui/FormField.vue'
import TextInput from '@/components/ui/TextInput.vue'
import PrimaryButton from '@/components/ui/PrimaryButton.vue'
defineOptions({ layout: TenantLayout })

const props = defineProps<{
  exception: null | {
    id: number; practitioner_id: number; starts_at: string;
    ends_at: string; type: string; reason: string | null;
  };
  practitioners: Array<{ id: number; first_name: string; last_name: string; title: string }>;
}>()

const types = [
  { value: 'vacation', label: 'Urlaub' },
  { value: 'sick', label: 'Krankheit' },
  { value: 'block', label: 'Blockierung' },
]

const form = useForm({
  practitioner_id: props.exception?.practitioner_id ?? props.practitioners[0]?.id ?? null,
  starts_at: props.exception?.starts_at ?? '',
  ends_at: props.exception?.ends_at ?? '',
  type: props.exception?.type ?? 'vacation',
  reason: props.exception?.reason ?? '',
})

const submit = () => {
  if (props.exception) form.put(`/abwesenheiten/${props.exception.id}`)
  else form.post('/abwesenheiten')
}
</script>

<template>
  <Head :title="exception ? 'Abwesenheit bearbeiten' : 'Neue Abwesenheit'" />
  <div class="p-8 max-w-2xl">
    <h1 class="text-3xl font-bold mb-6">
      {{ exception ? 'Abwesenheit bearbeiten' : 'Neue Abwesenheit' }}
    </h1>
    <Card as="form" @submit.prevent="submit">
      <FormField label="Behandler" required>
        <select v-model.number="form.practitioner_id" class="w-full p-2 border rounded">
          <option v-for="p in practitioners" :key="p.id" :value="p.id">
            {{ p.title }} {{ p.first_name }} {{ p.last_name }}
          </option>
        </select>
      </FormField>
      <FormField label="Typ" required>
        <select v-model="form.type" class="w-full p-2 border rounded">
          <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>
      </FormField>
      <div class="grid grid-cols-2 gap-4">
        <FormField label="Von" required>
          <input v-model="form.starts_at" type="datetime-local" required class="w-full p-2 border rounded">
        </FormField>
        <FormField label="Bis" required :error="form.errors.ends_at">
          <input v-model="form.ends_at" type="datetime-local" required class="w-full p-2 border rounded">
        </FormField>
      </div>
      <FormField label="Grund">
        <TextInput v-model="form.reason" />
      </FormField>
      <PrimaryButton :disabled="form.processing">Speichern</PrimaryButton>
    </Card>
  </div>
</template>
```

- [ ] **Step 5 : Build + vérif visuelle**

Run : `npm run build` → réussit.
Chrome : ouvrir create + edit de `/leistungen`, `/behandler`, `/sprechzeiten`, `/abwesenheiten`. Vérifier : champs identiques, espacements, bouton Speichern, et qu'une **erreur de validation** s'affiche bien (ex. soumettre un formulaire invalide). Créer + éditer une entrée pour confirmer le flux complet.

- [ ] **Step 6 : Commit**

```bash
git add backend/resources/js/Pages/Tenant/Services/Form.vue backend/resources/js/Pages/Tenant/Practitioners/Form.vue backend/resources/js/Pages/Tenant/Availabilities/Form.vue backend/resources/js/Pages/Tenant/Exceptions/Form.vue
git commit -m "refactor(ui): forms use Card + FormField + TextInput + PrimaryButton"
```

---

## Task 5 : Dashboard (PageHeader)

**Files :**
- Modify: `backend/resources/js/Pages/Tenant/Dashboard.vue`

- [ ] **Step 1 : Refactor `Dashboard.vue` (contenu complet)**

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import TenantLayout from '@/Layouts/TenantLayout.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
defineOptions({ layout: TenantLayout })
</script>

<template>
  <Head title="Dashboard" />
  <div class="p-8">
    <PageHeader title="Cabinet Dashboard" />
  </div>
</template>
```

> **Note** : l'original affichait `<h1 class="text-3xl font-bold">Cabinet Dashboard</h1>`
> sans `mb-6`. `PageHeader` ajoute `mb-6` au conteneur — différence visuelle négligeable
> (marge basse sur une page autrement vide). Acceptable. Si tu veux le strict iso-pixel,
> garder le `<h1>` natif ; ici on privilégie la réutilisation.

- [ ] **Step 2 : Build**

Run : `npm run build` → réussit. Chrome : `/dashboard` affiche le titre.

- [ ] **Step 3 : Commit**

```bash
git add backend/resources/js/Pages/Tenant/Dashboard.vue
git commit -m "refactor(ui): Dashboard uses PageHeader"
```

---

## Task 6 : Vérification finale

**Files :** aucun (vérification).

- [ ] **Step 1 : Build complet**

Run (depuis `backend/`) : `npm run build`
Expected : réussit, aucun warning bloquant.

- [ ] **Step 2 : Suite backend (non-régression)**

Run : `php artisan test`
Expected : **82 passed** (les feature tests backend ne dépendent pas du markup, mais on confirme que rien n'est cassé côté routes/Inertia).

- [ ] **Step 3 : Revue visuelle Chrome complète**

Parcourir tous les écrans refactorés (Dashboard, 4 Index, 8 formulaires create/edit) et confirmer **iso-visuel** + interactions (create, edit, delete avec confirm, affichage des erreurs).

- [ ] **Step 4 : Commit éventuel & fin**

```bash
git add -A && git commit -m "chore: ui-components refactor verified" || echo "rien à committer"
```

---

## Self-review (couverture du spec)

- §4 FormField / TextInput / PrimaryButton / ButtonLink / Card → Task 1 ✅
- §4 PageHeader / DataTable / StatusBadge / ColorDot / RowActions → Task 2 ✅
- §4 décision « RowActions émet delete » → Task 2 Step 5 (version propre) ✅
- §5 refactor 4 Index → Task 3 ✅
- §5 refactor 4 Form → Task 4 ✅ ; §5 Dashboard → Task 5 ✅
- §5 Calendar/AppointmentForm : hors scope explicite (noté en tête) ✅
- §6 QrCode.vue follow-up : noté en tête, hors scope de ce plan ✅
- §7 vérification build + Chrome + Pest → Tasks 3/4/6 ✅
- §9 risque régression visuelle → vérif Chrome incrémentale par lot ✅

Cohérence des noms/props : `ColorDot(color, size)`, `StatusBadge(active)`, `RowActions(editHref, @delete)`, `PageHeader(title, #action)`, `DataTable(#head, default)`, `Card(as)`, `FormField(label, error, required)`, `TextInput(v-model)`, `PrimaryButton(type, disabled)`, `ButtonLink(href)` — utilisés de façon identique dans toutes les pages. Pas de placeholder (l'ébauche `RowActions` est explicitement remplacée par la version propre).
