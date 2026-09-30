# Vendored third-party code

Vendored (not CDN-loaded) so the CSP can stay `script-src 'self'`, and pinned by hash so
CI detects any modification (`scripts/check_frontend.py`).

| File | Package | Version | License | Source integrity (npm tarball) | File SHA-256 |
|---|---|---|---|---|---|
| qrcode.js | qrcode-generator | 1.4.4 | MIT | sha512-HM7yY8O2ilqhmULxGMpcHSF1EhJJ9yBj8gvDEuZ6M+KGJ0YY2hKpnXvRD+hZPLrDVck3ExIGhmPtSdcjC+guuw== | 18ae399f81182bc9de916e9c77b195df20cc58d6f2d55a62b085a299f1bf1780 |

Review notes: no DOM sinks (`innerHTML`, `document.write`, `eval`) are used by the code paths we call
(`qrcode(0,'M')`, `addData`, `make`, `getModuleCount`, `isDark`); we draw onto a canvas ourselves.
