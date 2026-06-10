import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'
import tailwindcss from 'tailwindcss'
import autoprefixer from 'autoprefixer'

// Standalone IIFE build of the embeddable widget -> public/widget/masinga-widget.js
export default defineConfig({
    plugins: [vue()],
    css: {
        postcss: {
            plugins: [tailwindcss({ config: './tailwind.widget.config.js' }), autoprefixer()],
        },
    },
    // IIFE runs standalone in the browser; Vue reads process.env.NODE_ENV,
    // which is undefined without this define → "process is not defined".
    define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
    },
    resolve: { alias: { '@widget': path.resolve(import.meta.dirname, 'resources/js/widget') } },
    // outDir (public/widget) sits inside Vite's default publicDir (public);
    // disable publicDir copying so the build does not duplicate Laravel's
    // public assets into the widget output folder.
    publicDir: false,
    build: {
        outDir: 'public/widget',
        emptyOutDir: true,
        lib: {
            entry: path.resolve(import.meta.dirname, 'resources/js/widget/main.ts'),
            name: 'MasingaWidget',
            formats: ['iife'],
            fileName: () => 'masinga-widget.js',
            // Vite 6 requires an explicit CSS name (or a package.json "name")
            // once the lib build emits any CSS — without this, `build:widget`
            // fails with "Name in package.json is required ...". Produces
            // public/widget/masinga-widget.css.
            cssFileName: 'masinga-widget',
        },
        rollupOptions: {
            output: { assetFileNames: 'masinga-widget.[ext]' },
        },
    },
})
