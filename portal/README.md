# Carbure portal

Web portal of Carbure: Vue 3 (global build, no build step) served as static files, talking
to the API of `src/` (`/api/...`). User documentation: [docs/portail.md](../docs/portail.md).

| Path | Content |
|---|---|
| `index.html` | The whole template (tabs, modals) |
| `app.js` | Vue application: tabs, login, shared state; mixes in the modules |
| `modules/` | One mixin per part of the portal (state, computed values and methods) |
| `services/apiService.js` | Calls to the API (JWT, error messages, cancellable list requests) |
| `stores/budgetStore.js` | Navigation in the budget categories |
| `components/` | `CategoryPicker`, `FlowChart`, `SqlEditor`, `TagInput` |
| `utils/` | Formatting, icons and colors of the categories |
| `i18n.js` | Loader of the texts, `t()` (fallback to French, plurals `_one` / `_other`) |
| `i18n/*.json` | Texts, one file per language (`fr` reference, `en`): every key in each |
| `vendor/` | Vue, CodeMirror and the fonts, self-hosted |
| `manifest.webmanifest`, `icons/` | Web App Manifest and icons: the portal installs as an app (no service worker, no offline mode) |

Tests:

- unit tests of the modules without DOM (`utils/`, `services/`, `stores/`, the agent
  configurations): `node --test tests/portal/`;
- end-to-end tests of the whole portal against the API: `tests/e2e/` (Playwright).
