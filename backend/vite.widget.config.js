import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'

// Standalone IIFE build of the embeddable widget -> public/widget/masinga-widget.js
export default defineConfig({
    plugins: [vue()],
    // IIFE runs standalone in the browser; Vue reads process.env.NODE_ENV,
    // which is undefined without this define → "process is not defined".
    define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
    },
    resolve: { alias: { '@widget': path.resolve(__dirname, 'resources/js/widget') } },
    // outDir (public/widget) sits inside Vite's default publicDir (public);
    // disable publicDir copying so the build does not duplicate Laravel's
    // public assets into the widget output folder.
    publicDir: false,
    build: {
        outDir: 'public/widget',
        emptyOutDir: true,
        lib: {
            entry: path.resolve(__dirname, 'resources/js/widget/main.ts'),
            name: 'MasingaWidget',
            formats: ['iife'],
            fileName: () => 'masinga-widget.js',
        },
        rollupOptions: {
            output: { assetFileNames: 'masinga-widget.[ext]' },
        },
    },
})
