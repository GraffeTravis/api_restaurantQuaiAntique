# Contributing

## Local setup

Use PHP 8.4+, Composer, and PostgreSQL. Docker Compose starts PostgreSQL 18 for a local disposable database; see `Docker.md` for environment variables and persistent-volume behavior. Keep local secrets in ignored `.env.local` or `.env.docker` files.

## Changes

- Start from an up-to-date `main` and make a focused branch for the change.
- Inspect the affected routes, OpenAPI schema, persistence mappings, tests, and deployment configuration before changing behavior.
- Add negative tests for invalid user input and contract tests when request/response shapes change.
- Add reversible Doctrine migrations for schema changes. Never edit an already deployed migration.
- Never load fixtures against production. Use `RUN_DB_TESTS=1` only with an isolated database ending in `_test`.
- Keep secrets and real customer data out of commits, logs, fixtures, and tests.
- Use separate, descriptive commits for independent fixes.

## Verification

```bash
composer validate --no-check-publish
composer audit --locked
php bin/console lint:yaml config/packages
php bin/console lint:container --env=test
php vendor/bin/phpunit
```

Database workflow tests are skipped unless `RUN_DB_TESTS=1` is set and `DATABASE_URL` targets a disposable `*_test` database. Apply the test migration configuration before running those tests. CI runs the full suite with PostgreSQL.
