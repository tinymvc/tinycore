# Realtime broadcasting with Relay

Relay and the Pusher SDK are optional packages, not TinyCore APIs. Inspect the application's installed `vendor/tinymvc/relay` and `vendor/shahinmoyshan/pusher-php-server` sources and READMEs before assuming a feature or limit. A sibling checkout may contain unreleased changes.

Register `Spark\Relay\RelayServiceProvider` in the existing `bootstrap/providers.php` list and configure `config/relay.php`. The provider binds `Spark\Relay\Relay` and its `relay` alias and registers `php spark relay:start` and `php spark relay:restart`. The facade is `Spark\Relay\Facades\Relay`.

Use `Relay::trigger($channels, $event, $data, $socketId)` to publish. The default service uses the first configured app. Select other apps by constructing `Spark\Relay\Relay` with an explicit `Spark\Relay\App` and client configuration. Keep credentials out of request-controlled input and frontend bundles.

Authorize private/presence subscriptions in an application POST route protected by the application's authentication and CSRF middleware. Define `Spark\Relay\ChannelAuth` rules in a provider or explicitly loaded channel file. A pattern such as `private-orders.{id}` matches a channel; return true only after checking the user's access. Presence rules return `['id' => (string) $user->id, 'info' => [...]]`. Expose only intended presence fields. Unknown patterns deny authorization.

Validate `socket_id` and `channel_name` as strings before calling the authorizer. `Relay::authorize($channel, $socketId, $presence)` returns a JSON string; return it with `response($json, \Spark\Http\Response::STATUS_OK, ['Content-Type' => 'application/json'])`, not `json($json)`. Signing alone does not authorize a user. Presence requires a non-empty string/integer ID (zero is valid); authorization is checked on subscription, not on each later event.

The SDK uses `Spark\Http\Client\HttpRequest` and `HttpPool`. Its `*Async()` methods prepare requests without sending them. `execute()` and `pool()` wait for completion; they are not ReactPHP promises and must not block Relay's event loop. Dispatch a normal Spark job for asynchronous application broadcasts.

Reviewed SDK versions disable automatic retries for signed requests because a lost response can cause duplicate publishing. Inspect installed behavior; explicit retries and job retries still need application-level duplicate handling. Do not assume durable delivery, replay or transactions across broadcasts.

Relay keeps channel state in one daemon. Run one instance for a given app's traffic unless the installed version explicitly implements distributed fan-out. Put TLS and connection throttling at the reverse proxy, expose `/app/` for WebSockets, and keep `/apps/` on the backend network. Restrict origins and configure finite connection/memory limits appropriate for the event-loop backend. Reconnect clients should reload authoritative application state.

Relay supports a subset of Pusher Channels: public/private/presence subscriptions, event and batch publishing, channel/user queries, client events, heartbeats and bounded cache channels. Use `pusher-js` directly; Echo is not required. Do not assume Pusher cloud user sign-in/termination, webhooks, HTTP fallback or Laravel Reverb compatibility beyond the installed implementation.

Cache channels use `cache-`, `private-cache-`, `presence-cache-` or `private-encrypted-cache-`. Authorize private/presence variants normally. Backend events replace the last retained snapshot, including while there are no subscribers. After subscription success, the daemon sends that snapshot or `pusher:cache_miss`; bind both application state and cache-miss handlers immediately. Use full snapshots with versions when HTTP fallback can race realtime updates. Client events never populate this cache. App `cache_ttl`, `max_cached_channels` and `max_cache_size` bound retention; cache state is ephemeral and clears on restart. This is not durable replay or an application cache API.

`Relay::stats()` uses the signed, Relay-specific `/apps/{id}/stats` API through TinyCore HTTP. It reports app-isolated connections, subscriptions, channels, cache usage and process-lifetime WebSocket message/byte counters. Sent counts mean queued output, not delivery acknowledgement. `/health` is a minimal unauthenticated event-loop/HTTP health check. Keep monitoring on the backend network; retain history externally.

`relay:restart` writes a local marker and asks existing daemons to close clients with reconnect code 4200 and exit. A supervisor with restart enabled must start the replacement. Run deployment commands as the daemon's OS user. Default marker: `storage_dir() . 'relay.restart'`; for release directories use a stable absolute `relay.server.restart_file` outside the public root on shared local storage. New workers ignore the existing marker until it changes. Restart does not coordinate multiple independent channel registries or add Redis scaling.

For package work, use each repository's `php tests/run.php`. Relay also supplies `npm test` and `npm run test:load`; `RELAY_PUSHER_SOURCE=../pusher-php-server` selects a sibling SDK checkout for joint verification. These run temporary localhost servers. Preserve separate package dependency boundaries and release coordinated changes before expecting Composer users to receive them.
