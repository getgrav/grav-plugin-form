# cap-php

PHP port of the [Cap](https://github.com/tiagozip/cap) proof-of-work captcha server.

Wire-compatible with the official [`@cap.js/widget`](https://www.npmjs.com/package/@cap.js/widget), so the unmodified JS widget can talk to a PHP-backed endpoint.

- SHA-256 proof-of-work — no tracking, no third-party calls, no API keys
- No runtime dependencies beyond ext-json / ext-hash
- Pluggable storage: in-memory, filesystem, or any PSR-16 cache

## Install

```bash
composer require trilbymedia/cap-php
```

## Usage

```php
use TrilbyMedia\Cap\Cap;
use TrilbyMedia\Cap\Config;
use TrilbyMedia\Cap\Storage\FilesystemStorage;

$storage = new FilesystemStorage('/var/lib/cap');
$cap = new Cap(new Config(
    challengeStorage: $storage,
    tokenStorage:     $storage,
));

// In your /challenge endpoint:
$result = $cap->createChallenge();
// echo json_encode($result);

// In your /redeem endpoint (body: {"token":"...","solutions":[...]}):
$result = $cap->redeemChallenge($token, $solutions);
// echo json_encode($result);

// When validating a form submission that carried a cap token:
if ($cap->validateToken($submittedToken)) {
    // success
}
```

## Defaults

| Option               | Default  | Meaning                                     |
| -------------------- | -------- | ------------------------------------------- |
| challengeCount       | 50       | Number of sub-challenges per captcha        |
| challengeSize        | 32       | Salt length (hex chars)                     |
| challengeDifficulty  | 4        | Target prefix length (hex chars)            |
| expiresMs            | 600 000  | Challenge TTL (10 min)                      |
| tokenTtlMs           | 1 200 000 | Verification token TTL (20 min)             |

## Protocol compatibility

The PRNG and hashing are bit-exact with upstream `server/index.js` — verified by `tests/fixtures/prng-vectors.json`, a set of vectors generated directly from the upstream JS implementation.

## License

Apache-2.0, matching upstream.

## Browser instrumentation (opt-in)

The default challenge and verification token formats remain compatible with widget 0.1.57. Instrumentation adds a unique JavaScript program to each challenge. Its private answer and binding keys stay in challenge storage. The browser runs upstream's native checks, browser probes, arithmetic and DOM operations; PHP verifies the challenge ID and every bound field before issuing a token. This is a deterrent to automated clients, not proof that a human is present.

```php
use TrilbyMedia\Cap\InstrumentationOptions;

$cap = new Cap(new Config(
    challengeStorage: $storage,
    tokenStorage: $storage,
    instrumentation: new InstrumentationOptions(
        blockAutomatedBrowsers: true,
        detectAutomatedInteractions: true,
        blockAutomatedInteractions: false,
    ),
));

// Forward the entire decoded widget body, including failure markers:
$result = $cap->redeemChallengeBody($body);
// Or retain the existing call and pass the widget's extra fields:
$result = $cap->redeemChallenge($body['token'], $body['solutions'], $body);
```

`instrumentation: true` enables checks and risk reporting without blocking detected headless browsers. `false` is the default. `ChallengeOptions(instrumentation: ...)` overrides the policy per challenge. Policy is captured when the challenge is issued. Missing output, altered data, timeouts, blocked markers and script errors always fail; they cannot be used to bypass instrumentation. Failures include `success: false`, `message`, `error`, `instr_error: true` and `reason`; successes include `riskFlags` when instrumentation ran. HTTP adapters should return 403 for instrumentation failures (429 for `instr_timeout`) and JSON with `Cache-Control: no-store`.

Basic instrumentation is supported with widget 0.1.57. Host-page checks and interaction collection require widget 0.1.59 or later; use 0.1.61, the version used for current interoperability checks. Earlier widgets can run the basic program in an isolated frame; policies requiring host access reject that result. Instrumentation first appeared upstream in widget 0.1.37; versions before 0.1.57 have not been validated against this PHP implementation. Three synthetic clicks or keydowns trigger the optional interaction block. The collector ignores sensitive fields and never reads field values. Use `window.CAP_SCRIPT_NONCE` with a strict CSP. `window.CAP_ISOLATE = true` limits access to the host page and is incompatible with policies requiring that access.

The PHP generator uses upstream capjs-core 0.1.11's browser code and server verification rules. It randomizes its arithmetic, answer variables and binding keys per request, but does not provide upstream's optional esbuild/javascript-obfuscator levels. ext-zlib is used for compression when available; a valid uncompressed DEFLATE encoding is used otherwise. No Node process is required. See [browser code provenance](src/Instrumentation/UPSTREAM.md).

## Experimental behavior and agent policies

```php
use TrilbyMedia\Cap\BehaviorOptions;

$cap = new Cap(new Config(
    challengeStorage: $storage,
    tokenStorage: $storage,
    behavior: new BehaviorOptions(
        block: true,
        strict: false,
        requireInteraction: true,
        threshold: 0.75,
        blockAgents: 'identified', // false, 'identified' (high confidence), or 'all'
    ),
));
$result = $cap->redeemChallengeBody($body, $requestHeaders);
```

`behavior: true` reports scores without blocking. `BehaviorOptions` and `ChallengeOptions(behavior: ...)` allow explicit policies. Behavior automatically enables instrumentation and adds `behavior: true` to the challenge. The bound collector records pointer paths and key timing, never key identities or field values. Results include `behavior` with `score`, `verdict`, `flags` and diagnostic `signals`. Agent evidence can add `agent:<name>` to `riskFlags` and an `agent` result. Supply actual request headers as the separate second argument to `redeemChallengeBody` (fourth argument to `redeemChallenge`); never take these from the JSON body.

Use widget 0.1.59 or later for behavior collection. Blocking with `requireInteraction: true` rejects invisible/programmatic `solve()` calls without a widget press, so set it to false for invisible mode. Old/isolated widgets can report missing behavior but fail enforced policies with `instr_widget_outdated` or `instr_isolated`. Behavior thresholds must be between 0.1 and 1; weak signals are capped at 0.6 unless `strict` is enabled.

An optional `Config(behaviorReplayCheck: $closure)` receives the trace digest and its retention period in milliseconds and returns true when the digest was seen before. Use an atomic cache claim if enabling this callback. As upstream, callback exceptions leave the advisory check disabled for that attempt. Challenge and verification token replay protection still apply independently.

Local agent detection uses bound page artifacts, click/typing patterns and supplied request headers. `Signature-Agent` headers are evidence only: this library does not fetch key directories or verify Web Bot Auth signatures, and it always reports `verified: false`. There is no verified-agent bypass.

## Protocol lists and scopes

`Config(format: 2)` or `ChallengeOptions(format: 2)` opts into upstream's protocol-list envelope:

```json
{"format":2,"token":"...","expires":1234567890000,"challenges":[{"protocol":"sha256-pow","payload":{"salt":"...","target":"0000"}},{"protocol":"instrumentation","payload":{"blob":"..."}}]}
```

Solutions are an equally sized list of `{"nonce":123}` and `{"instr":{...}}` objects, in challenge order. Instrumentation failure entries use `blocked`, `error` or `timeout`. Pass them unchanged to `redeemChallengeBody`. Both tested widgets 0.1.57 and 0.1.61 support these two protocols. Format 1 remains the default; there is no automatic protocol negotiation or new endpoint.

`challengeSize` means salt length in hex characters in **both** PHP formats. Upstream format 2 instead treats it as random bytes, giving a 64-character salt at its default size 32; its current SHA-256 WASM solver cannot solve messages of 56 bytes or more. PHP deliberately retains 32 hex characters at the default. Keep the salt plus decimal nonce below 56 characters when using that WASM solver; sizes above the default can exceed its limit. Parameter bounds are count 1–1000, size 1–256, difficulty 1–16, checked when issuing a challenge. Widget requests must provide an exact, sequential solution list; legacy nonces must be nonnegative JS-safe integers. Invalid configuration throws `InvalidArgumentException`. Existing constructors and the first three positional `ChallengeOptions` arguments are unchanged.

`Config(scope: 'form-name')` binds challenges and verification tokens to that instance's scope. Instances can share storage; a token issued in one scope fails validation in another without consuming it. Token text remains `id:secret`. A wrong-scope redeem consumes the challenge, consistent with PHP's consume-on-attempt policy. Scope is optional; the default remains unscoped.

This library remains stateful. It does not issue or accept capjs-core's signed JWT challenges, provide custom token-signing hooks, or embed arbitrary extra data in JWTs. The widget treats challenge tokens as opaque strings, so these server internals do not affect widget interoperability. HashWX and RSW are **not implemented**: they require WASM/native execution or large-integer arithmetic beyond this library's ext-json/ext-hash requirements. It does not reproduce capd's HTTP site-key service, network signature verification, proxy/IP policy, rate limiter, rule engine, dashboard or telemetry. Applications continue to own routing, request limits, rate policy, secret authentication and HTTP status codes; local token validation is `validateToken`.

## Storage and concurrent replay

`ArrayStorage` and `FilesystemStorage` implement optional `AtomicChallengeStorageInterface::consumeChallenge` and `AtomicTokenStorageInterface::consumeToken`. Cap uses these when available. Filesystem mutations use stable `.lock` files and atomic renames, so separate PHP processes cannot redeem the same challenge or consume the same verification token twice. All writers must use the updated class and a filesystem supporting advisory locks. Corrupt/unreadable JSON or failed writes now throw instead of silently discarding records. Keep storage outside the web root.

Existing storage interfaces and all three backends still accept arbitrary arrays containing an `expires` key, including Grav's rate/binding records. Redeem rejects non-challenge token names without reading or deleting those records. PSR-16 has no atomic read-and-delete operation; `Psr16Storage` and older custom adapters retain their read/delete fallback, which does not guarantee single use under concurrency. For that guarantee, implement the two optional interfaces with an atomic backend operation, such as Redis GETDEL. `keepToken: true` intentionally permits reuse.

Challenge expiry remains 10 minutes; `tokenTtlMs` is configurable and defaults to 1,200,000 milliseconds. Cleanup remains lazy every five minutes unless `disableAutoCleanup` is true; `cleanup()` can be called explicitly. PSR-16 uses backend TTL eviction. The library performs no background network requests.
