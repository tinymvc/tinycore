# Realtime Broadcasting with Relay

> **Read when:** publishing broadcasts, authorizing channels, or operating a Relay daemon.
> **Related:** [Mail and HTTP](mail-http.md) · [Queues](queues.md) · [Auth and gate](auth-gate.md) · [Operations](operations.md)
> Relay and the Pusher SDK are optional. Verify installed `tinymvc/relay` and `shahinmoyshan/pusher-php-server` sources/READMEs; sibling checkouts can be unreleased.

Contents:

- [Register and publish](#register-and-publish)
- [Authorize private and presence channels](#authorize-private-and-presence-channels)
- [Client protocol and cache channels](#client-protocol-and-cache-channels)
- [Deployment, monitoring, and restart](#deployment-monitoring-and-restart)
- [Verify package changes](#verify-package-changes)

## Register and publish

Add `Spark\Relay\RelayServiceProvider` to the existing `bootstrap/providers.php`; configure `config/relay.php`. It binds `Spark\Relay\Relay`/`relay` and registers `relay:start`/`relay:restart`. Facade: `Spark\Relay\Facades\Relay`.

`Relay::trigger($channels, $event, $data, $socketId)` publishes through the first configured app. For another app, construct the service with an explicit `Spark\Relay\App` and client configuration. Keep credentials out of request input/frontend bundles.

The SDK's `*Async()` methods prepare TinyCore HttpRequests; `execute()`/`pool()` block. Do not call them on Relay's event loop; use a Spark job for background application broadcasts. Reviewed SDK versions disable retries for signed requests; verify installed behavior. Explicit/job retries still need duplicate handling. Delivery, replay, and cross-broadcast transactions are not guaranteed.

## Authorize private and presence channels

Use an authenticated, CSRF-protected POST route and `Spark\Relay\ChannelAuth` rules loaded by the app. Patterns such as `private-orders.{id}` must check that user's access; unknown patterns deny. Presence rules return `['id' => (string) $user->id, 'info' => [...]]` with only public fields.

Validate socket_id/channel_name as strings. `Relay::authorize($channel, $socketId, $presence)` returns a **JSON string**: return it through `response($json, Response::STATUS_OK, [Header::CONTENT_TYPE => 'application/json'])`, not `json($json)`. Signing is not authorization. Presence requires a nonempty string/integer ID (zero is valid); authorization occurs at subscription, not on every later event.

## Client protocol and cache channels

Use `pusher-js`; Echo is optional. Relay supports a subset of Pusher Channels: public/private/presence subscriptions, event/batch publishing, channel/user queries, client events, heartbeats, and bounded cache channels. Do not assume cloud sign-in/termination, webhooks, HTTP fallback, or Reverb compatibility.

Cache prefixes: `cache-`, `private-cache-`, `presence-cache-`, `private-encrypted-cache-`. Authorize private/presence variants normally. Backend events replace the last retained snapshot even without subscribers; subscription sends it or `pusher:cache_miss`. Bind both handlers immediately. Full snapshots with versions handle HTTP/realtime races. Client events do not populate the cache.

`cache_ttl`, `max_cached_channels`, and `max_cache_size` bound retention. Cache clears on restart; it is neither durable replay nor the application cache API. Reconnecting clients should reload authoritative state.

## Deployment, monitoring, and restart

- One daemon owns channel state. Use one instance per app's traffic unless the installed version explicitly supports distributed fan-out.
- Terminate TLS/throttle connections at the proxy. Expose `/app/` for WebSockets; keep `/apps/` backend-only. Restrict origins and set finite connection/memory limits.
- `Relay::stats()` calls the signed app-specific stats endpoint. It reports app-isolated connections, subscriptions, channels/cache, and process-lifetime message/byte counters. Sent means queued output, not acknowledged delivery. `/health` is a minimal unauthenticated loop/HTTP health check; store history externally.
- `relay:restart` writes a local marker; daemons close clients with code 4200 and exit. A supervisor starts replacements. Run commands as the daemon OS user. New daemons ignore the existing marker until changed.
- Default marker: `storage_dir() . 'relay.restart'`. Release directories need stable absolute `relay.server.restart_file` outside the public root on shared local storage. Restart does not add Redis scaling or coordinate independent registries.

## Verify package changes

Use each package's `php tests/run.php`; Relay also has `npm test` and `npm run test:load` with temporary localhost servers. `RELAY_PUSHER_SOURCE=../pusher-php-server` selects a sibling SDK for coordinated tests. Preserve package boundaries and release dependencies before expecting Composer consumers to receive joint changes.
