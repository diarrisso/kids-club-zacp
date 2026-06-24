<template>
  <div class="overflow-hidden rounded-ds-card border border-slate-200/70 bg-white shadow-card">
    <table class="w-full text-sm">
      <thead>
        <tr class="border-b border-slate-100 bg-slate-50/60">
          <slot name="head" />
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <slot />
      </tbody>
    </table>
  </div>
</template>
