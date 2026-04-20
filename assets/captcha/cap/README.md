# Cap widget (vendored)

Vendored from:
- `@cap.js/widget` (see `VERSION`)
- `@cap.js/wasm@0.0.6` — `cap_wasm_bg.wasm`

Upstream: https://github.com/tiagozip/cap

## Updating

```bash
npm pack @cap.js/widget
npm pack @cap.js/wasm
# extract and copy cap.min.js, cap.d.ts, wasm-hashes.min.js, LICENSE
# and browser/cap_wasm_bg.wasm into this directory, then update VERSION.
```

## Why vendored

The upstream widget fetches its WASM module from `cdn.jsdelivr.net` by
default. We set `window.CAP_CUSTOM_WASM_URL` to the locally vendored
copy so Cap captcha works fully self-hosted with no third-party runtime
dependency — matching the privacy-preserving ethos of the project.
