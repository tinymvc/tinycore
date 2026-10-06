# TinyCore/Spark
The Backbone/Core Classes and Functionalities for TinyMvc/Spark Framework.

## Introduction

**TinyCore/Spark** is the backbone of the TinyMvc Framework, providing essential core classes, utility functions, and helper methods to streamline for simplified web development.

* [Learn More](https://tinymvc.github.io)

## Tests

```sh
composer install
composer test
composer test -- --testsuite Feature
composer test -- --testsuite Unit
composer test -- --filter HttpTransportLifecycle
```

The suite uses TinyCore's own `Spark\Testing\Runner`, `ApplicationTestCase`, and assertions. Its scope is the HTTP request lifecycle:

| Area | Coverage |
| --- | --- |
| Request construction | Query and body input, write methods, method overrides, malformed input, CGI content headers, and request identity through helpers and dependency injection |
| Application boot | Provider registration and boot order, boot failures and retries, and request-scoped service resets |
| Dispatch | Route parameters, fallback responses, middleware ordering, exclusions, short circuits, and early responses |
| Response preparation | Return-value normalization, media types, header casing, callback ordering, recursion, repeated preparation, and reuse across requests |
| Exceptions | Built-in HTTP mappings, HTML/JSON negotiation, validation redirects, custom handlers, unexpected failures, and recovery on the next request |
| Termination | Deferred callback order and injection, nested callbacks, error reporting, repeated termination, and lifecycle events |
| HTTP transport | Real JSON/form bodies, custom request bindings in `run()`, emitted headers and bodies, HEAD, bodyless statuses, redirects, early exits, and shutdown callbacks |

Each feature test uses an isolated application and temporary storage. The transport tests start and stop a local PHP development server on an OS-selected loopback port; they require `proc_open` and permission to open local sockets. They read actual HTTP bytes rather than passing the result through response preparation again. No external server or database is required.

Run `composer test -- --filter RequestLifecycle` to select a class, or use `--filter` with part of a test method name. `composer test -- --list-tests` lists the available cases. The transport tests exercise PHP's built-in server; FastCGI-specific flushing remains dependent on the deployment environment.
