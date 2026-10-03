# VS Orders — Standalone (Cloudflare Pages + D1)

The VS Orders weekly-delivery system without WordPress. Same features as the plugin (menu, cart, Stripe checkout, capacity-limited weekly rounds, subscribers, messaging, print tickets, admin dashboard), running entirely on Cloudflare:

```
Browser ──► Cloudflare Pages
              ├─ public/            static: order page, embeddable widgets, admin SPA
              ├─ functions/api/*    Pages Functions → src/ (REST API)
              └─ functions/media/*  product images from R2
            Bindings: D1 (DB) · R2 (MEDIA) · secrets (Stripe, Resend, MailerLite)
Admin:  Cloudflare Access (login) ──► /admin + /api/admin  (JWT verified again in the Worker + role check)
```

| WordPress plugin | Standalone |
|---|---|
| CPTs, taxonomy, post/term meta, `wp_options`, `wp_vso_messages` | D1 tables (`migrations/0001_init.sql`) |
| `includes/rest-api.php` | `src/api-public.js`, `src/api-admin.js`, `src/router.js` |
| `wp-admin` pages, roles, capabilities | `public/admin/` SPA + Cloudflare Access + `admin_users` roles |
| `[visit_sicily_order]` | `public/vso/order-app.js` (+ `index.html`) |
| `[visit_sicily_status]` | `public/vso/status-widget.js` |
| `wp_mail` | Resend API |
| Media library | R2 bucket |
| Stripe / MailerLite keys in `wp_options` | Cloudflare secrets |

## Project structure

```
standalone/
├── wrangler.toml            Bindings + non-secret config
├── migrations/0001_init.sql Schema + default settings
├── seed/menu.sql            The 57-product Visit Sicily menu (optional)
├── functions/               Pages Functions entry points (thin)
├── src/                     API: router, auth (Access), settings, email, stripe, mailerlite
├── public/
│   ├── index.html           Order page
│   ├── status.html          Status widget example
│   ├── vso/                 Embeddable order app + status widget
│   └── admin/               Admin panel (vanilla JS, no build step)
├── tools/wp-export.php      WordPress → D1 data migration
└── test/                    Unit tests (offline) + smoke test (local server)
```

## Local development

```bash
cd standalone
npm install
cp .dev.vars.example .dev.vars        # sets DEV_ADMIN_EMAIL for a local-only admin login
npm run db:migrate:local
npm run db:seed:local                 # optional: sample menu
npm run dev                           # http://localhost:8788  ·  admin: http://localhost:8788/admin/
```

Without Stripe keys the shop takes orders without online payment; without `RESEND_API_KEY` emails are logged instead of sent. Add test keys to `.dev.vars` to try payments locally (card `4242 4242 4242 4242`).

Tests:

```bash
npm test               # offline unit tests: Stripe verification, webhooks, emails, Access JWT, CORS
npm run test:smoke     # end-to-end against the running local server (creates/deletes data; restores settings)
```

## Deploy to Cloudflare

Requires a Cloudflare account; a domain on Cloudflare is recommended (needed for Access on the production hostname).

1. **Log in**: `npx wrangler login`
2. **Create the database** and paste its `database_id` into `wrangler.toml`:
   ```bash
   npx wrangler d1 create vs-orders
   npm run db:migrate:remote
   npm run db:seed:remote          # only for a fresh menu — skip if you import from WordPress
   ```
3. **Create the image bucket**: `npx wrangler r2 bucket create vs-orders-media`
4. **Create the Pages project and deploy**:
   ```bash
   npx wrangler pages project create vs-orders --production-branch main
   npm run deploy
   ```
   Or connect the Git repo in the dashboard (Workers & Pages → Create → Pages → Connect to Git) with **root directory** `standalone`, no build command, output directory `public`.
5. **Custom domain**: Pages project → Custom domains → e.g. `orders.visitsicilynyc.com`.
6. **Secrets** (each command prompts for the value):
   ```bash
   npx wrangler pages secret put RESEND_API_KEY --project-name vs-orders
   npx wrangler pages secret put STRIPE_TEST_PUBLISHABLE_KEY --project-name vs-orders
   npx wrangler pages secret put STRIPE_TEST_SECRET_KEY --project-name vs-orders
   npx wrangler pages secret put STRIPE_TEST_WEBHOOK_SECRET --project-name vs-orders
   npx wrangler pages secret put STRIPE_LIVE_PUBLISHABLE_KEY --project-name vs-orders
   npx wrangler pages secret put STRIPE_LIVE_SECRET_KEY --project-name vs-orders
   npx wrangler pages secret put STRIPE_LIVE_WEBHOOK_SECRET --project-name vs-orders
   npx wrangler pages secret put MAILERLITE_API_KEY --project-name vs-orders   # optional
   ```
   Test vs. live is switched from the admin panel (Settings → Stripe); both key sets can coexist.
7. **Cloudflare Access** (Zero Trust → Access → Applications → Add → Self-hosted):
   - Application domain: `orders.visitsicilynyc.com` with **two paths**: `admin` and `api/admin`.
   - Policy: Allow → Emails → the owner and the delivery managers (or a Google login / email OTP rule).
   - Copy the **Application Audience (AUD) tag** and your **team domain** (`<team>.cloudflareaccess.com`) into `wrangler.toml` → `ACCESS_AUD`, `ACCESS_TEAM_DOMAIN`; put the owner email in `ADMIN_EMAILS`; redeploy.
   - Add delivery managers in the panel (Users). Being allowed by Access is not enough — the Worker also requires the email to be in `ADMIN_EMAILS` or the Users list.
   - The Worker verifies the Access JWT itself, so any hostname *not* covered by Access (e.g. `*.pages.dev`) simply returns 401 for the admin API.
8. **Stripe webhook** (Developers → Webhooks, once per mode): endpoint `https://orders.visitsicilynyc.com/api/stripe/webhook`, events `payment_intent.succeeded` and `charge.refunded`. Save each signing secret as `STRIPE_TEST_WEBHOOK_SECRET` / `STRIPE_LIVE_WEBHOOK_SECRET`. Enable Apple Pay / Google Pay / PayPal etc. in Stripe → Payment methods (Apple Pay also needs the domain registered there).
9. **Email (Resend)**: verify the sending domain in Resend and keep `EMAIL_FROM` in `wrangler.toml` on that domain.

## Migrating from WordPress

1. Copy `tools/wp-export.php` to the WordPress server and run: `wp eval-file wp-export.php > vs-orders-export.sql`
2. Import: `npx wrangler d1 execute vs-orders --remote --file vs-orders-export.sql`

It **replaces** categories, products, orders (with items and messages) and subscribers, keeps IDs and order references, converts times to UTC, and imports settings and the WP administrators / Delivery Managers as panel users. Secrets are not exported (step 6 above). Product images keep their WordPress URLs until you re-upload them in Products → Edit, so keep the WordPress media reachable until then.

## Embedding in another site

The shop works on its own at `/`. To put it inside another site (the old shortcodes), copy the snippets from Admin → Settings → Embed:

```html
<link rel="stylesheet" href="https://orders.example.com/vso/order-app.css">
<div id="vso-order-app"></div>
<script src="https://orders.example.com/vso/order-app.js" data-api="https://orders.example.com/api"></script>

<div class="vso-status" data-order-url="https://orders.example.com/"></div>
<script src="https://orders.example.com/vso/status-widget.js" data-api="https://orders.example.com/api" defer></script>
```

When the host site is on a different domain, add it to `ALLOWED_ORIGINS` (comma-separated) in `wrangler.toml`.

## Configuration reference

| Name | Where | Purpose |
|---|---|---|
| `DB`, `MEDIA` | `wrangler.toml` bindings | D1 database, R2 bucket for product images |
| `TIMEZONE` | var | Business time zone (order refs, "today" stats). Default `America/New_York` |
| `EMAIL_FROM`, `EMAIL_REPLY_TO` | var | Sender for all emails (domain verified in Resend) |
| `ACCESS_TEAM_DOMAIN`, `ACCESS_AUD` | var | Cloudflare Access verification |
| `ADMIN_EMAILS` | var | Always-admin emails (bootstrap) |
| `ALLOWED_ORIGINS` | var | Origins allowed to call the public API cross-site |
| `SITE_URL` | var (optional) | Public base URL for links in emails, if different from the request host |
| `STRIPE_{TEST,LIVE}_{PUBLISHABLE_KEY,SECRET_KEY,WEBHOOK_SECRET}` | secrets | Stripe |
| `RESEND_API_KEY` | secret | Email sending |
| `MAILERLITE_API_KEY` | secret | Subscriber sync (group ID is set in the panel) |
| `DEV_ADMIN_EMAIL` | `.dev.vars` only | Local admin login; ignored off localhost or when Access is configured |

Everything else (weekly zone, minimum, capacity, texts, business info, Stripe mode, admin email) is edited in the admin panel and stored in the `settings` table.

## API

Public (`/api`): `GET /products`, `GET /status`, `POST /orders`, `POST /subscribe`, `POST /stripe/create-intent`, `POST /stripe/webhook`.

Admin (`/api/admin`, Access + role; non-GET requests need header `X-Requested-With: vso-admin`): `me`, `dashboard`, `orders` (+ `/:id`, `/:id/status`, `/:id/payment`, `/:id/messages`), `products` (+ `/:id`, `/:id/toggle`, `/bulk-toggle`), `uploads`, `categories` (+ `/:id`, `/reorder`), `settings`, `delivery/toggle`, `subscribers` (+ `.csv`, `/notify`, `/:id`), `users` (admin), `reset` (admin).

## Changes vs. the WordPress plugin

- **Payments are verified server-side.** The plugin trusted `payment_status: "paid"` sent by the browser and the amount sent by the browser; now the server computes the amount from the cart, retrieves the PaymentIntent from Stripe, checks amount/status, and a PaymentIntent can back only one order. The webhook requires a valid signature (the plugin accepted unsigned events when no secret was set).
- If the round fills up between payment and order creation, the payment is **refunded automatically**.
- Redirect-based methods (PayPal, Klarna, Cash App…) now complete the order after returning; Apple Pay / Google Pay now actually confirm the payment before placing the order.
- Capacity and order numbers are reserved atomically (no double-booking under concurrent orders).
- Customer input is HTML-escaped in emails, admin and frontend.
- Orders can be marked paid/pending/refunded manually (useful for cash); subscribers can be removed individually; categories can be reordered with ▲▼ on mobile.
- Business name/address/phone/website used in emails and tickets are editable (Settings → Business).
- Not ported: parsing incoming customer email replies (`vso_process_incoming_email` existed in the plugin but was never wired up); the WP dashboard widget is replaced by the panel's dashboard.
