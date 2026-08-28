# Support

## Where to ask

- **Bugs and feature requests** — [open a GitHub issue](https://github.com/MeRezaRezaei/teleproto/issues).
  Please use the issue templates and include a minimal code snippet, your PHP version (8.2+), and the full stack trace.
- **Questions and usage help** — GitHub issues are the support channel for now (GitHub Discussions are not enabled).
  Search existing issues first, then open one labeled as a question.
- **Documentation** — start with the [README](https://github.com/MeRezaRezaei/teleproto#readme) and the [docs/](https://github.com/MeRezaRezaei/teleproto/tree/main/docs) folder (`quickstart.md`, `bot-client.md`, `user-client.md`, `telegram-passport.md`, `scaling.md`).
- **Live connectivity check** — run `vendor/bin/teleproto doctor` (no Telegram account needed) before reporting connection issues.

## Versioning

This project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

- Patch releases (`1.0.x`): bug fixes only, no BC breaks.
- Minor releases (`1.x`): new features, no BC breaks within the documented public API.
- Major releases (`x.0`): BC breaks are allowed and will be called out in the [CHANGELOG](../CHANGELOG.md).

Requires PHP 8.2+. Supported PHP versions track the CI matrix (currently 8.2, 8.3, 8.4).

## Security

Found a vulnerability? Please do **not** open a public issue — follow the policy in [SECURITY.md](../SECURITY.md).
