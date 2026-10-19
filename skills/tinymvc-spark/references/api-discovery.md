# Exact API discovery

Use when a signature, return type, optional parameter or method location is uncertain. The installed source is authoritative for the application's version. Behavioral references explain decisions; a declaration list cannot establish side effects, transaction scope or compatibility.

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

Support helpers are omitted by default to keep Spark searches focused. Pass `--include-support` when investigating those helpers. Exit codes: 0 for matches/help, 1 for no matching declaration, 2 for invalid arguments/source path.

Read the matching method body and its callers before relying on behavior. Follow trait imports, parent classes, `__call`/`__callStatic`, facade accessors and application wrappers when a method is forwarded. The tool reports declared public methods and selected protected extension points; it is not a resolved inheritance graph or a complete PHP semantic analyzer. No match does not prove an API is unavailable. It does not enumerate constants, properties, docblock magic methods, runtime macros or optional integration packages.

If PHP cannot run, search with `rg -n 'function upsert' vendor/tinymvc/tinycore/src`, then inspect the matching file and related traits. For exact installed version, use Composer lock/installed metadata; the version constraint in `composer.json` is insufficient.
