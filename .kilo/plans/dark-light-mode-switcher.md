# Dark / Light Mode Switcher (Top Nav)

## Goal
Add a dark/light theme switcher to the top navigation of the main app. Default follows the OS `prefers-color-scheme`, the user can override it, and the choice is persisted in `localStorage`. All label / nav text in the themed areas must remain clearly legible in both modes.

## Decisions (confirmed)
- **Scope**: Infrastructure + core layouts only. Individual pages keep their current light colors and are migrated incrementally later.
- **Default**: Follow system preference (`prefers-color-scheme`), overridable by the user.
- **Persistence**: `localStorage` only (no backend / DB changes).

## Stack context
- Tailwind **v4** via `@tailwindcss/vite` (`resources/css/app.css` uses `@import "tailwindcss"`). No `tailwind.config.js`.
- Tailwind v4 dark mode defaults to media-query strategy. We must switch to **class strategy** with a custom variant.
- ~138 Vue files, colors hardcoded light; only `Pages/Settings/LeadershipPoints.vue` already has `dark:` variants.
- Top nav lives in `resources/js/Layouts/AppLayout.vue:128`.
- SPA shell is `resources/views/app.blade.php`.

## Implementation steps

### 1. Enable class-based dark mode (Tailwind v4)
File: `resources/css/app.css`
- Add: `@custom-variant dark (&:where(.dark, .dark *));`
- This makes `dark:` utilities apply when an ancestor has the `.dark` class.

### 2. Anti-flash (FOUC) inline script
File: `resources/views/app.blade.php`
- Add a tiny blocking inline `<script>` in `<head>` (before `@vite`) that reads the saved preference from `localStorage` (`ghelpdesk.theme` = `light` | `dark` | `system`) and, resolving `system` via `matchMedia('(prefers-color-scheme: dark)')`, adds/removes the `dark` class on `document.documentElement` **before paint**.
- This prevents a flash of the wrong theme on first load / navigation.

### 3. `useTheme` composable
New file: `resources/js/Composables/useTheme.js`
- Shared module-level reactive `theme` ref (`light` | `dark` | `system`) and a derived `isDark` computed.
- `setTheme(mode)`, `toggle()` (flips between light/dark regardless of system), `init()`.
- On `init`: read `localStorage` key `ghelpdesk.theme` (default `system`), apply the `dark` class to `<html>`, and attach a `matchMedia` `change` listener so that when in `system` mode the UI reacts to OS theme changes live.
- Persist every change back to `localStorage` (guarded with try/catch like the existing sidebar storage pattern at `AppLayout.vue:41`).
- Storage key constant: `ghelpdesk.theme`.

### 4. `ThemeToggle` component
New file: `resources/js/Components/ThemeToggle.vue`
- Imports `useTheme`.
- A round icon button (heroicons sun/moon) using `@heroicons/vue/24/outline` (already a project dependency — see `Sidebar.vue` imports).
- Shows `MoonIcon` when light, `SunIcon` when dark; `aria-label` + `title` for clarity ("Switch to dark/light theme").
- Optional: a small dropdown offering `Light | Dark | System`. Decision kept simple: default to a direct toggle (light↔dark), with a "System" reset available in the user dropdown (step 5). To keep it minimal and clear, implement as a **direct toggle button**.

### 5. Wire toggle into the top nav
File: `resources/js/Layouts/AppLayout.vue`
- Import `ThemeToggle` and call `useTheme().init()` inside the existing `onMounted` (currently `AppLayout.vue:86`).
- Place `<ThemeToggle />` in the right-side cluster (around `AppLayout.vue:163`, next to `GlobalSearch` / `NotificationBell`).
- Add `dark:` variants to the top nav so it themes correctly while keeping labels legible:
  - Top bar: `bg-white shadow-sm border-b border-gray-200` → add `dark:bg-gray-900 dark:border-gray-700`.
  - Mobile menu button / title text `text-gray-900` → `dark:text-gray-100`.
  - Desktop page title `text-gray-900` → `dark:text-gray-100`.
  - User name `text-gray-700` → `dark:text-gray-200`.
  - Knowledge Base button (`text-blue-600 bg-blue-50 border-blue-100`) → `dark:text-blue-300 dark:bg-blue-950/40 dark:border-blue-900`.
  - User menu dropdown panel (`bg-white ...`) → `dark:bg-gray-800 dark:ring-gray-700`; its links `text-gray-700 hover:bg-gray-100` → `dark:text-gray-200 dark:hover:bg-gray-700`. "Logged in as" subtext `text-gray-500` → `dark:text-gray-400`.
  - Main content background `bg-gray-50` (lines 116 & 224) → add `dark:bg-gray-950`.
- Sidebar (`Sidebar.vue`) is intentionally always dark (`bg-gray-900`), so it needs no change and stays legible in both modes — verify flyout/tooltip contrast only.

### 6. Theme the legacy secondary layouts (keep labels legible)
- `resources/js/Layouts/AuthenticatedLayout.vue`: add `dark:` variants to the nav (`bg-white` → `dark:bg-gray-900`), border, nav text, settings dropdown button text, and responsive menu panel text.
- `resources/js/Layouts/GuestLayout.vue`: this is the login/branding screen with a fixed photo design. Leave the photo treatment untouched; only ensure the right-panel footer text and any labels stay readable. Minimal changes only (footer text contrast). No toggle added here (pre-auth).

## Files changed
- `resources/css/app.css` — add dark custom variant.
- `resources/views/app.blade.php` — FOUC inline script.
- `resources/js/Composables/useTheme.js` — NEW.
- `resources/js/Components/ThemeToggle.vue` — NEW.
- `resources/js/Layouts/AppLayout.vue` — wire toggle + dark variants.
- `resources/js/Layouts/AuthenticatedLayout.vue` — dark variants.
- `resources/js/Layouts/GuestLayout.vue` — minor label-contrast tweaks.

## Out of scope (explicit)
- Theming the ~138 page/component files (e.g. Dashboard, Tickets). Pages will still render light inside the themed shell for now; this is accepted as the "infrastructure + core layouts" scope.
- Backend / per-user DB persistence.

## Verification
- `npm run build` (or `npm run dev`) compiles without error.
- Toggle the button: `.dark` class toggles on `<html>`; refresh preserves choice via `localStorage`.
- With `system` mode, changing OS dark/light updates the app live.
- Confirm top-nav labels (page title, user name, KB button, dropdown items) are clearly legible in both light and dark.
- No FOUC on hard refresh in either mode.

## Notes
- Heroicons already available (`@heroicons/vue/24/outline`) — no new dependency.
- Follow existing localStorage try/catch pattern (`AppLayout.vue:41-47`).
- Tailwind v4 `@custom-variant` is the correct mechanism (not `darkMode` config, which is v3).
