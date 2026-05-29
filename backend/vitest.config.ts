import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import path from 'path'

export default defineConfig({
    plugins: [vue()],
    resolve: { alias: { '@widget': path.resolve(import.meta.dirname, 'resources/js/widget') } },
    test: {
        environment: 'jsdom',
        include: ['tests/widget/**/*.test.ts'],
    },
})
