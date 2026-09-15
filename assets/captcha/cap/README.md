# Cap widget (vendored)

Vendored from:
- `@cap.js/widget` (see `VERSION`)
- `@cap.js/wasm@0.0.7` — `cap_wasm_bg.wasm`
- `pako@2.1.0` — `pako_inflate.min.js` (+ `LICENSE.pako`)

Upstream: https://github.com/tiagozip/cap

## Updating

```bash
npm pack @cap.js/widget
npm pack @cap.js/wasm
npm pack pako
# extract and copy cap.min.js, cap.d.ts, wasm-hashes.min.js, LICENSE,
# browser/cap_wasm_bg.wasm and dist/pako_inflate.min.js into this
# directory, then update VERSION.
#
# Check the widget's default CDN URLs after every bump:
#   grep -oE 'https?://[^"'"'"']+' cap.min.js
# Each one it can reach at runtime needs a vendored copy and a window.CAP_*
# override in templates/forms/fields/cap/cap.html.twig, or the widget stops
# being self-hosted. 0.1.57 added the pako fallback that way.
```

## Why vendored

The upstream widget fetches its WASM module from `cdn.jsdelivr.net` by
default, and falls back to fetching pako from there too on a browser with
no `DecompressionStream` (Safari before 16.4, Firefox before 113). We set
`window.CAP_CUSTOM_WASM_URL` and `window.CAP_PAKO_URL` to the locally
vendored copies so Cap captcha works fully self-hosted with no third-party
runtime dependency — matching the privacy-preserving ethos of the project.
