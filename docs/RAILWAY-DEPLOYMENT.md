# Railway deployment

This deployment profile runs the Laravel web app, queue worker, and scheduler
on Railway with one public HTTPS domain. Tenants use paths such as
`https://<service-domain>/t/<slug>`; tenant subdomains are not required.

The app remains compatible with subdomain hosting when
`TENANT_URL_MODE=subdomain` (the default). Railway support does not include
WireGuard, RADIUS, or physical-router provisioning. Keep those integrations
disabled on every Railway service.

## Create the services

1. Connect the `deployment/railway` branch to a Railway project.
2. Create PostgreSQL and Redis services in the same Railway environment.
3. Create a `web` service from this repository and branch.
   - Set `RAILWAY_DOCKERFILE_PATH=Dockerfile.render`.
   - Leave the start command empty so the image runs its production web entrypoint.
   - Set the healthcheck path to `/up` and the target port to `8080`.
   - Generate a Railway public domain. Railway provides HTTPS for this domain.
4. Create `worker` and `scheduler` services from the same repository and branch.
   Set `RAILWAY_DOCKERFILE_PATH=Dockerfile.render` on each and use these start
   commands:
   - Worker: `sh scripts/railway-queue-worker.sh`
   - Scheduler: `sh scripts/railway-scheduler.sh`
5. Add a persistent volume to the **web service only**, mounted at
   `/var/www/html/storage/app`. User-uploaded logos use this directory. Railway
   free-plan volumes have limited capacity (currently documented as 0.5 GB and
   one volume per project); check the limits for the account before relying on
   local file storage. Use an external object-storage service with its Laravel
   filesystem adapter if more space, independent backups, or multiple web replicas
   are needed.

The Dockerfile configures Nginx on port 8080. The `/up` healthcheck verifies the
web process during deployment; monitor `/health/ready` separately after the
worker and scheduler are running.

## Variables

Use Railway reference variables for the managed services instead of public
database/Redis endpoints. The service names below are examples; adjust the
reference names to match the names in your Railway project.

### Shared application variables

Set these on `web`, `worker`, and `scheduler`, preferably through Railway
shared variables:

| Variable | Value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | One stable key shared by all three services. Generate locally with `php artisan key:generate --show`; never commit it. |
| `APP_NAME` | `Wi-Fi Billing SaaS` |
| `APP_TIMEZONE` | `UTC` or the deployment's chosen timezone |
| `APP_URL` | On `web`: `https://${{RAILWAY_PUBLIC_DOMAIN}}`. On `worker` and `scheduler`: `https://${{web.RAILWAY_PUBLIC_DOMAIN}}`. |
| `TENANT_URL_MODE` | `path` |
| `TENANT_BASE_DOMAIN` | Leave unset; path mode uses the host in `APP_URL`. |
| `TRUSTED_PROXIES` | `*` (Railway routes public HTTP through its edge and does not provide a stable proxy CIDR list). Expose the web service only through Railway's generated HTTP/HTTPS domain; do not add a TCP proxy or alternate origin. The application trusts only forwarded client IP, port, and protocol headers, not the forwarded host. |
| `SESSION_SECURE_COOKIE` | `true` |
| `SESSION_ENCRYPT` | `true` |
| `SESSION_DRIVER` | `redis` |
| `CACHE_STORE` | `redis` |
| `QUEUE_CONNECTION` | `redis` |
| `REDIS_CLIENT` | `phpredis` |
| `DB_CONNECTION` | `pgsql` |
| `SITE_AGENT_REQUIRED` | `true` |
| `ALLOW_DIRECT_ROUTER_CONNECTION` | `false` |
| `WIREGUARD_ENABLED` | `false` |
| `RADIUS_ENABLED` | `false` |
| `RADIUS_PRODUCTION_ENABLED` | `false` |
| `LOG_CHANNEL` | `stderr` |
| `FILESYSTEM_DISK` | `public` when using the mounted volume; use `s3` only after installing and configuring its Laravel filesystem adapter. |

Set `PORT=8080` and `RAILWAY_DOCKERFILE_PATH=Dockerfile.render` on all three
application services. The web service's `APP_URL` must be an origin with no
extra path. Keep `SESSION_DOMAIN` unset so the session cookie remains
host-only.

### Service references

On each application service, reference the PostgreSQL service's `DATABASE_URL`
as `DATABASE_URL` and the Redis service's `REDIS_URL` as `REDIS_URL`. For
example, if the resources are named `Postgres` and `Redis`:

```text
DATABASE_URL=${{Postgres.DATABASE_URL}}
REDIS_URL=${{Redis.REDIS_URL}}
```

Laravel accepts Railway's `DATABASE_URL` directly. Do not copy connection
credentials into source files or use public TCP-proxy URLs for internal
service-to-service connections.

Configure SMTP variables (`MAIL_MAILER=smtp`, host, port, username, password,
and from address) before enabling public registration; registration codes,
password resets, and welcome emails require delivery.

## Deploy and verify

1. Deploy PostgreSQL and Redis first.
2. Deploy `web`. Its entrypoint validates production settings, waits for the
   database, runs migrations, creates the public-storage symlink, then starts
   Nginx and PHP-FPM.
3. Deploy `worker` and `scheduler`. All three services must use the same
   `APP_KEY`, database, Redis, and `APP_URL`.
4. Confirm the deployment healthcheck succeeds at `/up`, then verify
   `/health/ready`, sign-in, tenant registration, and a tenant URL under
   `/t/<slug>`.

The Railway preflight fails closed if path tenancy, PostgreSQL, Redis-backed
sessions/cache/queue, or the router-disabled profile is misconfigured. It
allows `TRUSTED_PROXIES=*` only when Railway's `RAILWAY_PROJECT_ID` is present;
other production hosts must continue to provide exact trusted proxy
IP/CIDR values.

Changing an existing deployment from subdomains to paths does not require a
database migration, but existing tenant bookmarks and external callback URLs
must be updated to the new `APP_URL` and `/t/<slug>` paths.

## Explicitly out of scope

Railway's web service is not the WireGuard/RADIUS network edge. This profile
does not support physical-router onboarding, captive-portal access through
routers, or RADIUS authentication. Do not enable those features or use this
deployment as evidence that a physical router is ready to test.

This repository change does not create or modify resources in a Railway
account. Review the environment, variables, volume, and deploy plan in Railway
before applying them.

Railway references:

- [Dockerfile configuration](https://docs.railway.com/builds/dockerfiles)
- [Public networking and generated domains](https://docs.railway.com/networking/public-networking)
- [Healthchecks](https://docs.railway.com/deployments/healthchecks)
- [Variables and service references](https://docs.railway.com/variables)
