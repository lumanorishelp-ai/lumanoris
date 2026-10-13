# Lumanoris

AI chatbot marketplace. Users create and train chatbots, chat with them, and list them for sale as
time-limited subscriptions that other users can buy.

The repository holds two applications that are always run together: a Next.js frontend (`web/`) and a
PHP backend (`api/`) that also contains a separate server-rendered admin panel.

## Overview

| Path   | Stack                                            | Responsibility                                                                           |
| ------ | ------------------------------------------------ | ---------------------------------------------------------------------------------------- |
| `web/` | Next.js 15 (App Router), React 19, Tailwind CSS 3 | Dashboard UI — chat, marketplace, cart/checkout, wallet, notes, lists, settings             |
| `api/` | PHP 8.1+, PDO/MySQL                              | JSON API (one `.php` file per endpoint), session auth, chat inference proxy, admin panel    |

`web/` is not a static frontend. `web/server.js` runs an Express process that wraps the Next.js
request handler and proxies `/api`, `/admin` and `/assets` to the PHP backend, so both applications
answer on a single origin (`http://localhost:3000` by default in development).

> [!IMPORTANT]
> **The payment provider is Hoppa** (EsnekPOS infrastructure). Membership plans are paid on Hoppa's
> hosted payment page, so card data never reaches our servers. `PAYMENT_PROVIDER` defaults to
> `none` (plan purchase closed, the UI says "Ödeme altyapısı hazırlanıyor"); with `hoppa` +
> `HOPPA_MODE=live` it moves **real money**. See
> [Plan purchases — Hoppa](#plan-purchases--hoppa-hosted-payment-page) and AUDIT.md → "Hoppa canlıya açma".
>
> **iyzico is closed.** Its code (`IyzicoClient.php`, `checkout_payments.php`) is still in the
> repository, but the iyzico application was rejected (BLOCKERS B3), no keys are configured (every
> iyzico path fails closed) and nothing in the UI reaches it: the marketplace checkout page takes no
> payment since the N-17 interim measure, and paid marketplace sales need an active seller (B1). It is
> kept only for rows recorded under iyzico. See [Payments](#payments).
>
> One subsystem is still explicitly labelled a **development stub** in its own source file: the
> Param POS marketplace client (seller KYC / sub-merchant registration). Since Faz 4 it blocks **only
> paid sales** — free public publishing needs no seller registration. Producer-plan purchase was
> removed (S14, 2026-10-06). See [Development stubs](#development-stubs).
> Transactional email and plan-based chatbot limits were previously stubs and are now real
> implementations.

## Features

Each item below is backed by routes and endpoints that exist in this repository.

- **Chatbot authoring and training** — `web/src/features/chatbot-mgmt/ChatbotForm.jsx` and
  `web/src/app/dashboard/chatbots/create/page.jsx` collect a persona, style prompt, greeting,
  category and cover/profile images. Knowledge can be imported three ways: images are OCR'd in the
  browser with `tesseract.js`; PDFs are posted to `/api/training/readpdf.php` and parsed server-side
  with `smalot/pdfparser`; and a web page URL is fetched by `/api/training/readurl.php`. Extracted
  text is written to the bot's `training_prompt` in chunks via
  `/api/training/update_training_chunk.php`.
- **Chat with streaming replies** — `/api/chat/generatereply.php` proxies to the Google Gemini
  `streamGenerateContent` endpoint (`gemini-3-flash-preview`) and relays the SSE stream to the
  browser. Conversations, messages and history are stored in MySQL.
- **Message allowance ("coin") system** — a daily message quota plus per-bot bonus credits granted on
  purchase. Implemented in `api/functions/coin_engine.php`; consumption is atomic and the quota is
  read from the user's plan (see [Plans and quotas](#plans-and-quotas)).
- **Marketplace and social graph** — explore/discover pages, categories, like, dislike, follow,
  comment, report, hide, "not interested", and user-defined bot lists.
- **Membership plans** — four plans (see [Plans and quotas](#plans-and-quotas)) bought through Hoppa's
  hosted payment page (`/api/wallet/upgradeplan.php` → `hoppa_return.php`); closed while
  `PAYMENT_PROVIDER=none`. Refunds only from the admin screen *Paket Ödemeleri*.
- **Cart, checkout and subscriptions (marketplace)** — cart endpoints plus
  `/api/marketplace/createsubscription.php` (DB transaction, idempotency key, compensating
  cancellation; charges through iyzico). **Not reachable today:** the checkout page takes no payment
  (N-17 interim measure) and paid sales need an active seller (BLOCKERS B1). See [Payments](#payments).
- **Wallet and marketplace application** — balance, payment history, subscriptions, IBAN/bank
  details, withdrawal requests. Paid selling starts with a company application
  (`/dashboard/pazaryeri-basvurusu`, reviewed at `/admin/basvurular`); the old sub-merchant
  registration wizard was removed in Faz 4. The sub-merchant client itself is still a stub — see
  [Development stubs](#development-stubs).
- **Notes / dialogue books** — sharing a question/answer pair from your own chat history, with likes
  and comments. The server copies both texts from the database by message id and checks chat access
  (N-30); entries for private bots are visible only to the person who shared them.
- **Admin panel** — a separate server-rendered PHP UI at `/admin` for users, chatbots, categories,
  SEO, SMTP, API keys, withdrawals, and content pages.

## Technology Stack

### Frontend runtime dependencies (verified as imported)

| Package | Used for |
| --- | --- |
| `next` 15.5.20, `react` / `react-dom` 19 | App Router application |
| `express` 5, `http-proxy-middleware` 4 | `web/server.js` — custom server + PHP proxy |
| `tailwindcss` 3.4, `tailwindcss-animate`, `postcss`, `autoprefixer` | Styling |
| `@radix-ui/react-*` (avatar, checkbox, dialog, dropdown-menu, select, separator, slot, switch, tabs, toast, tooltip) | shadcn/ui-style primitives in `web/src/shared/ui/` |
| `class-variance-authority`, `clsx`, `tailwind-merge` | Variant + class-name composition (`web/src/lib/utils.js`) |
| `lucide-react` | Icons |
| `react-markdown` | Rendering assistant replies in `web/src/app/dashboard/chat/page.jsx` |
| `tesseract.js` | Client-side OCR (dynamically imported in `ChatbotForm.jsx`) |

> [!NOTE]
> These declared dependencies were **not** found imported anywhere under `web/src`:
> `@radix-ui/react-label`, `@radix-ui/react-scroll-area`, `@react-oauth/google`,
> `@splidejs/react-splide`, `@splidejs/splide`, `date-fns`, `framer-motion`, `pdfjs-dist`,
> `react-icons`, `react-masonry-css`, `sharp`.
> Google sign-in is implemented directly against the Google Identity Services script
> (`https://accounts.google.com/gsi/client`) in `web/src/app/login/page.jsx`, not via
> `@react-oauth/google`.

### Frontend dev tooling

`eslint` 9 + `eslint-config-next` (flat config in `web/eslint.config.mjs`, extending
`next/core-web-vitals`), `nodemon` (watches `server.js` only), `concurrently` (used by the `dev:all`
script).

### Backend

| Component | Detail |
| --- | --- |
| PHP | `>=8.1` required by `api/composer.json` |
| Database | MySQL/MariaDB via PDO (`utf8mb4`, `ERRMODE_EXCEPTION`, emulated prepares off) |
| `google/apiclient` ^2.16 | Verifying Google ID tokens in `AuthController::loginGoogle` |
| `smalot/pdfparser` ^2.9 | Server-side PDF text extraction |
| `vlucas/phpdotenv` ^5.6 (separate `api/admin/composer.json`) | Reads `api/admin/.env` for the admin API-keys page |
| Google Gemini REST API | Chat inference, called with cURL from `ChatController::generateReply` |

SMTP is spoken directly by `api/functions/smtp_client.php`, a dependency-free client — no mail
library is installed.

There is no Docker, CI, or automated test tooling of any kind in this repository.

## Architecture

### Request path

```
browser → http://localhost:3000
            │
            ├─ /api/*, /admin/*, /assets/*  → http-proxy-middleware → PHP_TARGET (default http://127.0.0.1:8000)
            └─ everything else              → Next.js request handler
```

`next.config.mjs` additionally declares `rewrites()` for the same three prefixes, so the routing also
works when the app runs under `next start` or a platform that ignores `server.js` (production runs on
Vercel this way, with `PHP_TARGET=https://api.lumanoris.com`, the fallback when it is unset there). Outside Vercel a
production build fails when `PHP_TARGET` is unset; the rewrites are skipped entirely in static-export mode.

All three prefixes are in use. `/assets/*` serves user-uploaded images: `ChatbotController` writes
cover/profile uploads into `api/assets/<column>/`, and the resulting `assets/…` relative path is what
is stored in the database and rendered by the frontend.

### Backend layering

Every file under `api/api/` is a three-line entrypoint: require `src/autoload.php`, then call one
static controller method. `api/src/autoload.php` boots `functions/bootstrap.php` (JSON header,
session, `.env` parsing, PDO connection, response helpers, and global exception / error / fatal
handlers), `validators.php` and `rate_limit.php`, then registers a filename-based autoloader that
searches a fixed list of directories.

```
Presentation/Controllers  →  Application/UseCases  →  Infrastructure/Repositories  →  Database (PDO)
                          ↘  Presentation/Response/JsonResponse
                          ↘  Presentation/Middleware/AuthMiddleware
```

> [!WARNING]
> The layering is only partially applied. Use cases exist for auth only
> (`Application/UseCases/Auth/` — `LoginUseCase`, `RegisterUseCase`, `GoogleLoginUseCase`); every
> other controller talks to `Database` or a repository directly. `Domain/Interfaces/` declares eight
> repository interfaces (cart, chat, chatbot, notification, social, subscription, user, wallet), but
> only two implementations exist — `ChatbotRepository` and `UserRepository`, both extending
> `BaseRepository`. The other six interfaces have no implementation. Several directories in the
> autoloader's search list (`Domain/Entities/`, `Infrastructure/Mail/`, `Infrastructure/Payment/`,
> `Application/DTO/`, and others) exist but are empty.

`AppException.php` defines eight exception classes in one file, so `autoload.php` requires it
unconditionally — the filename-based autoloader could only ever resolve one of them.

## Folder Structure

```
lumanoris-dashboard/
├── package.json              # root shim — every script delegates to web/
├── autostart.bat             # Windows helper: opens two terminals (PHP + Next.js)
├── TODO.md                   # stale scratch notes for a ChatbotForm refactor
├── project_tree.txt          # generated snapshot of the tree, not consumed by any code
├── .cpanel.yml               # cPanel Git Version Control deploy → scripts/deploy-backend.sh
├── scripts/                  # verify.sh (single verification gate) · deploy-backend.sh (live backend deploy)
├── storage/                  # runtime artefacts, OUTSIDE the document root, contents gitignored
│   ├── db_backup/            #   mysqldump output (default DB_BACKUP_DIR)
│   ├── logs/                 #   php-error.log (default APP_LOG_FILE) + legacy logs
│   └── archive/              #   ad-hoc source archives
│
├── web/
│   ├── server.js             # Express + Next.js + PHP proxy (PORT/HOST env, /healthz, graceful shutdown)
│   ├── next.config.mjs       # static-export toggle, distDir override, rewrites, security headers
│   ├── tailwind.config.js    # "Lumanoris Elite" dark palette + typography scale
│   ├── components.json       # shadcn/ui generator config (style: new-york, tsx: false)
│   ├── scripts/phpify.js     # copies web/src/php → web/out (source dir does not exist — see Scripts)
│   ├── public/               # static assets, incl. robots.txt; public/api/*.php are orphaned
│   └── src/
│       ├── app/              # App Router pages
│       ├── entities/         # Domain-object UI (chatbot cards, user profile cards)
│       ├── features/         # Feature flows (chat, purchasing, wallet, seller, settings, …)
│       ├── widgets/          # Sidebar, DashboardHeader, Navbar, info popups
│       ├── shared/           # ui/ primitives, api/client.js, hooks, contexts, lib helpers
│       ├── lib/utils.js      # cn() helper
│       ├── images/, font/    # imported assets
│       └── app/css/          # global.css (imported), global.scss + .map (see note below)
│
└── api/
    ├── .htaccess             # document-root denylist (.env, dumps, logs, src/, vendor/, functions/)
    ├── router.php            # dev-server router: same denylist + admin pretty URLs
    ├── composer.json         # google/apiclient, smalot/pdfparser
    ├── .env.example          # documents every backend variable
    ├── api/<domain>/*.php    # endpoint entrypoints (3 lines each) + index.php 404 fallback
    ├── assets/               # user-uploaded images (.htaccess disables script execution)
    ├── database/
    │   ├── schema.sql        #   50 tables, utf8mb4_general_ci throughout
    │   ├── migrations/       #   001–017 (incl. 002b, no 014), applied in filename order
│   ├── pending/          #   proposed migrations awaiting approval — NOT applied by migrate.php
    │   ├── migrate.php       #   runner: dry-run by default, records schema_migrations; --auto/--bootstrap/--approve for live deploy
    │   ├── migration_lib.php #   runner logic (tested by migrate_selftest.php)
    │   ├── deploy_mysql_defaults.php # deploy helper: writes a 0600 mysqldump option file from .env
    │   ├── iyzico_selftest.php #  payment integration self-test (offline + sandbox)
    │   ├── hoppa_selftest.php  #  plan purchase via Hoppa (offline + rolled-back DB; --e2e test env)
│   ├── plan_limits_selftest.php, access_selftest.php, application_selftest.php # read-only/rolled-back self-tests
    │   └── seed_contracts.*  #   seeds legal contract texts into global_vars
    ├── cron/                 # CLI-only jobs: plan_payments.php (Hoppa reconciliation + renewal reminders)
    ├── functions/            # bootstrap, env, logging, db, rate limit, mailer, SMTP, coin engine, plans, payments
    ├── src/                  # Presentation / Application / Domain / Infrastructure / Shared
    └── admin/                # server-rendered admin panel (own composer.json + .env + .htaccess)
```

Notes on files a newcomer will trip over:

- `web/public/api/get_bank_info.php` and `save_bank_info.php` `require '../../php/functions/db.php'`,
  a path that does not exist in this repository. They are also unreachable at runtime, because the
  proxy sends every `/api/*` request to the PHP backend before Next.js sees it.
- `web/src/app/css/global.css` (808 lines) is the file imported by `app/layout.js`.
  `global.scss` (11,004 lines) and `global.css.map` are leftovers — no Sass compiler is configured in
  `web/package.json` and no JavaScript imports the `.scss`.
- `web/src/app/auth/page.jsx` and `web/src/app/dashboard/market/page.jsx` deliberately call
  `notFound()` — they are retired routes kept as explicit 404s.
- `api/_restoretest.php` is a scratch script; both `api/.htaccess` and `router.php` deny it by name.
- `web/src/.next-verify/` is build output from a `NEXT_DIST_DIR` verification build, not source.

## Installation

### Prerequisites

| Requirement | Verified from |
| --- | --- |
| PHP 8.1+ with `pdo_mysql` and `curl` | `api/composer.json`; PDO/cURL usage in `functions/db.php`, `ChatController`, `TrainingController` |
| Composer | `api/composer.lock`, `api/admin/composer.lock` |
| MySQL or MariaDB | `Database` builds a `mysql:` DSN |
| Node.js + npm | `web/package-lock.json` (lockfileVersion 3) |

npm is the package manager: `web/package-lock.json` is the only lockfile present, and there is no
`packageManager` field. No Node or PHP runtime version is pinned anywhere (no `engines` field, no
`.nvmrc`, no `platform` block in `composer.json`).

### 1. Backend

```bash
cd api
composer install
cp .env.example .env
```

Then fill in `api/.env`. `api/.env.example` documents every variable the backend reads, including
which ones are mandatory.

The admin panel has its own dependency tree and its own env file:

```bash
cd api/admin
composer install
```

`API_GOOGLE_GEMINI` (the Gemini key used for chat inference) belongs in `api/admin/.env` — that is
the file the admin panel's API-integrations page writes, and `AppConfig::googleGeminiApiKey()`
parses it directly as a fallback. It first checks `$_ENV` / `getenv()`, so setting the variable in
`api/.env` also works, because `functions/bootstrap.php` loads that file into both.

### 2. Database

The schema and migrations are in version control (`api/database/`). A fresh install is:

```bash
# 1. Create an empty database
mysql -u root -p -e "CREATE DATABASE lumanoris CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"

# 2. Load the schema (50 tables)
mysql -u root -p lumanoris < api/database/schema.sql

# 3. Review, then apply migrations (dry-run first — this is the default)
php api/database/migrate.php
php api/database/migrate.php --apply
```

> [!WARNING]
> `002_clean_orphan_rows.sql` and `002b_clean_orphan_rows_2.sql` **delete and rewrite data** — they
> remove rows orphaned by the years the schema ran without foreign keys, so that
> `003_add_foreign_keys.sql` can succeed. Read them before running them. The runner refuses to
> execute a data-destroying migration unless you also pass `--allow-destructive`.

The runner records what it applied in `schema_migrations` (with a checksum), so re-running it is
safe, and it stops at the first failure rather than continuing out of order. `--status` prints what
has and has not been applied without changing anything.

Optionally seed the legal contract texts that the admin pages and frontend popups read:

```bash
php api/database/seed_contracts.php            # dry run
php api/database/seed_contracts.php --apply    # fills only empty global_vars keys
```

Credentials come from `api/.env` (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`). **All four are
required.** `db.php` carries no hard-coded fallback credentials and throws a descriptive exception
rather than connecting somewhere unintended. An empty `DB_PASS=` is a valid value (password-less
user); an empty host, user or database name is rejected.

### 3. Frontend

```bash
cd web
npm install
```

`web/.env` is required for Google sign-in (`NEXT_PUBLIC_GOOGLE_CLIENT_ID`). There is no
`web/.env.example`.

## Environment Variables

### `api/.env` — loaded by `functions/env.php`, into both `$_ENV` and `putenv()`

Values already present in the real process environment always win over the file, so container env
vars are never overwritten by a stale `.env`.

| Variable | Required | Read by | Purpose |
| --- | --- | --- | --- |
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | **Yes** | `functions/db.php` | Connection settings. `DB_HOST` accepts `host:port` (default port 3306). |
| `APP_DEBUG` | No | `functions/bootstrap.php`, `admin/index.php` | When `true`, error responses carry the real exception message and the admin panel enables `display_errors`. Leave false outside local dev. |
| `GOOGLE_CLIENT_ID` | For Google login | `AppConfig::googleClientId()` | Audience for verifying Google ID tokens on `/api/auth/login-google.php`. |
| `CONTACT_EMAIL` | No | `AppConfig::contactEmail()` | Recipient for contact-form mail. Falls back to a hard-coded address. |
| `NOREPLY_EMAIL` | No | `AppConfig::noreplyEmail()` | Sender for password-reset mail. Falls back to a hard-coded address. |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_NAME`, `SMTP_ENCRYPTION` | No | `functions/phpmailer.php` | SMTP transport. When unset, the equivalent `global_vars` rows written by the admin panel's SMTP page are used instead. |
| `IYZICO_API_KEY`, `IYZICO_SECRET_KEY` | No (iyzico is closed) | `IyzicoClient` | Provider credentials from the iyzico merchant panel (Ayarlar → API anahtarları). With either missing, `isConfigured()` is false and charge / refund / reconcile all fail closed — no silent success. |
| `IYZICO_BASE_URL` | No | `IyzicoClient` | `https://sandbox-api.iyzipay.com` (default, test cards) or `https://api.iyzipay.com` (**real money**). The default is deliberately sandbox. |
| `PAYMENT_PROVIDER` | **For plan purchases** | `PaymentGatewayFactory` | `none` (default — plan purchase returns `503`, the UI shows "Ödeme altyapısı hazırlanıyor") or `hoppa`. Unknown values count as `none`. Does not affect marketplace checkout. A real environment variable of the same name overrides `.env` (`functions/env.php`). |
| `HOPPA_MODE` | No | `HoppaGateway` | `test` (default, `posservicetest.esnekpos.com`) or `live` (`posservice.esnekpos.com`, **real money**). The base URLs are constants in `HoppaGateway`; there is no base-URL variable, and the Merchant Public Token is not used by the code. |
| `HOPPA_TEST_MERCHANT`, `HOPPA_TEST_MERCHANT_KEY`, `HOPPA_LIVE_MERCHANT`, `HOPPA_LIVE_MERCHANT_KEY` | With `PAYMENT_PROVIDER=hoppa` | `HoppaGateway` | Only the pair for the selected mode is read; if it is empty the gateway counts as unconfigured. Keep values in `api/.env` only — never in the repo. |
| `APP_PUBLIC_URL` | With `PAYMENT_PROVIDER=hoppa` | `WalletController::startHostedPlanPayment()` | Public site origin, no trailing slash. Hoppa returns the buyer to `APP_PUBLIC_URL/api/wallet/hoppa_return.php`. Not derived from the `Host` header (spoofable). Must be `https` in live mode. |
| `ADMIN_USERNAME` | No | `admin_env_account()` | When set, the admin panel account is read from `.env` and the `adminler` table is **not consulted at all**. Unset → legacy DB-table behaviour. See [Admins](#admins). |
| `ADMIN_PASSWORD` | With `ADMIN_USERNAME` | `admin_env_account()` | Plain-text admin password, compared with `hash_equals`. Simplest option; anyone who can read `.env` can read the password. |
| `ADMIN_PASSWORD_HASH` | Optional alternative | `admin_env_account()` | `password_hash()` output. Takes precedence over `ADMIN_PASSWORD` when set. A hash pasted into `ADMIN_PASSWORD` is detected and treated as a hash, not double-hashed. |
| `PARAM_CALLBACK_SECRET` | For callbacks | `SellerController::paramposCallback()` | Shared secret for the payment-gateway callback. Fails closed: without it no notification is accepted. The callback itself is now inert — iyzico's non-3DS flow sends no async notification. |
| `PARAM_RECONCILE_SECRET` | For reconcile | `SellerController::reconcile()` | Shared secret required via `?secret=`, POST `secret`, or `X-Reconcile-Secret`. If unset, the endpoint always rejects. |
| `DB_BACKUP_DIR` | No | `Database::backupDir()` | Where `mysqldump` output is written. Defaults to `storage/db_backup`. A path resolving inside `api/` is refused. |
| `APP_LOG_FILE` | No | `functions/logging.php` | PHP error-log destination. Defaults to `storage/logs/php-error.log`. |
| `MYSQL_BIN_DIR` | No | `functions/db.php` | Directory holding the `mysql` / `mysqldump` binaries. Needed when they are not on `PATH`. |

> [!NOTE]
> `api/.env.example` also defines `PARAM_CLIENT_CODE`, `PARAM_CLIENT_USERNAME`,
> `PARAM_CLIENT_PASSWORD`, `PARAM_GUID`, `PARAM_MARKETPLACE_GUID`, `PARAM_PAYMENT_WSDL`,
> `PARAM_MARKETPLACE_WSDL`, `PARAM_PAYMENT_SECURITY_TYPE`, `PARAM_REF_URL`, `PARAM_SUCCESS_URL` and
> `PARAM_FAIL_URL`. A search of the whole repository finds **no code that reads any of them** — the
> provider is Hoppa now (iyzico before it, closed), and `ParamPosMarketplace.php` (which those keys
> belonged to) is still a stub. They are kept in the example file only as a marker; nothing breaks if you delete them.

### `api/admin/.env` — loaded by `vlucas/phpdotenv` in `admin/api.php`

| Variable | Purpose |
| --- | --- |
| `API_GOOGLE_GEMINI` | Google Gemini API key used for chat inference. Server-side only; never sent to the browser. |

### `web/.env` and the Node process environment

| Variable | Read by | Purpose |
| --- | --- | --- |
| `NEXT_PUBLIC_GOOGLE_CLIENT_ID` | `web/src/app/login/page.jsx` | Client ID passed to `google.accounts.id.initialize`. Without it the Google button shows an error toast. |
| `PHP_TARGET` | `web/server.js`, `web/next.config.mjs`, `web/src/shared/lib/legal-content.server.js` | Backend origin for the proxy/rewrites and for the server-side fetch of the legal texts. Production: `https://api.lumanoris.com` (the PHP host; no trailing slash). `server.js` and `legal-content.server.js` default to `http://127.0.0.1:8000`. On Vercel (`VERCEL=1`) an unset value falls back to `https://api.lumanoris.com` with a build-log warning and is inlined into the server bundle (`next.config.mjs` `VERCEL_DEFAULTS`); elsewhere a **production build fails** when it is unset. Without a backend origin `/api` stays on Vercel and is answered `403` by Vercel's firewall. A development build only warns and emits no rewrites. |
| `NEXT_PUBLIC_SITE_URL` | `web/src/shared/config/site.js` | Public site origin for canonical URLs, Open Graph and the sitemap. Production: `https://www.lumanoris.net`. On Vercel an unset value falls back to `https://www.lumanoris.net` with a build-log warning; elsewhere a **production build fails** when it is unset; development falls back to localhost. Inlined at build time. |
| `PORT` | `web/server.js` | Listen port. Defaults to `3000`. |
| `HOST` | `web/server.js` | Bind address. Defaults to `127.0.0.1` in development and `0.0.0.0` in production. |
| `NODE_ENV` | `web/server.js` | Anything other than `production` starts Next.js in dev mode. |
| `NEXT_EXPORT` | `web/next.config.mjs` | When `1`, switches the build to `output: 'export'` with unoptimized images, and disables rewrites and `headers()`. |
| `NEXT_DIST_DIR` | `web/next.config.mjs` | Overrides Next's output directory (default `.next`). Use it to run a verification build without clobbering a running dev server's output. |

## Available Scripts

### Root `package.json`

Every root script is a shim that runs the identically named script in `web/`.

| Script | Runs |
| --- | --- |
| `npm run dev` | `cd web && npm run dev` |
| `npm run build` | `cd web && npm run build` |
| `npm start` | `cd web && npm start` |
| `npm run lint` | `cd web && npm run lint` |

### `web/package.json`

| Script | Command | Notes |
| --- | --- | --- |
| `dev` | `nodemon server.js` | Express + Next.js dev server. `nodemon.json` watches only `server.js`; Next.js handles its own hot reload for `src/`. |
| `dev:all` | `concurrently -k -n web,api -c blue,green "npm run dev" "cd ../api && php -S 127.0.0.1:8000 router.php"` | Starts both processes in one terminal. |
| `build` | `next build` | Verified by execution — succeeds, producing 24 statically prerendered routes. |
| `start` | `node server.js` | Does **not** set `NODE_ENV`; without `NODE_ENV=production` it starts Next.js in dev mode and binds `127.0.0.1`. |
| `lint` | `next lint` | Verified by execution — passes with no warnings. Prints a deprecation notice: `next lint` is removed in Next.js 16. |
| `export` | `next export` | **Broken.** Verified by execution: Next.js 15 removed this command in favour of `output: 'export'`. Use `NEXT_EXPORT=1 npm run build` instead. |
| `phpify` | `node scripts/phpify.js` | **Broken.** Verified by execution: it copies `web/src/php` to `web/out`; `web/src/php` does not exist, so the script fails with `ENOENT` and creates nothing. |

### Destructive operations

> [!CAUTION]
> `api/admin/ajax/db_backup.php` with `mode=restore` calls `Database::restore()`, which pipes a
> backup file into `mysql`, overwriting the live database. `mode=backup` shells out to `mysqldump`.
>
> Both require **POST + a valid CSRF token + an authenticated admin session** (enforced by
> `admin/ajax/_guard.php`), and `restore` additionally requires an explicit `confirm=RESTORE` field.
> Only `mode=list` is reachable by `GET`.
>
> Backups are written **outside the document root** — the repo-root `storage/db_backup/` by default,
> overridable with `DB_BACKUP_DIR`. `Database::backupDir()` refuses any configured path that resolves
> inside `api/`.
>
> `Database::truncate()` also exists but is not called anywhere in the repository.

`php api/database/migrate.php --apply --allow-destructive` is the other destructive command — see
[Database](#database).

## Development

Two processes must run. Start the backend first — the frontend has no fallback data.

**Terminal 1 — PHP backend, from `api/`:**

```bash
cd api
php -S 127.0.0.1:8000 router.php
```

`router.php` must be used. It replicates `admin/.htaccess`: real files and directories are served
as-is, and anything else under `/admin` is routed to `admin/index.php`, which is how the admin
panel's pretty URLs (`/admin/seo`, `/admin/kullanicilar`, …) resolve.

`router.php` also carries a denylist that the built-in server has no other way to enforce (there is
no `.htaccess` support in `php -S`): `.env` files, `error_log`, `db_backup/`, archive and dump
extensions, `composer.json`/`composer.lock`, dotfiles, and the `src/`, `vendor/`, `migrations/`,
`database/` and `functions/` directories all return 404. Requests containing `..` are rejected
before any path resolution — `urldecode()` turns `%2e%2e` back into `..`, so the check happens after
decoding. The Apache equivalents live in `api/.htaccess` and `api/admin/.htaccess` — **change all
three together.**

**Terminal 2 — frontend, from `web/`:**

```bash
cd web
npm run dev
```

Or start both at once with `npm run dev:all` from `web/`.

Open <http://localhost:3000>. `/` redirects to `/dashboard`; `/register` redirects to
`/login?tab=register`. `GET /healthz` on the Node server returns uptime and `NODE_ENV` without
touching PHP.

`autostart.bat` at the repository root opens both terminals on Windows, starting the backend as
`php -S 127.0.0.1:8000 router.php` from inside `api/`.

> [!NOTE]
> Both sides deliberately bind `127.0.0.1` rather than `localhost`. On Windows `localhost` resolves
> to `::1` first, which left PHP listening on IPv6 only while Node bound IPv4 — every proxied call
> then failed with `ECONNREFUSED`.

### Routes

| Route | Access |
| --- | --- |
| `/` | Redirects to `/dashboard` |
| `/login` | Public — login and register tabs, plus Google sign-in |
| `/register` | Redirects to `/login?tab=register` |
| `/forgot-password` | Public — email code request, then password reset |
| `/dashboard` and `/dashboard/{chat, chatbots, chatbots/create, checkout, explore, following, history, list, notes, pazaryeri-basvurusu, purchased, settings, upgrade, wallet}` | Guarded by `web/src/app/dashboard/layout.jsx` |
| `/auth`, `/dashboard/market` | Retired — return 404 |
| `/admin` | Proxied to the PHP admin panel |

## Build

```bash
cd web
npm run build
NODE_ENV=production node server.js
```

Static-export mode is also supported:

```bash
cd web
NEXT_EXPORT=1 npm run build
```

This sets `output: 'export'` and `images.unoptimized`, and disables both the rewrites and the
`headers()` block — the exported output must then be served behind something that routes `/api`,
`/admin` and `/assets` to PHP and sets the security headers itself.

`app/layout.js` sets `export const dynamic = 'force-static'`, so all 24 routes prerender as static
content and every data fetch happens client-side.

To run a build purely to check that it compiles, without overwriting a running dev server's `.next`:

```bash
NEXT_DIST_DIR=.next-verify npm run build
```

## Deployment

Only what the repository itself shows is documented here.

- `web/server.js` reads `PORT` (default `3000`) and `HOST` (default `127.0.0.1` in dev,
  `0.0.0.0` in production), exposes `GET /healthz`, and handles `SIGTERM`/`SIGINT` with a graceful
  shutdown that waits up to 15 seconds for in-flight requests before forcing exit.
- `npm start` does not set `NODE_ENV=production`. Run `NODE_ENV=production node server.js`, or set
  the variable in the process manager.
- `next.config.mjs` comments state the `rewrites()` block exists specifically because Vercel does not
  run a custom server. `PHP_TARGET` must point at the real backend origin in that setup.
- `api/admin/.htaccess` and `api/.htaccess` imply Apache with `mod_rewrite`, `mod_headers` and
  `mod_authz_core` for the PHP side in production.

The frontend's hosting, process manager and TLS termination could not be verified from the
repository. The PHP backend's deployment is below.

### Backend deploy (cPanel)

The PHP backend runs on a cPanel host (Markahost) at `api.lumanoris.com`; the contents of `api/`
sit in that domain's Document Root, next to the live `.env`. There is no SSH terminal. cPanel
**Git Version Control** clones `main` from GitHub into a directory outside the web root (default
`~/repositories/…`); **Deploy HEAD Commit** runs `.cpanel.yml`, which runs
`scripts/deploy-backend.sh` with `DEPLOYPATH=$HOME/api.lumanoris.com`.

The script, in order — any failure stops it with a non-zero exit:

1. **Lock** (`flock`, or a `mkdir` lock with stale-PID takeover) — two deploys never overlap.
2. **Backup** — `mysqldump --single-transaction` with the credentials from the live `.env`, passed
   through a temporary 0600 option file (never on the command line), gzipped to
   `~/lumanoris-deploy/backups/db-YYYYmmdd-HHMMSS.sql.gz`. The dump must exit 0, pass `gzip -t` and
   end with mysqldump's `-- Dump completed` line, or the script stops before touching the database.
   The newest 10 backups are kept.
3. **Bootstrap** — `migrate.php --bootstrap` (once; a no-op afterwards).
4. **Migrations** — `migrate.php --apply --auto`. If a file fails, the remaining files are not run
   and the code is **not** copied: the old code keeps running against the additive schema.
5. **Code** — `git archive HEAD:api` extracted over `DEPLOYPATH`: tracked files only, so `.env`,
   `api/admin/.env`, `vendor/` and local junk never travel; `_restoretest.php` is excluded too.
   `web/`, `docs/`, `.claude/` are outside `api/` and are never copied. Files deleted from the
   repository are **not** deleted on the server.

Logs: `~/lumanoris-deploy/logs/deploy-*.log` (full output, newest 30 kept) and
`~/lumanoris-deploy/logs/ozet.log` (one line per run: time, result, commit, log file). Both are
outside the web root. The script refuses a `STATE_DIR` inside `DEPLOYPATH` or `public_html`, and
refuses a `DEPLOYPATH` without `.env` and `functions/db.php`.

Environment overrides: `DEPLOYPATH` (required), `ENV_FILE` (default `$DEPLOYPATH/.env`),
`STATE_DIR` (default `~/lumanoris-deploy`), `PHP_BIN` (default `php`), `MYSQLDUMP` (default
`mysqldump`).

**Deferred and destructive migrations** (`001`, `002`, `002b`, `003`, `005`, `006`) are never
applied by a deploy. With the owner's approval, one file at a time, with lock + backup and without
copying code:

```bash
DEPLOYPATH=$HOME/api.lumanoris.com bash scripts/deploy-backend.sh --approve 005_fix_table_collations.sql
DEPLOYPATH=$HOME/api.lumanoris.com bash scripts/deploy-backend.sh --approve 002_clean_orphan_rows.sql --allow-destructive
```

Intended order for the integrity package: `001` → `002` → `002b` → `003` → `005` → `006`, after
re-measuring orphan rows on the live data (the counts in `002`/`002b` are from 2026-08-26).

**Without Git Version Control** (or to run an `--approve` without a terminal), use a one-shot cPanel
**Cron Job**. Upload the repository (GitHub → Code → Download ZIP of `main`) with File Manager and
extract it to `~/lumanoris-dashboard-main` (outside `public_html`), then add a cron job set to run
every minute:

```bash
mkdir -p $HOME/lumanoris-deploy && mkdir $HOME/lumanoris-deploy/once-1 2>/dev/null && DEPLOYPATH=$HOME/api.lumanoris.com PHP_BIN=/usr/local/bin/php /bin/bash $HOME/lumanoris-dashboard-main/scripts/deploy-backend.sh >/dev/null 2>&1
```

The second `mkdir` succeeds only once, so the job runs exactly once even though cron fires every
minute. Delete the cron job after `ozet.log` shows the result. For the next run change `once-1` to
`once-2`. For an approved migration append `--approve FILE` (and `--allow-destructive` only where
that file needs it) after `deploy-backend.sh`. Without `.git`, the script copies `api/` with `tar`,
excluding `.env*`, `vendor/` and `_restoretest.php`.

## API

### Conventions

- Endpoints are physical files. The URL is the file path: `/api/<domain>/<file>.php`.
- Most `POST` endpoints expect `multipart/form-data` with a single field named `data` containing a
  JSON string. `web/src/shared/api/client.js` builds exactly that shape; most pages build it inline
  with `FormData`. Some endpoints differ: `/api/training/readpdf.php` and `/api/training/readurl.php`
  read a raw JSON body, and `/api/auth/passresetmail.php` and `/api/auth/updateuserpass.php` read
  plain `$_POST` fields.
- Success responses are `{"success": true, ...payload}`; errors are
  `{"success": false, "message": "...", "error_code": "..."}` (`JsonResponse`). Error codes come from
  `AppConfig::ERR_*`: `VALIDATION_ERROR`, `AUTH_REQUIRED`, `NOT_FOUND`, `PERMISSION_DENIED`,
  `LIMIT_REACHED`, `SELLER_NOT_ACTIVE`, `DUPLICATE_ENTRY`, `PAYMENT_ERROR`, `FEATURE_UNAVAILABLE`,
  `SERVER_ERROR`.
- Two handlers return a shape that is **not** the standard envelope:
  `ChatController::getConversation` returns a bare row object with no `success` key, and
  `AuthController::sessionCheck` returns `{"authenticated": false}` when signed out (its signed-in
  branch does use the envelope). A few others — `UserController::getProfilePhoto` and the two
  `seller/list_*` endpoints — `echo` their JSON directly instead of going through `JsonResponse`,
  but still emit the `success` key.
- Status codes observed: `401` with `AUTH_REQUIRED` for a protected endpoint without a session,
  `405 {"success":false,"message":"Method not allowed"}` for a wrong verb, `429` from the rate
  limiter, `500` from the global exception/fatal handlers, `402` with `ERR_PAYMENT` when the
  provider declines a card, `503` with `FEATURE_UNAVAILABLE` when a payment path is asked to run
  without a configured provider (plan purchase with `PAYMENT_PROVIDER=none`, or iyzico without keys), and `404` for any unmatched `/api/**` path (`api/api/index.php`).
- `/api/chat/generatereply.php` is the one non-JSON endpoint: it sets
  `Content-Type: text/event-stream` and streams Gemini's SSE response through. It frames a `meta`
  event first (remaining coins), an `error` event (`refunded: true`) on upstream failure, and, after a
  successful reply, an `event: saved` with the id of the bot message the **server** stored (N-30).

The **Auth** column is derived from the guard each controller method calls: `user` =
`AuthMiddleware::requireAuth()`, `optional` = `optionalAuth()` (works signed-out, personalises when
signed in), `admin` = `requireAdmin()` (the `$_SESSION['admin']` flag set by the admin panel), `none`
= no guard. `GET/POST` means the handler calls no `require_method()`.

### auth

| Endpoint | Method | Auth |
| --- | --- | --- |
| `/api/auth/register.php` | POST | none |
| `/api/auth/login.php` | POST | none |
| `/api/auth/login-google.php` | GET/POST | none |
| `/api/auth/logout.php` | GET/POST | optional |
| `/api/auth/sessioncheck.php` | GET/POST | optional |
| `/api/auth/passresetmail.php` | POST | none |
| `/api/auth/updateuserpass.php` | POST | none |

### chat

| Endpoint | Method | Auth |
| --- | --- | --- |
| `/api/chat/addchat.php` | POST | user |
| `/api/chat/addconversation.php` | POST | user |
| `/api/chat/updateconversation.php` | POST | user |
| `/api/chat/deleteconversation.php` | POST | user |
| `/api/chat/generatereply.php` | POST | user |
| `/api/chat/getchat.php` | GET/POST | user |
| `/api/chat/getconversation.php` | GET/POST | user |
| `/api/chat/gethistory.php` | GET/POST | user |

### chatbot

| Endpoint | Method | Auth |
| --- | --- | --- |
| `/api/chatbot/savechatbot.php` | POST | user |
| `/api/chatbot/updatechatbot.php` | POST | user |
| `/api/chatbot/deletechatbot.php` | POST | user |
| `/api/chatbot/publishchatbot.php` | POST | user |
| `/api/chatbot/unpublishchatbot.php` | POST | user |
| `/api/chatbot/updatechatbotprice.php` | POST | user |
| `/api/chatbot/getchatbot.php` | GET/POST | optional |
| `/api/chatbot/getchatbots.php` | GET/POST | none |
| `/api/chatbot/getchatbots_v2.php` | GET/POST | optional |
| `/api/chatbot/getchatbotsmenu.php` | GET/POST | user |
| `/api/chatbot/getchatbotlimits.php` | GET/POST | user |
| `/api/chatbot/getdefaultbot.php` | GET/POST | none |
| `/api/chatbot/get_suggested.php` | GET/POST | optional |

### content

`getabout`, `getadcounts`, `getcategories`, `getcontactinfo`, `getdelivery`, `getlandingimages`,
`getowner`, `getprivacy`, `getsocials`, `gettermsofsale`, `getusage` — all `/api/content/<name>.php`,
no method guard, no auth. All use the standard `{"success": true, ...}` envelope. These back the
static content popups in `web/src/widgets/info/`.

### marketplace

| Endpoint | Method | Auth |
| --- | --- | --- |
| `/api/marketplace/addtocart.php` | POST | user |
| `/api/marketplace/updatecart.php` | POST | user |
| `/api/marketplace/deletecart.php` | POST | user |
| `/api/marketplace/getcart.php` | GET/POST | user |
| `/api/marketplace/getcartcount.php` | GET/POST | user |
| `/api/marketplace/createsubscription.php` | POST | user |
| `/api/marketplace/updatesubscription.php` | POST | user |
| `/api/marketplace/deletesubscription.php` | POST | user |
| `/api/marketplace/buychatbot.php` | GET/POST | none — retired, always returns `410` |

### social

| Endpoint | Method | Auth |
| --- | --- | --- |
| `/api/social/likechatbot.php`, `dislikechatbot.php`, `followchatbot.php` | POST | user |
| `/api/social/addcomment.php`, `addreport.php`, `addhide.php`, `adduninterest.php` | POST | user |
| `/api/social/adduserlist.php`, `deleteuserlist.php`, `addbottolist.php`, `deletebotfromlist.php` | POST | user |
| `/api/social/getuserlists.php`, `getbotlists.php`, `getfollowedbots.php`, `gethide.php`, `getuninterest.php` | GET/POST | user |
| `/api/social/diduserlike.php`, `diduserdislike.php`, `diduserfollow.php`, `getuserbotstatus.php` | GET/POST | optional |
| `/api/social/getbotsoflist.php`, `getchatbotcomments.php` | GET/POST | none |

### note

| Endpoint | Method | Auth |
| --- | --- | --- |
| `/api/note/adddialogbook.php`, `addcomment2.php`, `likedialog.php`, `dislikedialog.php` | POST | user |
| `/api/note/diduserlike2.php`, `diduserdislike2.php` | GET/POST | optional |
| `/api/note/getdialogues.php`, `getdialoginteracts.php` | GET/POST | user |

### wallet

| Endpoint | Method | Auth |
| --- | --- | --- |
| `/api/wallet/withdraw.php`, `save_bank_info.php`, `upgradeplan.php` | POST | user |
| `/api/wallet/getmybalance.php`, `getiban.php`, `get_bank_info.php`, `getmypayments.php`, `getmysubscriptions.php`, `getsubscription.php` | GET/POST | user |
| `/api/wallet/getpricing.php` | GET/POST | optional |
| `/api/wallet/hoppa_return.php` | POST | none — Hoppa `BACK_URL`; trusts only the order reference, confirms server-side via `ProcessQuery`, answers `303` to `/dashboard/upgrade?odeme=…` |
| `/api/wallet/list_withdrawals.php` | GET/POST | admin |
| `/api/wallet/update_withdrawal_status.php` | POST | admin |

### user

| Endpoint | Method | Auth |
| --- | --- | --- |
| `/api/user/updateusernames.php`, `updateuseremail.php`, `updateuserphone.php`, `user_profilephoto.php` | POST | user |
| `/api/user/getusernames.php`, `getuseremail.php`, `getuserphone.php`, `getuserheader.php`, `user_getphoto.php` | GET/POST | user |

### message, notification, training, contact

| Endpoint | Method | Auth |
| --- | --- | --- |
| `/api/message/consumemessage.php` | POST | user |
| `/api/message/checkmessageallowance.php` | GET/POST | user |
| `/api/notification/createnotification.php`, `readnotification.php` | POST | user |
| `/api/notification/getnotification.php` | GET/POST | user |
| `/api/training/readpdf.php` (raw JSON body, `base64Data`) | POST | user |
| `/api/training/readurl.php` (raw JSON body, `url`; 3 MB cap) | POST | user |
| `/api/training/update_training_chunk.php` | POST | user |
| `/api/training/get_training_chunks.php` | GET/POST | user |
| `/api/contact/contact.php` | POST | none |

> [!NOTE]
> `/api/training/readurl.php` fetches an arbitrary user-supplied URL and is SSRF-hardened: only
> `http`/`https`, every DNS-resolved IP is checked against private/loopback/link-local ranges,
> redirects are disabled, the protocol list is restricted, and size/time caps plus a per-user rate
> limit apply.

### seller

| Endpoint | Method | Auth |
| --- | --- | --- |
| `/api/seller/submerchant_register.php` | POST | user |
| `/api/seller/submerchant_resubmit.php` | GET/POST | user — delegates to `register()`, which requires POST + auth |
| `/api/seller/submerchant_status.php` | GET | user |
| `/api/seller/application_submit.php` | POST | user — marketplace application (company only); field-level validation errors in `errors` |
| `/api/seller/application_status.php` | GET | user — own application (IBAN masked; no birth date / tax no) and `registered`, the single "has marketplace registration" flag |
| `/api/seller/submerchant_list.php` | GET | admin |
| `/api/seller/submerchant_list_remote.php` | GET/POST | admin |
| `/api/seller/submerchant_update.php` | POST | admin |
| `/api/seller/submerchant_delete.php` | POST | admin |
| `/api/seller/marketplace_refund.php` | — | **retired — always `410`** (N-22: admin-session endpoint without CSRF). Refunds are done only from the admin panel, see [Plan purchases — Hoppa](#plan-purchases--hoppa-hosted-payment-page). |
| `/api/seller/marketplace_reconcile.php` | GET/POST | shared secret — `PARAM_RECONCILE_SECRET`, compared with `hash_equals` |
| `/api/seller/parampos_callback.php` | POST | none — payment-gateway callback, gated by `PARAM_CALLBACK_SECRET` |
| `/api/seller/list_iller.php`, `list_ilceler.php` | GET/POST | none — province/district lookups, cached 15 min as JSON in the system temp dir; `?nocache` bypasses it |

### Endpoints with no caller in `web/src`

Verified by diffing every `/api/**.php` literal in `web/src` against the files under `api/api/`:

`chatbot/get_suggested.php`, `chatbot/getchatbots_v2.php`, `chatbot/getdefaultbot.php`,
`content/getcontactinfo.php`, `content/getlandingimages.php`, `content/getowner.php`,
`content/getsocials.php`, `marketplace/buychatbot.php`, `marketplace/updatecart.php`,
`marketplace/updatesubscription.php`, `marketplace/deletesubscription.php`,
`message/consumemessage.php`, `notification/createnotification.php`, `social/diduserlike.php`,
`social/diduserdislike.php`, `social/diduserfollow.php`, `wallet/list_withdrawals.php`,
`wallet/update_withdrawal_status.php`, `seller/submerchant_register.php` (kept for the real sub-merchant integration, BLOCKERS B1), and every admin-only `seller/*` endpoint.

`consumemessage.php` is unused because message consumption moved server-side into
`generatereply.php`; the admin-only endpoints are driven from the PHP admin panel, not the Next.js
app.

### Rate limits

Fixed-window counters in the `rate_limits` table, applied via `checkRateLimit()` (429 on breach):

| Key | Limit |
| --- | --- |
| `login:<ip>:<identifier>` | 8 per 5 min |
| `login-ip:<ip>` | 30 per 5 min |
| `register:<ip>` | 5 per 10 min |
| `passreset:<ip>:<email>` | 3 per 10 min |
| `resetcode:<ip>:<email>` | 10 per 10 min |
| `contact:<ip>` | 5 per 10 min |
| `savechatbot:<user>` | 20 per 5 min |
| `genreply:<user>` | 20 per min |
| `consumemsg:<user>` | 60 per min |
| `checkout:<user>` | 5 per min |
| `readurl:<user>` | 15 per 5 min |
| `readpdf:<user>` | 10 per 5 min |
| `mapp_submit:<user>` | 5 per hour |
| `parampos_cb:<ip>` | 60 per min |

The admin login path uses the non-throwing `rateLimitHit()` variant instead: 5 per account and 20 per
IP, in a 15-minute window.

## Database

MySQL/MariaDB, accessed exclusively through the singleton `Database` class in `api/functions/db.php`.
The connection string is built as `mysql:host=…;dbname=…;charset=utf8mb4;port=…`.

The schema (`api/database/schema.sql`, 50 tables) and the migrations (`api/database/migrations/`,
files `001`–`009` including `002b`) are under version control. `.gitignore` excludes `*.sql`
generally — real dumps and backups must never be committed — with an explicit exception for
`api/database/**/*.sql`, because a schema is configuration, not data.

Every table in `schema.sql` uses `utf8mb4_general_ci`, and `Database::ensureTable()` writes an
explicit `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci` rather than inheriting
the server default. Migration `005_fix_table_collations.sql` exists to normalise databases created
before that was true.

Apply migrations with the runner, which records applied files and their checksums in
`schema_migrations`, refuses to run destructive files without an explicit flag, and stops at the
first failure:

```bash
php api/database/migrate.php                              # dry run (default)
php api/database/migrate.php --status                     # what is applied / pending
php api/database/migrate.php --apply
php api/database/migrate.php --apply --allow-destructive  # needed for 002 and 002b
```

On the **live** server migrations run only through `scripts/deploy-backend.sh` (see
[Deployment → Backend deploy (cPanel)](#backend-deploy-cpanel)), which adds three modes:

| Flag | Does |
| --- | --- |
| `--bootstrap --backup=F` | First deploy only: when `schema_migrations` is missing or empty, records `004`/`010` as applied (with their checksums) and `001`/`002`/`002b`/`003`/`005`/`006` as deferred in `schema_migrations_deferred`. Otherwise does nothing. |
| `--apply --auto --backup=F` | Applies files that are not applied, not deferred and not destructive. Deferred and destructive files are skipped and logged; `008` is skipped while `chatbot_hide`, `chatbot_uninterested` or `chatbot_in_list` has duplicate rows. Stops at the first failure (exit 1). Refuses to run without a fresh, complete backup or before the bootstrap. |
| `--apply --approve=FILE --backup=F [--allow-destructive]` | Applies exactly one file, deferred or not. A destructive file also needs `--allow-destructive`. Used only with the owner's approval. |

`--env=FILE` loads that `.env` first (the deploy script runs from the repo clone and passes the live
one). `pending/` is never read in any mode. `migrate.php`'s destructive check matches `DELETE`,
`TRUNCATE` and `DROP` at the start of a line; it does not catch `UPDATE` (`013` and `015` update
rows and were approved on 2026-10-06).

Six tables are created lazily at runtime with `CREATE TABLE IF NOT EXISTS` — everything else is
assumed to exist:

| Table | Created by |
| --- | --- |
| `rate_limits` | `functions/rate_limit.php` |
| `password_resets` | `AuthController::sendPasswordResetMail()` |
| `checkout_idempotency` | `MarketplaceController::createSubscription()` |
| `param_callback_events` | `SellerController::paramposCallback()` (replay protection) |
| `schema_migrations`, `schema_migrations_deferred` | `database/migrate.php` |

One conditional `ALTER TABLE` still runs at request time: `NotificationController` adds `message_tr`
and `message_en` to `notifications`. The equivalent inside `createSubscription` was moved out to
`migrations/004_add_details_chatbot_id.sql`, because DDL triggers an implicit COMMIT in MySQL and was
silently ending the payment transaction mid-checkout.

Table names used by the application layer are centralised in `AppConfig`:

| Constant | Table |
| --- | --- |
| `TABLE_USERS` | `kullanicilar` |
| `TABLE_CHATBOTS` | `chatbotlar` |
| `TABLE_CART` | `user_cart` |
| `TABLE_SUBSCRIPTIONS` | `user_subscriptions` |
| `TABLE_NOTIFICATIONS` | `notifications` |
| `TABLE_CONVERSATIONS` | `chatbot_conversations` |
| `TABLE_CHATS` | `chatbot_chats` |
| `TABLE_LIKES` / `TABLE_DISLIKES` / `TABLE_FOLLOWS` | `chatbot_likes` / `chatbot_dislikes` / `chatbot_follows` |
| `TABLE_COMMENTS` | `chatbot_comments` |
| `TABLE_LISTS` / `TABLE_LIST_ITEMS` | `user_lists` / `chatbot_in_list` |
| `TABLE_USER_TOKENS` | `user_tokens` |
| `TABLE_BANK_INFO` | `banka_bilgileri` |
| `TABLE_SELLERS` | `param_marketplace_sellers` |
| `TABLE_DIALOG_BOOKS` | `user_dialog_books` |
| `TABLE_COIN_BALANCES` | `user_coin_balance` |
| `TABLE_PURCHASE_CREDITS` | `chatbot_purchase_credits` |

Additional tables referenced directly in SQL: `chatbot_kategoriler`, `chatbot_hide`,
`chatbot_uninterested`, `dialog_likes`, `dialog_dislikes`, `dialog_comments`,
`param_marketplace_payments`, `param_marketplace_details`, `para_cekme_talepleri`, `global_vars`,
`themes`, `adminler`, `plans`, `plan_icerikler`, `producer_plans`, `user_plan_selection`,
`chatbot_reports`, `chatbot_visits`.

> [!WARNING]
> The `Database` class carries explicit hardening for the legacy admin CRUD engine
> (`admin/ajax/{create,read,update,delete}.php`), which accepts client-supplied table names and raw
> `WHERE` fragments: `assertAllowedAdminTable()` enforces a fixed table whitelist,
> `assertSafeWhereFragment()` blocklists injection payloads, and `assertSafeColumnName()` validates
> column identifiers in `insert()`/`update()`. Those admin endpoints remain the riskiest surface in
> the codebase; treat the whitelist as load-bearing.

## Payments

| Flow | Provider | State |
| --- | --- | --- |
| Membership plans | **Hoppa** hosted payment page | Code complete; closed while `PAYMENT_PROVIDER=none`. Opening steps: AUDIT.md → "Hoppa canlıya açma". |
| Marketplace (bot subscriptions) | iyzico code path | **Closed.** Checkout page takes no payment (N-17), paid sales need an active seller (B1), iyzico has no keys and its application was rejected (B3). A Hoppa sub-merchant integration is the planned replacement (B1 note 2026-10-09). |

The sections below up to [Plan purchases](#plan-purchases--hoppa-hosted-payment-page) describe the
**iyzico** code as it stands, for the rows recorded under it. Two files carry that integration:

| File | Responsibility |
| --- | --- |
| `api/src/Infrastructure/Payment/IyzicoClient.php` | Transport: IYZWSv2 request signing, cURL, response redaction, amount/basket formatting |
| `api/functions/checkout_payments.php` | Domain: `chargeCard()`, `cancelCharge()`, `reconcilePayments()`, `processRefund()` |

The official `iyzipay` composer package is **not** installed. It emits deprecations on PHP 8.1 and
all that is actually needed is an HMAC signature plus a JSON POST, so `IyzicoClient` speaks HTTP
directly and the project keeps one fewer dependency.

### Signing

```
payload    = randomKey + uriPath + requestBody
signature  = hex(hmac_sha256(payload, IYZICO_SECRET_KEY))
authString = "apiKey:{key}&randomKey:{rnd}&signature:{sig}"
Authorization: IYZWSv2 <base64(authString)>
x-iyzi-rnd:    <the same randomKey>
```

The `x-iyzi-rnd` header must equal the `randomKey` used in the signature; a mismatch is rejected by
the provider with a signature error rather than a useful message.

### Charge flow — `/api/marketplace/createsubscription.php`

Not reachable from the UI today (see the table above); kept as written for a future provider swap.

1. **Local validation first** — required fields, Luhn, CVV shape, expiry. Identical to the rules
   `CartConfirm`/checkout applies in the browser, so the two layers cannot contradict each other and
   an obviously bad card never costs a network round-trip.
2. **Configuration gate** — `IyzicoClient::isConfigured()`. With no keys, `chargeCard()` returns
   `CONFIG_MISSING` and checkout answers "ödeme altyapısı kullanılamıyor". It never reports a
   success that did not happen.
3. **`POST /payment/auth`** — a direct, non-3DS charge in TRY. `price` and the sum of `basketItems`
   must match **to the kuruş**; `IyzicoClient::balanceBasket()` pushes any rounding remainder into
   the last item, because a mismatch is the single most common cause of a rejected request
   (`errorCode 5`).
4. **Decline** → the provider's own Turkish message ("Kart limiti yetersiz", …) is surfaced to the
   user with `402` / `ERR_PAYMENT`, and the surrounding DB transaction rolls back.
5. **Success** → ledger rows are written as `paid` / `approved`. The per-item
   `paymentTransactionId` values returned by iyzico are stored in `param_response_json`; **refunds
   are per basket item**, so a payment saved without them can never be refunded automatically.
6. **Failure after a successful charge** → `cancelCharge()` issues a same-day full cancellation, so
   the buyer is not left charged for a subscription that was rolled back.

The buyer object iyzico requires (`registrationAddress`, `city`, `country`, `email`,
`identityNumber`, `ip`) has no natural value for a digital product; a consistent placeholder derived
from the user's account is sent because the provider's schema rejects empty fields.

### Plan purchases — Hoppa hosted payment page

Membership plans (Faz 7a) are sold through Hoppa's **Ortak Ödeme Sayfası** (`CommonPaymentDealer`).
The card is entered on Hoppa's page; Lumanoris never receives card data (closes N-17 for this flow).

| Step | Where | What happens |
| --- | --- | --- |
| 1. Start | `POST /api/wallet/upgradeplan.php` → `WalletController::startHostedPlanPayment()` | Price read from `plans`. A `param_marketplace_payments` row is written **before** the provider call with status `hoppa_pending`, then `CommonPaymentDealer` returns the `URL_3DS` (must be `https` on `*.esnekpos.com`). The client only sends `plan_name`. |
| 2. Pay | Hoppa's page | Card + 3-D Secure on Hoppa's side. |
| 3. Return | `POST /api/wallet/hoppa_return.php` | Cross-site form POST, so no session cookie arrives (`SameSite=Lax`). Only `ORDER_REF_NUMBER` is read; the posted status is ignored. |
| 4. Confirm | `WalletController::finalizeHostedPlanPayment()` | Server-to-server `ProcessQuery`. Paid only if the success transaction has `STATUS_ID=3` and the charged amount covers the order (amount = price, or amount − commission = price). A conditional `UPDATE … WHERE status IN ('hoppa_pending','hoppa_failed')` decides which request activates the plan, in the same transaction — the same order can never activate twice. |

Hoppa rows use their own statuses (`hoppa_pending`, `hoppa_failed`) on purpose: iyzico's
`reconcilePayments()` scans `pending`/`failed` and would mark a Hoppa order `failed` because iyzico has
never heard of it.

**Scheduled job (Faz 7a-2)** — `api/cron/plan_payments.php`, CLI only (the `cron` directory is in all
three denylists and the script answers `404` outside the CLI). Install on the server:

```cron
*/5 * * * * cd /path/to/lumanoris && php api/cron/plan_payments.php >> /var/log/lumanoris-plan-payments.log 2>&1
```

Each run (skipped if the previous one still holds the lock):

1. **Reconciliation** — Hoppa documents no webhook, so a buyer who closes the tab after paying never
   hits `hoppa_return.php`. `hoppa_pending` orders older than 15 min are asked via `ProcessQuery` and
   confirmed through the same `finalizeHostedPlanPayment()` (no double activation). Still unpaid after
   24 h → `hoppa_expired`; expired orders are re-asked at most every 6 h for 7 days, then left alone.
   Skipped while `PAYMENT_PROVIDER=none`.
2. **Renewal reminder** (GK-27, no automatic renewal) — in-app notification 3 days before
   `user_plan_selection.expires_at`, once per period (`type = plan_renewal_reminder`).

**Refunds** — only from the admin panel screen **Paket Ödemeleri** (`/admin/odemeler`,
`admin/ajax/odemeler.php`; admin session + CSRF like every admin AJAX endpoint). It lists only Hoppa
plan payments (filter by status and user e-mail) and refunds a `paid` row after a confirmation dialog via
`hostedPlanRefund()`: `OrderReturn`, full refund only, verified with `ProcessQuery`, plan reverted to the
default plan, every attempt in `admin_audit_log`. If the plan is not reverted (a later paid plan
exists, or the user's current plan differs) the reason is shown on screen. The old API endpoint
`/api/seller/marketplace_refund.php` is retired (`410`, N-22) because it checked only the admin session
and no CSRF token. iyzico's `processRefund()` stays in the code but currently has no entry point.

Still open before going live: commission shown to the buyer (N-19), buyer address placeholders (N-20),
`AUTH_HASH` algorithm, hosted-page lifetime, cron, HTTPS. The full list and the opening-day steps are in
AUDIT.md → "Hoppa canlıya açma".
Provider details and observed test responses: `docs/proposals/hoppa-gecis-kesif.md` §0.2.

### Refund and reconciliation

| Operation | Endpoint | Notes |
| --- | --- | --- |
| Refund | none since N-22 (the former `/api/seller/marketplace_refund.php` returns `410`); `processRefund()` is kept | Only a `paid` row can be refunded; `refunded` returns `409`, any other status `422`. Without stored `itemTransactions` it refuses and tells the operator to refund from the iyzico panel. |
| Reconcile | `/api/seller/marketplace_reconcile.php` (`PARAM_RECONCILE_SECRET`) | Asks the provider about rows left `pending` / `payment_started` / `failed` / `unknown` in the last 7 days, max 200. Settled rows (`paid`, `refunded`) are never touched — reconciliation resolves uncertainty, it does not rewrite history. |
| Callback | `/api/seller/parampos_callback.php` | **Inert.** iyzico's non-3DS flow sends no async notification; the handler logs and returns `200`. Kept so a stale gateway configuration cannot retry for hours. |

Both refund and reconcile fail closed with `503` / `FEATURE_UNAVAILABLE` when the keys are missing.

### Schema naming

Table and column names still say `param_*` (`param_marketplace_payments`, `param_transaction_id`,
`param_receipt_id`, `param_net_amount`, `param_response_json`). This is deliberate — they hold live
data, and renaming means a migration plus every read path. The meanings are provider-independent:

| Column | Holds |
| --- | --- |
| `param_transaction_id` | iyzico `paymentId` |
| `param_receipt_id` | iyzico `conversationId` — our `order_id` |
| `param_net_amount` | net payout after provider commission |
| `param_response_json` | the redacted raw provider response, including `itemTransactions` |

### Self-test

```bash
php api/database/iyzico_selftest.php
```

Other self-tests (no persistent writes — scenario data is created inside a transaction and rolled
back): `plan_limits_selftest.php --strict` (plan catalogue, free-plan regression, make-private rights),
`access_selftest.php` (who can list / preview / chat / read persona per bot type) and
`application_selftest.php` (marketplace application validation, resubmission rules and the
"has registration" rule) and `hoppa_selftest.php` (plan purchase via Hoppa: offline classification of
real recorded `ProcessQuery` responses, confirmation and idempotency, reconciliation, reminders and refunds
with a fake gateway; `--e2e` runs one successful payment + real refund, one declined payment and a real
reconciliation on Hoppa's test environment through a headless browser, see the file header). All are listed in `CLAUDE.md` → Doğrulama komutları.

In `iyzico_selftest.php`, part A runs offline and needs no keys: it proves the signature scheme, the amount formatting and the
basket-total equality rule. Part B runs only when `IYZICO_API_KEY`/`IYZICO_SECRET_KEY` are present —
it charges a sandbox test card and then cancels it. No real money moves on the sandbox host.

> [!WARNING]
> `IYZICO_BASE_URL=https://api.iyzipay.com` charges real cards. The default is the sandbox host
> precisely so that a misconfiguration lands in test rather than on someone's card.

## Authentication

### End users

- **Sessions.** PHP sessions started in `functions/bootstrap.php` with `httponly`, `SameSite=Lax`,
  and `secure` set automatically when `$_SERVER['HTTPS']` is present. The identity is
  `$_SESSION['user_id']`.
- **Passwords.** `password_hash(..., PASSWORD_BCRYPT, ['cost' => 12])`. The policy lives in
  `InputSanitizer::passwordPolicyError()` and is applied on **both** the register and the
  password-reset path: minimum 10 characters, maximum 200, not whitespace-only, not a single repeated
  character, not one of a common-password list, and it may not contain the username or the local part
  of the e-mail address.
- **Login.** `LoginUseCase` accepts a username *or* email and returns the same error for an unknown
  identifier and a wrong password, so accounts cannot be enumerated. `AuthController::login` calls
  `session_regenerate_id(true)` on success.
- **Remember me.** Split selector/validator token. The validator is stored SHA-256 hashed and
  compared with `hash_equals`; the cookie is `httponly`, `secure`, `SameSite=Strict`, and lives
  `AppConfig::REMEMBER_ME_DAYS` (30) days. `AuthMiddleware::tryRememberMe()` restores the session from
  it, rotating the pair and regenerating the session id on every use. A valid selector with a bad
  validator burns that selector. Logout clears the DB token as well as both cookies.
- **Google sign-in.** The browser loads Google Identity Services, and the resulting credential is
  posted to `/api/auth/login-google.php`, where `Google_Client::verifyIdToken()` validates it against
  `GOOGLE_CLIENT_ID` before `GoogleLoginUseCase` resolves or creates the account. The handler
  additionally requires `email_verified` on the payload — `verifyIdToken()` validates the signature,
  not ownership, and Google signs unverified-email tokens too, while the account lookup matches on
  e-mail.
- **Password reset.** A 6-digit code is generated server-side, stored SHA-256 hashed in
  `password_resets`, and expires after 15 minutes (computed by MySQL). Reset requires a matching
  `(email, code)` pair; the user id is never taken from the client. A successful reset clears every
  remember-me token and destroys the user's other sessions. Reset requests return an identical
  response whether or not the address is registered.

### Route protection

`web/src/app/dashboard/layout.jsx` calls `/api/auth/sessioncheck.php` on mount and
`router.replace("/login")` when unauthenticated, so every `/dashboard/*` route is gated client-side.
The server-side guard is the per-endpoint `AuthMiddleware` call listed in the [API](#api) tables —
that is the authoritative one.

> [!NOTE]
> `web/src/shared/lib/auth-guard.js` documents an older design in which dashboard pages were open to
> guests and only individual actions required login. That is no longer what the code does: the
> dashboard layout redirects unauthenticated visitors. `requireLogin()` is still used for per-action
> guarding.

### Authorization

There is no role system for end users. Access is checked per resource, most notably
`ChatbotRepository::userHasAccess($bot, $user, $purpose)`. `full` (persona and training text) = the bot's
author/owner or a live `user_subscriptions` row. `chat` (used by `generatereply.php`) = `full` **or** a
free public bot (published, both prices empty/0) — anyone signed in may chat, spending their daily
coins. `preview` (marketplace card) = `full`, a free public bot, or a priced bot whose author is an
active seller. Private (`is_independent = 1`) bots match no public branch. `getchatbot.php` returns
`style_prompt` only to the bot's owner, plus `is_owner`. The home-page bot picker takes its lock from the same
`chat` rule (`getfollowedbots.php` → `can_chat`, N-27). Chat messages: `addchat.php` requires `chat`
access and accepts `sent_by: "bot"` only for the bot's own greeting; generated replies are saved by
`generatereply.php`. Dialogue books (`functions/dialog_book.php`, N-30): `adddialogbook.php` takes only
`{message_id, name}` — a bot message from the caller's own history on a bot they can chat with; question
and answer are copied from the database. The feed and every per-dialog endpoint show an entry only if
its bot passes the storefront rule (`ChatbotRepository::publicVisibleSql()`) or the viewer shared it.
`api/database/access_selftest.php` locks this matrix. Ownership checks are also inlined in controllers (for example
`ChatController::updateConversation` and `TrainingController::updateTrainingChunk`).

### Admins

Admins are a separate identity reusing the same PHP session as end users. Both admin login paths
(`admin/ajax/giris.php` and the no-JS `admin/partials/_login.php`) go through one function,
`admin_login_attempt()` in `admin/functions/admin_login.php`, which regenerates the session id
before privilege escalation and applies the two-tier rate limit described above. Every
admin AJAX endpoint includes `admin/ajax/_guard.php`, which rejects non-admins with `403` and
requires a CSRF token (`csrf_token` field or `X-CSRF-Token` header) on every non-`GET` request.
`AuthMiddleware::requireAdmin()` reads the same `$_SESSION['admin']` flag for the JSON API and
returns `403` with `PERMISSION_DENIED` when absent.

Marketplace applications are reviewed at `/admin/basvurular` (`admin/ajax/basvurular.php`, guarded as
above). Marking one "reviewed" does **not** make the user an active seller (that depends on BLOCKERS B1)
and notifies the user through the `notifications` table. It also copies the application IBAN into
`banka_bilgileri` (GK-23, enabled since migration `017_admin_audit_log.sql`): each change is written to
`admin_audit_log` with the IBAN masked, and the copy is skipped while the user has a pending withdrawal
(retry with "IBAN'ı aktar"). Plan refunds on *Paket Ödemeleri* (`/admin/odemeler`) are logged there too.

**Where the account comes from** is decided by `ADMIN_USERNAME` in `api/.env`:

| `ADMIN_USERNAME` | Credential source | `adminler` table | `/admin/adminler` page |
| --- | --- | --- | --- |
| set | `.env` (`ADMIN_PASSWORD` plain, or `ADMIN_PASSWORD_HASH`) | never queried on login | read-only; `admin/ajax/adminler.php` answers `409` to every write |
| unset | `adminler` row, bcrypt-verified | authoritative | full CRUD |

The env path is deliberately exclusive rather than a fallback chain: if a leftover `adminler` row
could still log in, moving the account into `.env` would buy nothing. It also means the most
privileged account cannot be created by anything that can write to the database. The session records
which path was used in `$_SESSION['admin_source']` (`env` / `db`).

Username comparison is case-insensitive in both modes, matching MySQL's default collation so the
behaviour does not change when you switch. A configured `ADMIN_USERNAME` with no password set is a
configuration error: login returns `500` with an explicit message and does **not** quietly fall back
to the table.

## Security

Three layers enforce the same denylist. **They must be changed together** — each covers a
deployment the others cannot see:

| Layer | File | Applies to |
| --- | --- | --- |
| Apache | `api/.htaccess`, `api/admin/.htaccess` | shared hosting / production |
| Built-in server | `api/router.php` | `php -S` (dev, `autostart.bat`, `dev:all`) |
| Next.js | `next.config.mjs` → `headers()` | everything Next serves (excludes `/api`, `/admin`, `/assets`) |

What they block: `.env` files, `error_log`, dumps and archives (`.sql`, `.zip`, `.tar`, `.gz`,
`.bak`, `.key`, `.pem`, …), `db_backup/`, `composer.json`/`composer.lock`, all dotfiles, and the
`src/`, `vendor/`, `migrations/`, `database/` and `functions/` directories. Directory indexes are
disabled.

`api/assets/.htaccess` and `api/admin/uploads/.htaccess` disable the script interpreter for
user-uploaded bytes (`php_flag engine off`, handler removal, and an extension denylist).

### Response headers

`next.config.mjs` sets CSP, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
`Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`,
`Cross-Origin-Opener-Policy: same-origin-allow-popups`, and (production only) HSTS and
`upgrade-insecure-requests`. Both production-only directives are deliberately dev-excluded: pinning
`localhost` to HTTPS in a browser is hard to undo, and upgrading sub-requests breaks plain-HTTP local
access from a LAN IP. `api/.htaccess` sets the three basic headers for the PHP side.

The header rule deliberately skips `/api`, `/admin` and `/assets` — the admin panel loads assets from
third-party CDNs that the app's CSP would block. The CSP allows `accounts.google.com` for
script/style/connect/frame because Google Identity Services renders its button in an iframe from that
origin.

### Secrets

| Secret | Lives in | Notes |
| --- | --- | --- |
| DB credentials | `api/.env` (`DB_*`) | All four required; `db.php` fails loudly if any is missing, and carries no fallback credentials. |
| Gemini API key | `api/admin/.env` | Managed by the admin panel; read server-side only, never sent to the client. |
| Hoppa merchant key | `api/.env` (`HOPPA_LIVE_MERCHANT_KEY`; test pair `HOPPA_TEST_*`) | The credential that moves money today. Sent only in request bodies to Hoppa; bodies are never logged and stored provider responses drop `MERCHANT_KEY` and `CUSTOMER_*` (`HoppaGateway`). |
| iyzico API keys | `api/.env` (`IYZICO_API_KEY`, `IYZICO_SECRET_KEY`) | Empty — iyzico is closed. If ever set: requests are signed with HMAC-SHA256 and responses redacted (`IyzicoClient::redact()`). |
| Admin panel password | `api/.env` (`ADMIN_PASSWORD` or `ADMIN_PASSWORD_HASH`) | Only when `ADMIN_USERNAME` is set. Plain text is supported and is readable by anyone who can read the file; use `ADMIN_PASSWORD_HASH` if that matters. |
| Param POS credentials | `api/.env` (`PARAM_*`) | Present in `.env.example` but read by no code in this repository — superseded (iyzico, then Hoppa). |
| SMTP | `global_vars` table, or `SMTP_*` in `api/.env` | Environment wins when set. |
| Callback / reconcile secrets | `api/.env` | `PARAM_CALLBACK_SECRET`, `PARAM_RECONCILE_SECRET`. Both fail closed when unset. |

> [!CAUTION]
> The repository root contains `google.txt`, `customserver.txt` and `chatbot_table.txt`, which hold
> credential-shaped content. `.gitignore` excludes all three by explicit path with a comment
> recording that they previously contained real OAuth credentials and server connection details.
> `api/` also contains several `.env.bak-*` files. Treat every value in these files as compromised
> and rotate it; removing a file does not remove it from git history.

### Mass assignment

Client JSON is never passed straight to `insert()`/`update()`. Each write endpoint declares a
column allowlist via `InputSanitizer::pickAllowed()`, which **reports** rejected keys rather than
dropping them silently. `BaseRepository` validates every column name against
`InputSanitizer::isSafeIdentifier()` as a last line of defence, and `Database` has its own
`assertSafeColumnName()`.

## Configuration

### Business constants — `api/src/Shared/Constants/AppConfig.php`

| Constant | Value | Meaning |
| --- | --- | --- |
| `DAILY_FREE_MESSAGES` | 10 | Fallback daily message quota. Used only when the `plans` table is unavailable — see [Plans and quotas](#plans-and-quotas). |
| `FREE_INDEPENDENT_BOT_LIMIT` / `FREE_PUBLIC_BOT_LIMIT` | 1 / 2 | Fallback free-plan bot limits, same condition. |
| `FREE_PRIVACY_RIGHT_LIMIT` | 1 | Fallback total "make private" (unpublish) rights, same condition. |
| `SUBSCRIPTION_WEEKLY` / `SUBSCRIPTION_MONTHLY` | 7 / 30 days | Subscription durations |
| `DISCOUNT_MONTHLY_FACTOR` | 0.9 | Applied exactly once, when a seller sets a price: `ucret_aylik = round(weekly × 4 × 0.9)`. Everything downstream uses the stored value as-is. |
| `SELLER_COMMISSION_WEEKLY` / `SELLER_COMMISSION_MONTHLY` | 0.85 / 0.80 | Seller's share of a sale |
| `MIN_WEEKLY_PRICE` / `MAX_WEEKLY_PRICE` | 100 / 5000 ₺ | Enforced by `ChatbotController::assertValidPrice()` on a priced publish and on price update, for both the weekly and the derived monthly figure. A publish without a price makes a free public bot. |
| `MAX_UPLOAD_SIZE_BYTES` | 5 MB | Upload cap, enforced in `ChatbotController::handleImageUploads()` |
| `REMEMBER_ME_DAYS` | 30 | Remember-me lifetime |

> [!NOTE]
> `SERVICE_FEE_PERCENT`, `SERVICE_FEE_EXEMPT_ABOVE` and `DEFAULT_PAGE_LIMIT` are declared but read by no code. In
> particular **no service fee is charged anywhere** — do not assume one is applied because the
> constants exist.

`web/src/shared/lib/pricing.js` deliberately mirrors the pricing values for client-side validation,
and `api/functions/coin_engine.php` mirrors the bonus-credit tiers in
`web/src/shared/lib/pricing.js` (`COIN_TIER_*`). **Both sides must be edited together — nothing enforces
that they agree.**

### Plans and quotas

`api/functions/plans.php` is the single source of truth for per-plan quotas. `getUserPlan()` joins
`user_plan_selection.plan_name` to `plans.name_tr`, falling back to the default plan row, and both
`chatbot_limits.php` (bot limits) and `coin_engine.php` (daily message quota) read through it. When
the `plans` table is missing, has no quota columns (migration `007_plan_limits.sql` not applied), or
is empty, `fallbackPlan()` returns the `AppConfig` values above — so behaviour is unchanged on an
un-migrated database.

Current catalogue (migration `013_plan_catalog_2026_10.sql`; editable in the admin Subscription page):

| Plan | Monthly | Daily coins | Private bots | Public bots | Make-private rights (total) |
| --- | --- | --- | --- | --- | --- |
| Ücretsiz | 0 ₺ | 10 | 1 | 2 | 1 |
| Gümüş | 149 ₺ | 50 | 3 | 5 | 3 |
| Altın | 299 ₺ | 100 | 5 | 10 | 5 |
| Elmas | 849 ₺ | 200 | unlimited | unlimited | 20 |

Since migration `012`, a `NULL` bot limit means unlimited (`chatbot_limits.php` maps it to
`BOT_LIMIT_UNLIMITED`). An **active marketplace seller** additionally gets unlimited public bots.
Publishing a bot does not use a right; pulling a published bot back to private
(`unpublishchatbot.php`) uses one make-private right (counted in `user_privacy_right_usage`, never
renewed) and is not limited by the private-bot quota. `api/database/plan_limits_selftest.php --strict`
checks the catalogue.

### Design tokens

`web/tailwind.config.js` defines the dark "Lumanoris Elite" palette (`luma.*`), shadcn HSL variables,
gradients, shadows, and a typography scale that **overrides** Tailwind's default `text-xs` …
`text-5xl` sizes rather than extending them. `darkMode` is set to `["class"]`. Public Sans and Space
Grotesk are pulled from Google Fonts by the `@import` on line 6 of `global.css`; Digital-7 is a local
`@font-face` served from `web/src/font/`. `global.css` also contains `font-family` rules naming
Montserrat, but nothing in the imported stylesheet loads that family.

### Development stubs

These files ship implementations that log and return a canned value instead of doing the real work.
Each one says so in its own header comment.

| File | Stubbed behaviour |
| --- | --- |
| `api/functions/ParamPosMarketplace.php` | Every sub-merchant and province/district method returns a failure or an empty list. Fail-closed by design: nobody can become an **active** seller, so **paid** sales (priced publish, cart, subscription, seller payout) stay closed. Since Faz 4 this does **not** block free public publishing — anyone can publish a free public bot without seller registration (GK-1/GK-2). Personal data in call logs is redacted. Seller KYC only — plan payments (Hoppa) do not go through this class. |

> [!NOTE]
> `api/functions/checkout_payments.php`, `api/functions/phpmailer.php` and
> `api/functions/chatbot_limits.php` were previously stubs and are now real implementations — iyzico
> charging (implemented, but closed: see [Payments](#payments)), SMTP delivery via
> `functions/smtp_client.php`, and plan-driven limits via `functions/plans.php`.

## Troubleshooting

**All API calls fail with a 502 and `Backend sunucusuna ulaşılamıyor.`**
The PHP server is not running, or `PHP_TARGET` points elsewhere. `web/server.js` returns that JSON
body specifically so callers doing `JSON.parse(await res.text())` get a parseable error instead of a
syntax error. Check `GET /healthz` to confirm the Node process itself is up.

**`Veritabanı yapılandırması eksik: … tanımlı değil`**
One or more of `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME` is missing from `api/.env`. All four must
be present; leave `DB_PASS=` empty for a password-less user.

**`Veritabanı bağlantısı başarısız`**
The four variables are set but MySQL rejected them or is unreachable.

**Admin panel: `/admin/` loads but `/admin/seo` returns 404**
The PHP server was started without `router.php`. Restart it as `php -S 127.0.0.1:8000 router.php`
from inside `api/`.

**Chat replies never arrive; the endpoint reports the AI service is not configured**
`API_GOOGLE_GEMINI` is set in neither the process environment, `api/.env`, nor `api/admin/.env`.
`AppConfig::googleGeminiApiKey()` checks all three, in that order, and returns an empty string when
none of them has it.

**Password-reset emails never arrive**
Configure SMTP — either `SMTP_*` in `api/.env` or the admin panel's SMTP settings page, which writes
the `smtp_host` / `smtp_email` / `smtp_pass` / `smtp_name` rows in `global_vars`. Delivery is
fail-closed: with no host configured, `sendEmail()` returns `success: false` rather than pretending
the code was sent. The code is **not** written to the error log.

**Nobody can sell a bot for money / priced publish is refused**
Expected: `ParamPosMarketplace.php` is a fail-closed stub, so no user becomes an active seller
(BLOCKERS B1). This blocks only **paid** sales. Free public bots can be published without any seller
registration (Faz 4), and "Pazaryerine Kaydet" leads to the company application form.

**Plans page says `Ödeme altyapısı hazırlanıyor`**
`PAYMENT_PROVIDER` is `none` (the default) or unset — plan purchase is closed on purpose. The
marketplace checkout page always shows the same notice (N-17 interim measure). See
AUDIT.md → "Hoppa canlıya açma" before switching to `hoppa`.

**"Ödemeye geç" fails with `PAYMENT_PROVIDER=hoppa`**
Check the PHP log. `APP_PUBLIC_URL tanımsız/geçersiz (canlıda https zorunlu)`: `APP_PUBLIC_URL` is
missing, malformed, or not `https` while `HOPPA_MODE=live`. Otherwise the merchant pair for the
selected mode is empty (`HOPPA_TEST_*` for `test`, `HOPPA_LIVE_*` for `live`). A real environment
variable overrides `.env`; restart PHP after editing it.

**User lands on `/login` after paying on Hoppa**
Fixed in N-31 (`canli-2026-10c`): `hoppa_return.php` no longer starts a session. If it happens again,
check that the site is opened on the same host as `APP_PUBLIC_URL`; on another host the session cookie
is not sent back.

**iyzico refunds and reconciliation return 503**
Expected — iyzico is closed and has no keys. Run `php api/database/iyzico_selftest.php` — part A passes
without keys, part B is skipped.

**Admin login says `Admin girişi yapılandırılmamış`**
`ADMIN_USERNAME` is set in `api/.env` but neither `ADMIN_PASSWORD` nor `ADMIN_PASSWORD_HASH` is.
Add one, or remove `ADMIN_USERNAME` to go back to the `adminler` table.

**Admin password stopped working after setting `ADMIN_USERNAME`**
Expected. The `.env` account fully replaces the table — the old `adminler` row can no longer log in.
Comment out the two `ADMIN_*` lines to revert.

**Every 500 says `Sunucu hatası oluştu.` with no detail**
By design. Set `APP_DEBUG=true` in `api/.env` for local debugging; the real message is always written
to the error log regardless.

**`npm run export` or `npm run phpify` fails**
Both scripts are broken — see [Available Scripts](#available-scripts). Use
`NEXT_EXPORT=1 npm run build` for a static export.

**`npm start` behaves like the dev server**
`web/package.json`'s `start` script does not set `NODE_ENV`. Run `NODE_ENV=production node server.js`.

**A `next build` broke the running dev server**
A production build overwrote the dev server's `.next`, so the dev chunks it is still requesting are
gone. Restart the dev server, and use `NEXT_DIST_DIR=.next-verify` for verification builds.

**Turkish column and field names**
The database schema and most request payloads use Turkish identifiers (`kullanicilar`, `chatbotlar`,
`eposta`, `sifre`, `isim`, `aciklama`, `ucret_haftalik`). `AppConfig`'s `TABLE_*` constants are the
quickest map between the two vocabularies.

## Contributing

No contribution workflow, PR template, or CI configuration exists in this repository. Three
project-specific practices are documented in the code itself and should be preserved:

- **Never commit secrets.** `.gitignore` excludes `.env*` (except `*.env.example`), `db_backup/`,
  `*.sql` (with an explicit exception for `api/database/**/*.sql`), and the root-level `google.txt`,
  `customserver.txt` and `chatbot_table.txt` files, with a comment recording that those files
  previously contained real OAuth credentials and server connection details.
- **Change the three denylists together.** `api/.htaccess`, `api/admin/.htaccess` and
  `api/router.php` implement the same rules for three different deployment shapes; a rule added to
  one and not the others silently does nothing in the deployments it misses.
- **Keep the mirrored constants in sync.** Pricing, commission and message-allowance values are
  duplicated between `api/src/Shared/Constants/AppConfig.php`, `api/functions/coin_engine.php` and
  `web/src/shared/lib/pricing.js`. Change all of them together.

## License

No `LICENSE` file exists in this repository. The only license declaration is in
`api/composer.json`, which sets `"license": "proprietary"` for the `lumanoris/api` package. The
license status of `web/` could not be verified from the repository.
