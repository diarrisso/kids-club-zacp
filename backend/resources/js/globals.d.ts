import type { AxiosInstance } from 'axios'

// `window.axios` is exposed by resources/js/bootstrap.js (Laravel convention).
// Declared here so TypeScript knows the global the Inertia pages use.
declare global {
  interface Window {
    axios: AxiosInstance
  }
}

export {}
