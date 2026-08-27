# Contributing to Teleproto

Thank you for considering contributing to Teleproto!

## Development Setup

1. **Clone the repository:**
   ```bash
   git clone https://github.com/MeRezaRezaei/teleproto.git
   cd teleproto
   ```

2. **Install dependencies:**
   ```bash
   composer install
   ```

3. **Run tests:**
   ```bash
   ./vendor/bin/phpunit
   ```

## Pull Request Guidelines

- Ensure all new features and bug fixes include corresponding unit tests under `tests/`.
- Verify that `composer validate --strict` passes.
- Adhere to PSR-12 coding standards and strict type declarations (`declare(strict_types=1);`).
- Keep changes zero-dependency and low-level focused. Higher-level abstractions belong in downstream packages.
