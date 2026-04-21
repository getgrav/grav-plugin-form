# cap-php

PHP port of the [Cap](https://github.com/tiagozip/cap) proof-of-work captcha server.

Wire-compatible with the official [`@cap.js/widget`](https://www.npmjs.com/package/@cap.js/widget), so the unmodified JS widget can talk to a PHP-backed endpoint.

- SHA-256 proof-of-work — no tracking, no third-party calls, no API keys
- Small (~500 LOC), no runtime dependencies beyond ext-json / ext-hash
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
| token TTL            | 20 min   | Validation token TTL (not configurable)     |

## Protocol compatibility

The PRNG and hashing are bit-exact with upstream `server/index.js` — verified by `tests/fixtures/prng-vectors.json`, a set of vectors generated directly from the upstream JS implementation.

## License

Apache-2.0, matching upstream.
