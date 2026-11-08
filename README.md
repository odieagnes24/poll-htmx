# Poll App

A single-file PHP poll app backed by Postgres (Neon), with htmx-powered voting and
live results.

## Requirements

- PHP 8.1+ with the `pdo_pgsql` and `mbstring` extensions
- A Postgres database

## Configuration

`DATABASE_URL` is read from `.env.local` (already present in this project), then
`.env`, and can be overridden with a real environment variable. Both PDO DSNs
(`pgsql:host=...;dbname=...`) and `postgres://` URLs are accepted.

The tables (`polls`, `options`, `votes`) are created automatically on the first
request. They use UUID primary keys, and each poll's shareable link is a UUID
stored in `polls.link`.

## Run locally

```sh
php -S 127.0.0.1:8000 -t .
```

Then open <http://127.0.0.1:8000/>.

There is no build step: htmx is loaded from a CDN, and all CSS/JS is inline.
Poll links look like `/index.php?p=<uuid>` locally, and `/?p=<uuid>` on Vercel.

## Deploy to Vercel

> **Note:** Vercel has no official PHP runtime. This project uses the
> community runtime [`vercel-php`](https://github.com/vercel-community/php),
> which was archived in October 2026 and is not supported by Vercel. For a new
> long-lived deployment, Vercel's own guidance is to use a container runtime
> instead. The setup below works with `vercel-php@0.9.0` (PHP 8.5, includes
> `pdo_pgsql`).

The deployment files are already in place:

- `api/index.php` — Vercel entrypoint; loads the app from the project root.
- `vercel.json` — registers the PHP runtime and routes every request to it.
- `.vercelignore` / `.gitignore` — keep `.env*` files out of deploys and commits.

Steps:

1. Push the project to a Git repository (or run `vercel` from this directory).
2. Import the project in Vercel.
3. In **Project → Settings → Environment Variables**, add `DATABASE_URL` for
   Production and Preview. Use Neon's **pooled** connection string (the host
   contains `-pooler`) so serverless instances do not exhaust connections.
   Do not add `.env.local` to Vercel.
4. Deploy. Vercel reads `vercel.json`, bundles the app with the PHP runtime,
   and routes all paths (including `/index.php` and `/?p=...`) to `api/index.php`.

You can test the exact Vercel entrypoint locally, without `vercel dev`:

```sh
php -S 127.0.0.1:8000 api/index.php
```

Then open <http://127.0.0.1:8000/>.

### Serverless behaviour

- State is only in Postgres and the `poll_voter` cookie, so there is no local
  filesystem or session state to lose between invocations.
- HTTPS and secure cookies are detected behind Vercel's proxy; generated links
  use the public host rather than `/api/index.php`.
- The schema check runs on each request, but the DDL migration only runs when
  the schema is missing (for example on a fresh database).
- Each open poll page polls `?action=results` every 2 seconds, and each poll is
  a separate function invocation. This is fine for demos and small polls, but
  keep Vercel's invocation limits in mind for high-traffic polls.
- Cold starts add roughly a few hundred milliseconds, and the function must
  finish within the plan's duration limit.

## How voting works

Each browser gets a random UUID in the `poll_voter` cookie. Votes are stored with
that value in `votes.visitor_id`, and a unique `(poll_id, visitor_id)` index
enforces one vote per visitor per poll.
Deleting the cookie (or using a private window) allows another vote, as with any
cookie-based single-vote scheme.
