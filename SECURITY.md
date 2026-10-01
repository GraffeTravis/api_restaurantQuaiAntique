# Security Policy

## Supported deployment

Security fixes are prepared for the current `main` branch and its production deployment. Keep PHP, Symfony, PostgreSQL, Node.js, and container base images updated through reviewed dependency changes.

## Reporting a vulnerability

Do not publish secrets, personal data, exploit details, or production database contents in an issue. Use GitHub's private vulnerability reporting for this repository, or contact the repository owner privately. Include the affected route/version, impact, and a minimal reproduction without real user data.

## Authentication and data handling

- Browser deployments request `X-AUTH-SESSION: cookie`; the API returns the session only as an `HttpOnly` cookie and does not include the bearer value in JSON.
- HTTPS deployments use `Secure` cookies. Production uses `SameSite=Lax`; the Vercel preview origin uses `SameSite=None` when configured and must be narrowly allowed in `CORS_ALLOW_ORIGIN`.
- Mutating requests authenticated by a cookie require an `Origin` matching `CORS_ALLOW_ORIGIN`. Keep that value limited to known front-end origins; it is not a wildcard.
- `X-AUTH-TOKEN` remains available for existing non-browser clients. Do not put bearer tokens in URLs, logs, source control, or browser storage.
- API tokens are stored as SHA-256 hashes and have server-enforced expiration and revocation. Passwords are stored through Symfony's password hasher.
- Never run `doctrine:fixtures:load` against production. Use the idempotent `app:seed-production` command only for deliberate initial provisioning.
- Do not use production data in test, preview, or local environments. Use a dedicated `*_test` database for destructive tests.

## Deployment checks

Before release, review `composer audit`, test migrations against a clean PostgreSQL database, verify the cookie/CORS origin configuration, and confirm the API's rate-limit storage is available. Keep `APP_SECRET`, database credentials, and initial administrator credentials in the hosting provider's secret configuration, never in Git.
