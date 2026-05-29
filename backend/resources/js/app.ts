import { createInertiaApp } from '@inertiajs/vue3';
import { createApp, h, type DefineComponent } from 'vue';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import '../css/app.css';

createInertiaApp({
    title: (title) => `${title} — Masinga Booking`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./Pages/**/*.vue')
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
    progress: { color: '#0a6cb3' },
});
