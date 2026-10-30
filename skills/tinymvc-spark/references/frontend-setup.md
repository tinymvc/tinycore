# Frontend Selection and Vite Setup

> **Read when:** configuring assets or choosing a frontend.
> **Related:** [Blade](views-frontend.md) · [FireLine](fireline.md) · [Inertia](inertia.md)
> Preserve the target app's stack and package manager. Before changing setup, read its lockfiles, Vite config, asset entry, root Blade view, and providers.

## Choose by existing stack

| Existing stack | Page contract | Continue with |
| --- | --- | --- |
| Blade, optionally Alpine/Tailwind | Full HTML; browser redirects after forms | [Blade](views-frontend.md) |
| Blade + FireLine + Alpine | Full HTML initially; adapter JSON envelopes for navigation/forms | [FireLine](fireline.md) |
| Inertia + React/Vue | Root Blade document initially; Inertia page/redirect protocol afterward | [Inertia](inertia.md) |

FireLine and Inertia are **optional packages, not core APIs**. Match the Composer adapter and JavaScript client versions. Never add both protocols to the same action or mount point. Orbit is an application starter, not a required dependency for Inertia.

## Keep the asset contract aligned

The Skeleton baseline:

| Setting | Value |
| --- | --- |
| Entry | `resources/app/app.js` (imports `app.css`, starts Alpine once) |
| Vite root | `resources/app` |
| Public base | `/build/` |
| Output | `public/build` |
| Manifest | `public/build/.vite/manifest.json` |
| Dev port | 5173 |

- Preserve existing plugins, aliases, inputs, and template-reload config. Resolve input paths from the actual Vite config's working directory; do not duplicate root prefixes.
- Load entries with `@vite('app.js')`, or `@vite(['app.tsx', 'app.css'])` when both are build entries. CSS imported from JS need not be a separate entry.
- Register Alpine plugins before the single `Alpine.start()`. Shared bundles belong in the initial document, outside FireLine's replaced fragment.
- Put `@inertia` inside `<body>`. Keep the client mount ID and server root-element ID aligned. (Changing the Blade root-view path is a separate setting.)
- The PHP Vite helper's root, base, dist directory, manifest path, host, port, and scheme must agree with the build. Inspect `Spark\Utils\Vite` for nondefault setups.

## Development and deployment

- **Dev:** run the app's Vite dev script and `php spark serve` separately, then visit the PHP app's URL.
- **Build:** `npm run build` (or the project's package-manager equivalent).
- **Deploy** the complete build output, **including the hidden `.vite/manifest.json`**. A missing manifest usually means the build was omitted or PHP/Vite paths differ.
- **Detection:** with automatic Vite detection, local request hosts probe the dev server before using a manifest; remote hosts with a manifest use production assets. An explicit `running` config overrides detection. Never leave a forced development asset host enabled in production.

## Verify

1. Initial page load and production asset URLs.
2. The relevant adapter's navigation/form behavior (see its reference).

A passing asset build alone does not prove the PHP/client protocol works.