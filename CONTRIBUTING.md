# Contributing

Thanks for helping improve Bachs for WHMCS. Keep changes focused on WHMCS payments.

## Set up locally

Clone the repository. Use PHP 8.2 or newer with PDO SQLite, SQLite3, OpenSSL, and pcntl, Node.js, Python 3.9 or newer, and Make. The race test uses pcntl, so run it on Linux, macOS, or WSL. No real API key, WHMCS license, Composer install, or npm install is needed for the local tests.

```sh
make test
make check
make package
```

`make test` runs PHP and client JavaScript tests. `make check` checks syntax, selected security rules, documentation links, and the old-PHP syntax target. `make package` runs those checks, then builds and checks the installation ZIP. Generated files stay in `dist/` and are not committed.

GitHub Actions is configured to run these checks on PHP 8.2, 8.3, and 8.4. That workflow tests the local harness; it does not prove licensed WHMCS or real Bachs compatibility.

## Code rules

- Use four spaces, clear names, and simple English comments that explain important decisions.
- Keep shipped PHP within the PHP 5.6 syntax target. Modern PHP is allowed in development-only tests and tools.
- Keep WHMCS-specific behavior in `src/Infrastructure/Whmcs/` and provider transport in `src/Infrastructure/Bachs/`.
- Keep amounts as exact decimal strings. Never use floats for payment decisions.
- Save an operation before sending a provider write. Reuse its request and retry key. An unknown result needs a status check, not a new charge.
- Send all incoming payment paths through `PaymentRecognition`. Search for the existing native transaction before recording money.
- Use native WHMCS helpers for accounting. Do not write to WHMCS financial tables directly.
- Require permission checks and native CSRF checks for protected actions.
- Keep keys, tokens, private URLs, customer records, and real webhook bodies out of source, tests, logs, and issues.
- Do not add merchant payout tools, split payments, or a separate subscription system.

## Submit a change

Open an issue for a large change first. For a pull request, explain the user-visible problem, what changes, and how you tested it. Add a focused test when changing money handling, access controls, retry behavior, or provider fields. Update the relevant guide if setup or behavior changes.

Read the [code guide](docs/architecture.md), [API notes](docs/provider-contract.md), and [test status](docs/test-report.md) before changing payment flows. Check current official API documentation instead of guessing an endpoint or response field.

## Test a real installation

Use a separate licensed WHMCS test site and a Bachs sandbox account. Follow the [installation guide](docs/installation.md) and [acceptance plan](docs/test-report.md). Keep resource IDs and customer evidence private. Public test reports should state exact versions, scenarios, and results without secrets.

## License

By submitting code, you agree that your contribution may be used under this project's [MIT license](LICENSE.txt).
