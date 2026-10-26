# Exact API discovery

Use when a signature, return type, optional parameter or method location is uncertain. The installed source is authoritative for the application's version. Behavioral references explain decisions; a declaration list cannot establish side effects, transaction scope or compatibility.

## Find task guidance before scanning source

Use the topic links in `SKILL.md`, or search the installed references with focused terms:

```bash
rg -n -i 'cursor|pagination' vendor/tinymvc/tinycore/skills/tinymvc-spark/references
```

In a core checkout, use `skills/tinymvc-spark/references`. Read the relevant section, then look up uncertain declarations; avoid loading the whole reference directory or dumping every source method. This skill provides local guidance and declaration search, not a runtime MCP server or semantic documentation service.

## Targeted source lookup

From the application root:

```bash
php vendor/tinymvc/tinycore/skills/tinymvc-spark/scripts/api-lookup.php upsert
php vendor/tinymvc/tinycore/skills/tinymvc-spark/scripts/api-lookup.php transaction --file=Database/
php vendor/tinymvc/tinycore/skills/tinymvc-spark/scripts/api-lookup.php whenLoaded
php vendor/tinymvc/tinycore/skills/tinymvc-spark/scripts/api-lookup.php PendingDispatch --limit=40
```

The dependency-free PHP tool reads declarations without booting the app, requiring source files, connecting to services, or executing application code. Output is limited to 25 matching declarations by default, with signatures and absolute source locations. Narrow with `--file=substring`; `--limit=1..200` controls output. `--source=/path/to/tinycore/src` explicitly selects a different checkout. The default is `vendor/tinymvc/tinycore/src` relative to the current working directory, never an automatic sibling-checkout fallback.

For explicitly requested TinyCore work, run from that checkout and select its source deliberately:

```bash
php skills/tinymvc-spark/scripts/api-lookup.php upsert --source=src
```

The entire `Support/` directory, including `Collection`, `Str`, `Arr`, and helpers, is omitted by default. Pass `--include-support` when investigating those APIs, for example `slug --include-support --file=Support/Str.php`. Exit codes: 0 for matches/help, 1 for no matching declaration, 2 for invalid arguments/source path.

Read the matching method body and its callers before relying on behavior. Follow trait imports, parent classes, `__call`/`__callStatic`, facade accessors and application wrappers when a method is forwarded. The tool reports declared public methods and selected protected extension points; it is not a resolved inheritance graph or a complete PHP semantic analyzer. No match does not prove an API is unavailable. It does not enumerate constants, properties, docblock magic methods, runtime macros or optional integration packages.

If PHP cannot run, search with `rg -n 'function upsert' vendor/tinymvc/tinycore/src`, then inspect the matching file and related traits. For exact installed version, use Composer lock/installed metadata; the version constraint in `composer.json` is insufficient.

## Inspect the application and its commands

From a configured application root:

```bash
php -v
php -m
php spark help --no-ansi
php spark route:list --no-ansi
php spark help make:model --no-ansi
```

Spark commands boot the application and its providers. If bootstrap is broken or unavailable, inspect `src/Foundation/Providers/ConsoleServiceProvider.php` in the installed package and the app's command/provider registrations instead. The TinyCore checkout has no application `spark` entrypoint. `route:list` shows methods, paths, and names; inspect route definitions for middleware and binding details.

Use existing `make:*` generators when creating application files, checking their handler/stub for supported arguments. Do not copy Artisan flags: command help may contain only a description. Generators can also update bootstrap registrations (for example `make:provider`); review those changes. Use the app's configured logs and schema/migrations for diagnosis; `tinker` executes real application code and is not a read-only inspector.

## Check optional capabilities

Core requires PHP 8.2+. Its `composer.json` `suggest` entries identify dependencies that are not guaranteed to be installed. Check installed packages/extensions before relying on the feature:

| Feature | Dependency |
| --- | --- |
| `Str::slug()`, ASCII helpers | `voku/portable-ascii` |
| `Str::uuid()` | `ramsey/uuid` |
| Markdown rendering | `league/commonmark` |
| Mail utility/facade | `phpmailer/phpmailer` |
| HTTP client / S3 | `ext-curl`; S3 listing/copying also uses `ext-simplexml` |
| Upload MIME / images | `ext-fileinfo` / `ext-gd` |
| Redis backends | `ext-redis` |

Match the selected database's PDO extension as well. Read installed constraints for compatible versions; do not install or upgrade optional packages unless the task requires them. FireLine, Inertia, and Relay have separate package/client contracts: use their topic references and installed source.
