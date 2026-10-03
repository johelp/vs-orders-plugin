# 🇮🇹 VS Orders — Weekly Delivery Ordering System

A WordPress plugin built for **Visit Sicily NYC** that powers a weekly personal delivery service. Customers browse the menu, build their order, and check out — while the business controls which areas get delivery each week, sets capacity limits, and manages everything from a custom admin dashboard.

Built to be **generic and reusable** for any food business that does scheduled/weekly deliveries.

> **Standalone version (no WordPress):** [`standalone/`](standalone/README.md) runs the same system on Cloudflare Pages + D1, with Cloudflare Access for the admin panel and a migration script for existing WordPress data.

---

## How It Works (Business Model)

This is not a typical "always-on" delivery system. The concept:

1. **Each week**, the business picks a delivery area (e.g., "Westchester County, NY")
2. **Orders open** — customers in that area place orders before a deadline
3. **The team delivers personally** on the scheduled day (11AM–7PM)
4. **Orders close** when the deadline passes or capacity is reached
5. Between delivery rounds, customers can **browse the menu** and **subscribe** to get notified

---

## Features

### Customer-Facing (Frontend)
- **Menu browser** with categories, prices, descriptions
- **Add-to-cart** with quantity controls (+/−), no page reloads
- **Minimum order enforcement** with visual progress bar ($100 default)
- **Weekly zone banner** showing current delivery area, day, and deadline
- **Checkout form** with name, email, phone, address, notes
- **Stripe Payment Element** — credit cards, Apple Pay, Google Pay, PayPal (auto-detected)
- **Order confirmation** with unique reference number (format: `VS-YYMMDD-XXXX`)
- **Closed state** — menu is browse-only with subscribe form + Uber Eats redirect
- **Subscribe form** captures name, email, zone for notification list
- **Mobile-optimized** — no double-tap zoom, proper input sizing, sticky category bar

### Admin Dashboard
- **WordPress Dashboard Widget** — quick stats + delivery toggle right from WP home
- **Custom Admin Panel** (Sicily Orders menu) with:
  - **Dashboard** — today's orders, revenue, status breakdown, delivery toggle
  - **Orders** — filterable list by status/zone/payment, search by ref/name/email/phone
  - **Order Detail** — customer info, items, status management, payment info, messaging thread, **print ticket** (thermal printer optimized, 80mm)
  - **Products** — CRUD with instant toggle on/off (AJAX), category filter, inline edit
  - **Categories** — CRUD with emoji icons, drag-and-drop reordering
  - **Settings** — all configuration in one place

### Delivery Management
- **Open/Close toggle** — one click from dashboard, settings, or WP widget
- **Weekly configuration** — area, delivery day, deadline, min order, max capacity
- **Auto-close at capacity** — stops accepting orders when limit reached
- **Order counter resets** when a new delivery round opens

### Payments (Stripe)
- **Stripe Payment Element** — shows all enabled payment methods automatically
- Apple Pay, Google Pay, PayPal, Klarna, Cash App — configured in Stripe Dashboard
- **Test mode support** — use `pk_test_` / `sk_test_` keys with test card `4242 4242 4242 4242`
- **Webhook support** for async payment confirmation
- Works without Stripe too (orders placed without online payment)

### Communication
- **Order confirmation emails** — branded HTML to customer + admin notification
- **Status update emails** — automatic when status changes (confirmed, preparing, delivered)
- **In-platform messaging** — send emails to customers from order detail, threaded by order
- **Subscriber notifications** — "Delivery is Open!" mass email with one click

### Subscriber System
- **Local storage** with name, email, zone, date
- **MailerLite sync** — automatic background sync (optional, non-blocking)
- **Stats by zone** — see demand per area
- **CSV export**
- **Mass notification** — notify all subscribers when delivery opens

### Content Management
- **All customer-facing text is editable** from Settings:
  - "How It Works" section (title, steps, footer note)
  - Checkout delivery note
  - Confirmation title and message
  - Closed state message
  - Supports `{{minimum}}` placeholder for min order amount

### Shortcodes
| Shortcode | Description |
|-----------|-------------|
| `[visit_sicily_order]` | Full ordering system (menu + cart + checkout) |
| `[visit_sicily_status]` | Status badge only (open/closed + zone info + action buttons) |

---

## File Structure

```
vs-orders-plugin/
├── vs-orders.php                    # Main plugin file, activation, seeding, admin_init hooks
│
├── includes/
│   ├── cpt-products.php             # CPT: vs_product + taxonomy: vs_product_cat
│   ├── cpt-orders.php               # CPT: vs_order + reference number generator
│   ├── rest-api.php                 # All REST endpoints (public + admin)
│   ├── shortcode.php                # [visit_sicily_order] + [visit_sicily_status]
│   ├── emails.php                   # Email templates (confirmation, admin notify, status update)
│   ├── stripe.php                   # Stripe Payment Intent creation + webhook handler
│   ├── messaging.php                # Messages DB table + send/receive API
│   └── subscribers.php              # Local subscriber storage + MailerLite sync + mass notify
│
├── admin/
│   ├── dashboard.php                # Admin menu registration + dashboard overview page
│   ├── orders-page.php              # Orders list + order detail + print ticket
│   ├── products-page.php            # Product CRUD with AJAX toggle
│   ├── categories-page.php          # Category CRUD with drag-and-drop reorder
│   ├── settings.php                 # All settings: delivery, content, Stripe, MailerLite, subscribers
│   ├── messages-page.php            # Redirect (messaging is in order detail)
│   └── wp-dashboard-widget.php      # WordPress Dashboard widget with stats + toggle
│
├── public/
│   └── order-app.php                # Frontend JS/CSS (vanilla JS, no build step)
│
└── templates/                       # Reserved for future email/print templates
```

---

## REST API Endpoints

### Public
| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/wp-json/vs-order/v1/products` | Menu items + delivery status + weekly config |
| `POST` | `/wp-json/vs-order/v1/orders` | Create order (validates min, capacity, active status) |
| `POST` | `/wp-json/vs-order/v1/subscribe` | Email subscription (name, email, zone) |
| `POST` | `/wp-json/vs-order/v1/stripe/create-intent` | Create Stripe PaymentIntent |
| `POST` | `/wp-json/vs-order/v1/stripe/webhook` | Stripe webhook receiver |

### Admin (requires `manage_options`)
| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/wp-json/vs-order/v1/orders/{id}/status` | Update order status |
| `POST` | `/wp-json/vs-order/v1/products/{id}/toggle` | Toggle product active/inactive |
| `GET` | `/wp-json/vs-order/v1/orders/{id}/messages` | Get message thread |
| `POST` | `/wp-json/vs-order/v1/orders/{id}/messages` | Send message to customer |
| `POST` | `/wp-json/vs-order/v1/delivery/toggle` | Toggle delivery open/close |

---

## Database

### Custom Post Types
- **`vs_product`** — Menu items (meta: `_vso_price`, `_vso_description`, `_vso_active`)
- **`vs_order`** — Orders (meta: `_vso_ref`, `_vso_status`, `_vso_items`, `_vso_total`, `_vso_customer_*`, `_vso_payment_status`, `_vso_stripe_payment_intent`, `_vso_created_at`)

### Taxonomy
- **`vs_product_cat`** — Menu categories (meta: `_vso_icon`, `_vso_order`)

### Custom Table
- **`{prefix}_vso_messages`** — Order messaging thread (order_id, direction, channel, body, sender, status, timestamps)

### wp_options Keys
| Key | Type | Description |
|-----|------|-------------|
| `vso_delivery_active` | `'0'/'1'` | Master delivery toggle |
| `vso_weekly_zone` | string | Current delivery area name |
| `vso_weekly_delivery_day` | string | Delivery day display text |
| `vso_weekly_deadline` | string | Order deadline display text |
| `vso_minimum_order` | float | Minimum order amount (default: 100) |
| `vso_max_orders` | int | Max orders per round (0 = unlimited) |
| `vso_current_order_count` | int | Current round order count |
| `vso_stripe_publishable_key` | string | Stripe publishable key |
| `vso_stripe_secret_key` | string | Stripe secret key |
| `vso_stripe_webhook_secret` | string | Stripe webhook signing secret |
| `vso_admin_email` | email | Admin notification email |
| `vso_ubereats_url` | url | Uber Eats store link |
| `vso_closed_message` | text | Message when delivery is closed |
| `vso_how_title` | string | "How It Works" section title |
| `vso_how_it_works` | JSON | Array of steps `[{title, desc}]` |
| `vso_how_note` | string | Footer note (supports `{{minimum}}`) |
| `vso_checkout_note` | string | Note shown in checkout |
| `vso_confirm_title` | string | Confirmation page title |
| `vso_confirm_message` | string | Confirmation page message |
| `vso_mailerlite_api_key` | string | MailerLite API token |
| `vso_mailerlite_group_id` | string | MailerLite group ID |
| `vso_subscribers` | array | Local subscriber list |
| `vso_last_subscriber_notify` | array | Last notification log |
| `vso_last_order_seq` | int | Order sequence counter |

---

## Installation

1. Upload `vs-orders-plugin/` to `/wp-content/plugins/`
2. Activate from Plugins page
3. Go to **Sicily Orders → Settings** to configure
4. Create a page and add `[visit_sicily_order]`
5. Optionally add `[visit_sicily_status]` to homepage or sidebar

### First Run
On activation the plugin seeds **57 products** from the Visit Sicily NYC menu across 11 categories. Delete or modify these from the Products page.

---

## Stripe Testing

1. Get test keys from [Stripe Dashboard → Developers → API Keys](https://dashboard.stripe.com/test/apikeys)
2. Enter `pk_test_...` and `sk_test_...` in Settings → Stripe
3. Use test card: `4242 4242 4242 4242`, any future date, any CVC
4. See [all test cards](https://docs.stripe.com/testing#cards)

---

## Tech Stack

- **PHP 7.4+** — no external dependencies, no Composer
- **WordPress 5.9+** — CPTs, REST API, wp_options, wp_mail
- **Vanilla JavaScript** — no React/Vue build step, inline in PHP
- **Stripe.js v3** — Payment Element + Express Checkout Element
- **MailerLite API v2** — optional subscriber sync

---

## Roadmap

- [ ] WhatsApp integration via Baileys (auto-notify customers)
- [ ] Generic version with configurable branding/currency
- [ ] MercadoPago integration for Argentina market
- [ ] Customer order tracking page (by ref number)
- [ ] Recurring order / favorites
- [ ] Multi-language support

---

## License

Proprietary — Built by Johel for Visit Sicily NYC / Nicolas Calia.
