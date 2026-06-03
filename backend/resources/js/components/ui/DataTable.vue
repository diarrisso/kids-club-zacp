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
