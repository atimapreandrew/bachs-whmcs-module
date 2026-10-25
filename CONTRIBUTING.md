# Contributing

Keep changes focused on WHMCS payments. For a large change, open an issue first. Pull requests should explain the problem, the change, and the tests run.

## Build and test

Use PHP 8.2 or newer with PDO SQLite, SQLite3, OpenSSL, and pcntl; Node.js; Python 3.9 or newer; and Make. Linux, macOS, or WSL is needed for the process-race test. These are development tools, not WHMCS server requirements. No Composer/npm install or real API key is needed for local tests.

```sh
make test
make check
make package
```

The package is written to `dist/bachs-whmcs-1.0.0.zip`, with a checksum file. Upload that package's contents to WHMCS, not the source checkout. Generated files are ignored by Git. No GitHub workflow is required.

## Code rules

- Use clear names, four spaces, and simple English.
- Keep shipped PHP within the PHP 5.6 syntax target; use the PHP version required by WHMCS at runtime.
- Keep money as exact decimal strings, never floats.
- Save provider writes before sending them. Check an unknown result instead of starting a replacement charge.
- Route payment recognition through the shared service and use native WHMCS accounting helpers.
- Preserve webhook signatures, invoice ownership, staff permissions, CSRF, encryption, and consent checks.
- Add focused tests for changes to money, permissions, retries, or provider fields.
- Keep secrets and real customer data out of code, fixtures, logs, and issues.

See the [developer guide](docs/development.md) for the code map and test limits. Contributions use this project's [MIT license](LICENSE.txt).
