# Exact API discovery

> **Read when:** a signature, return type, optional parameter, or method location is uncertain.
> **Related:** [SKILL.md](../SKILL.md) · [Native feature map](native-feature-map.md) · [Workflow and testing](workflow-testing.md)
> Installed source is authoritative. Read method bodies for side effects, transactions, and compatibility.

Contents:

- [1. Find task guidance first](#1-find-task-guidance-first)
- [2. Look up declarations](#2-look-up-declarations)
- [3. Inspect the application and its commands](#3-inspect-the-application-and-its-commands)
- [4. Check optional capabilities](#4-check-optional-capabilities)

## 1. Find task guidance first

Use the topic links in `SKILL.md`, or search the installed references with focused terms:

```bash
rg -n -i 'cursor|pagination' vendor/tinymvc/tinycore/skills/tinymvc-spark/references
# without ripgrep:
grep -rniE 'cursor|pagination' vendor/tinymvc/tinycore/skills/tinymvc-spark/references
```

In a core checkout, use `skills/tinymvc-spark/references`. Read the relevant section, then look up uncertain declarations. Do not load the whole reference directory or dump every source method. This skill provides local guidance and declaration search, not a runtime MCP server or semantic documentation service.

## 2. Look up declarations

From the application root:

```bash
php vendor/tinymvc/tinycore/skills/tinymvc-spark/scripts/api-lookup.php upsert
php vendor/tinymvc/tinycore/skills/tinymvc-spark/scripts/api-lookup.php transaction --file=Database/
php vendor/tinymvc/tinycore/skills/tinymvc-spark/scripts/api-lookup.php whenLoaded
php vendor/tinymvc/tinycore/skills/tinymvc-spark/scripts/api-lookup.php PendingDispatch --limit=40
```

For explicitly requested TinyCore work, run from that checkout and select its source deliberately:

```bash
php skills/tinymvc-spark/scripts/api-lookup.php upsert --source=src
```

| Option | Effect |
| --- | --- |
| `--file=substring` | Narrow results to matching source paths |
| `--limit=1..200` | Output cap (default 25 declarations, with signatures and absolute locations) |
| `--source=/path/to/tinycore/src` | Explicitly select a checkout. The default is `vendor/tinymvc/tinycore/src` relative to the working directory, never an automatic sibling-checkout fallback |
| `--include-support` | Include the `Support/` directory (`Collection`, `Str`, `Arr`, helpers), omitted by default, e.g. `slug --include-support --file=Support/Str.php` |

Exit codes: `0` matches or help, `1` no matching declaration, `2` invalid arguments or source path.

The dependency-free tool reads declarations without booting the app, requiring source files, connecting to services, or executing application code.

**Interpreting results**
- Read the matching method body and its callers before relying on behavior. Follow trait imports, parent classes, `__call` / `__callStatic`, facade accessors, and application wrappers when a method is forwarded.
- The tool lists declared public methods and selected protected extension points. It is not a resolved inheritance graph or a full PHP analyzer, and it does not enumerate constants, properties, docblock magic methods, runtime macros, or optional integration packages.
- No match does not prove an API is unavailable.

**If PHP cannot run:** search with `rg -n 'function upsert' vendor/tinymvc/tinycore/src` (or `grep -rn`), then inspect the matching file and related traits. For the exact installed version use Composer lock/installed metadata; the `composer.json` constraint is not enough.

## 3. Inspect the application and its commands

From a configured application root:

```bash
php -v
php -m
php spark help --no-ansi
php spark route:list --no-ansi
php spark help make:model --no-ansi
```

- Spark commands boot the application and its providers. If bootstrap is broken or unavailable, inspect `src/Foundation/Providers/ConsoleServiceProvider.php` in the installed package and the app's command/provider registrations instead. The TinyCore checkout has no application `spark` entrypoint.
- `route:list` shows methods, paths, and names; read the route definitions for middleware and binding details.
- Use existing `make:*` generators when creating application files, and check their handler/stub for supported arguments. Do not copy Artisan flags: command help may contain only a description. Generators can also update bootstrap registrations (for example `make:provider`); review those changes.
- Diagnose with the app's configured logs and schema/migrations. `tinker` executes real application code and is not a read-only inspector.

## 4. Check optional capabilities

Core requires PHP 8.2+. Its `composer.json` `suggest` entries list dependencies that are not guaranteed to be installed. Check installed packages and extensions before relying on a feature:

| Feature | Dependency |
| --- | --- |
| `Str::slug()`, ASCII helpers | `voku/portable-ascii` |
| `Str::uuid()` | `ramsey/uuid` |
| Markdown rendering | `league/commonmark` |
| Mail utility/facade | `phpmailer/phpmailer` |
| HTTP client / S3 | `ext-curl`; S3 listing/copying also uses `ext-simplexml` |
| Upload MIME / images | `ext-fileinfo` / `ext-gd` |
| Redis backends | `ext-redis` |

Match the selected database's PDO extension as well. Read installed constraints for compatible versions, and do not install or upgrade optional packages unless the task requires them. FireLine, Inertia, and Relay have separate package/client contracts: use their topic references and installed source.
