# Frontend selection and Vite setup

Read this for asset configuration or choosing a frontend. Preserve the target application's stack and package manager. Read its lockfiles, Vite configuration, asset entry, root Blade view, and providers before changing setup.

| Existing stack | Page contract | Continue with |
| --- | --- | --- |
| Blade, optionally Alpine/Tailwind | Full HTML; browser redirects after forms | [Blade](views-frontend.md) |
| Blade + FireLine + Alpine | Full HTML initially; adapter JSON envelopes for navigation/forms | [FireLine](fireline.md) |
| Inertia + React/Vue | Root Blade document initially; Inertia page/redirect protocol afterward | [Inertia](inertia.md) |

FireLine and Inertia are optional packages, not core APIs. Match Composer adapter and JavaScript client versions; do not add both protocols to the same action or mount point. Orbit is an application starter, not a required dependency for Inertia.

## Keep the asset contract aligned

The Skeleton baseline uses `resources/app/app.js`, imports `app.css`, and starts Alpine once. Its Vite root is `resources/app`, public base is `/build/`, output is `public/build`, and manifest is `public/build/.vite/manifest.json`. Preserve existing plugins, aliases, inputs, and template-reload configuration. Resolve input paths using the actual Vite config's working directory; do not duplicate root prefixes blindly.

- Load configured entries through `@vite('app.js')` or `@vite(['app.tsx', 'app.css'])` when both are build entries. Imported CSS need not be a separate entry.
- Register Alpine plugins before the single `Alpine.start()`. Shared bundles belong in the initial document, outside FireLine's replaced fragment.
- Put `@inertia` inside `<body>`. Keep the client mount ID and server root-element ID aligned; changing the Blade root-view path is a separate setting.
- The PHP Vite helper's root, base, distribution directory, manifest path, host, port, and scheme must agree with the build. Inspect `Spark\Utils\Vite` for nondefault setup.

## Development and deployment

Use the existing package scripts: run the Vite dev script and `php spark serve` separately, then visit the PHP application's URL. The default Skeleton dev port is 5173. Build with `npm run build` (or the project's package-manager equivalent).

Deploy the complete build output, including hidden `.vite/manifest.json`. A missing manifest usually means the build was omitted or PHP/Vite paths differ. With automatic Vite detection, local request hosts probe the dev server before using a manifest; remote hosts with a manifest use production assets. An explicit `running` configuration overrides detection. Do not leave a forced development asset host enabled in production.

Verify an initial page load and production asset URLs. Then check the relevant adapter's navigation/form behavior using its dedicated reference. A passing asset build alone does not prove the PHP/client protocol works.
