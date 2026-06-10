/**
 * Tailwind config for the STANDALONE WIDGET BUILD ONLY (vite.widget.config.js
 * + vitest). Kept separate from tailwind.config.js so widget tokens (CSS
 * variables set at runtime from /api/v1/widget/config) never collide with the
 * main app's shadcn `primary`/`accent` hsl tokens.
 *
 * Colors use `rgb(var(--…-rgb) / <alpha-value>)` so opacity modifiers like
 * `ring-accent/20` keep working; useTheme sets BOTH the hex var (gradients,
 * inline styles) and the rgb-triplet var (Tailwind alpha).
 */
export default {
    content: ['./resources/js/widget/**/*.{vue,ts}'],
    theme: {
        extend: {
            colors: {
                primary: 'rgb(var(--masinga-primary-rgb) / <alpha-value>)',
                'primary-to': 'rgb(var(--masinga-primary-to-rgb) / <alpha-value>)',
                accent: 'rgb(var(--masinga-accent-rgb) / <alpha-value>)',
                'widget-bg': 'rgb(var(--masinga-bg-rgb) / <alpha-value>)',
                'widget-text': 'rgb(var(--masinga-text-rgb) / <alpha-value>)',
            },
            borderRadius: {
                widget: 'var(--masinga-radius)',
            },
            fontFamily: {
                heading: 'var(--masinga-font-heading)',
                body: 'var(--masinga-font-body)',
            },
        },
    },
}
