# Docker - Quai Antique

This guide covers the two independent Git repositories:

- Front: `QuaiAntiqueRestaurantFront`
- Back: `QuaiAntiqueRestaurantBack/restaurantQuaiAntique`

Docker Compose runs the static front and the Symfony API as separate stacks. The Back stack includes PostgreSQL 18. The database is reachable by the API on the private Compose network and is not published to the host by default. The API is published on `127.0.0.1:8000`; the front is published on `127.0.0.1:8080`.

## Prerequisites

Install Docker Desktop (Windows/macOS) or Docker Engine with the Compose v2 plugin (Linux). On Windows, use Linux containers and run the commands below in PowerShell from the relevant repository directory. The first build needs internet access to download the base images and PHP/Node dependencies. Later starts use the local images and named volumes.

## Start the Back

From the Back repository root (`QuaiAntiqueRestaurantBack/restaurantQuaiAntique`):

```powershell
Copy-Item .env.docker.example .env.docker
```

Edit `.env.docker` before starting. Generate two different random hexadecimal values, one for `POSTGRES_PASSWORD` and one for `APP_SECRET`. For example, run this twice and paste each result into the matching variable:

```powershell
docker run --rm php:8.4-cli php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Keep `.env.docker` private. It is ignored by Git and excluded from the image build context. A hexadecimal database password avoids URL-encoding errors in Doctrine's `DATABASE_URL`. Never reuse production credentials or restore production data into this local stack.

Build the API image and start PostgreSQL:

```powershell
docker compose --env-file .env.docker build api
docker compose --env-file .env.docker up -d database
```

Wait until PostgreSQL is healthy, then apply the repository's Doctrine migrations:

```powershell
docker compose --env-file .env.docker run --rm api php bin/console doctrine:migrations:migrate --no-interaction
```

Start the API:

```powershell
docker compose --env-file .env.docker up -d api
docker compose --env-file .env.docker ps
```

The API is available at `http://localhost:8000`. Migrations are deliberately manual by default. Set `MIGRATE_ON_START=1` only when automatic migration at each API container start is specifically desired. A fresh database contains the schema after migrations; it does not automatically load fixtures or production content.

### Database access for local tools

PostgreSQL is intentionally not exposed on the host by the default Compose file. For a local database client, add the development-only override:

```powershell
docker compose --env-file .env.docker -f compose.yaml -f compose.dev.yaml up -d
```

This binds the database port to `127.0.0.1` only. Set `POSTGRES_PORT` in `.env.docker` if local port 5432 is already occupied. You can also run SQL without publishing a port:

```powershell
docker compose --env-file .env.docker exec database psql -U quaiantique -d quaiantique
```

## Start the Front

From the Front repository root (`QuaiAntiqueRestaurantFront`):

```powershell
Copy-Item .env.docker.example .env.docker
docker compose --env-file .env.docker up -d --build
```

Open `http://localhost:8080`. The same image can be used on another machine; set `API_BASE_URL` to the API URL that the visitor's browser can reach, ending in `/api/`. For example, use `http://localhost:8000/api/` when front and API are used on one computer. For another device on a trusted LAN, use the server's LAN name or IP instead of `localhost`.

The API address is browser-facing. Do not set it to the Compose-only hostname `database` or `api`: those names resolve inside Docker, not in a visitor's browser. The Back must allow the front's browser origin in `CORS_ALLOW_ORIGIN`. The default permits localhost origins; for a LAN front, add its exact host/IP to that regular expression. Avoid broad `.*` CORS rules.

The front image bundles Bootstrap's JavaScript. Google Fonts and the Bootstrap Icons stylesheet remain external assets in the existing site, so typography and icons may fall back if a visitor has no internet connection; they do not prevent the containers or main site from starting.

## Run on another device

The default bind address is loopback-only for both applications. This prevents accidental LAN exposure. For a trusted private network, configure the Back's `API_BIND=0.0.0.0`, the Front's `FRONT_BIND=0.0.0.0`, set the Front's `API_BASE_URL` to the Back machine's reachable LAN URL, and allow only the Front origin in the Back's CORS expression. Restrict access with the host firewall. Do not expose either plain-HTTP development endpoint directly to the public internet. A public deployment needs HTTPS via a maintained reverse proxy, a firewall, managed secrets, monitoring, and an off-host backup plan.

## Data persistence and backups

The Back declares three named volumes:

- `postgres_data`: PostgreSQL 18 data, mounted at `/var/lib/postgresql` (the correct parent mount point for the official PostgreSQL 18 image).
- `restaurant_uploads`: legacy VichUploader files under `public/uploads/restaurants`.

The current gallery upload endpoint stores image bytes in PostgreSQL. Its images therefore live in `postgres_data`; older VichUploader file paths live in `restaurant_uploads`. Both stores matter if older records still refer to files on disk. Recreating a container or running `docker compose down` keeps named volumes. **Do not use `docker compose down -v` unless you intend to permanently delete the database and uploaded files.**

Create a database backup before schema changes or upgrades. The command below writes a custom-format dump in the current directory; treat it as sensitive and store it somewhere protected and separate from the Docker host:

```powershell
docker compose --env-file .env.docker exec -T database pg_dump -U quaiantique -d quaiantique -Fc -f /tmp/quaiantique.dump
docker compose --env-file .env.docker cp database:/tmp/quaiantique.dump .\quaiantique.dump
```

Back up any files in `restaurant_uploads` as well. Verify backups by restoring into a disposable, isolated database before relying on them. Symfony's `var` cache/log directory lives with the disposable API container and is intentionally not persisted. Never put dumps, `.env.docker`, passwords, or customer data in Git or a Docker image.

## Useful operations

Run from the Back repository root, adding `--env-file .env.docker` to each command:

```powershell
docker compose ps
docker compose logs -f api database
docker compose exec api php bin/console doctrine:migrations:status
docker compose stop
docker compose up -d
docker compose down
```

After changing application code or a Dockerfile, rebuild the affected service with `docker compose --env-file .env.docker up -d --build api`. The Front can be rebuilt independently from its own repository with `docker compose --env-file .env.docker up -d --build`.

## Project-specific notes

- The Back image uses PHP 8.4 with Apache, `pdo_pgsql`, `intl`, and OPcache, matching the Symfony 8.1 project requirements. Apache serves only Symfony's `public/` directory and listens on container port 8080.
- The Back image excludes local environment files, Git metadata, development dependencies, tests, and existing local upload files. It does not seed fixtures or include a production database backup.
- The API health check tests whether Apache accepts connections; PostgreSQL has a separate `pg_isready` health check. Compose waits for the database health check before starting the API.
- PHP upload limits preserve the API's existing 5 MB per-image rule. PostgreSQL stores current gallery image data; the named upload volume preserves the legacy filesystem mapping.
- The Front is served by Nginx with SPA route fallback and a runtime-generated `docker-config.js`. `API_BASE_URL` is validated and inserted into the front image at container startup, so the image does not have to be rebuilt to target another API host.
- These Compose files do not alter Vercel, Heroku, GitHub, the production domain, or the existing preview environment. They describe a portable Docker deployment, not a change to the live deployment pipeline.

## Verification checklist

On a machine with Docker available:

1. Run `docker compose --env-file .env.docker config` in each repository (the Front has no required secrets; its `.env.docker` supplies the host URL and port).
2. Build and start the Back, confirm both services become healthy, and check the API at `http://localhost:8000`.
3. Confirm a gallery upload and a database row remain after `docker compose restart` and after `docker compose down` followed by `up`.
4. Start the Front and confirm direct navigation and refresh on `/`, `/lacarte`, `/menu`, and `/galerie`, plus an API-backed page.
5. Verify that requests to the API come from the configured API URL and that CORS allows only the intended front origin.
6. Test database and legacy upload restoration from backups before using this setup for important data.
