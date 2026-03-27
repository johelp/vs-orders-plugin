# VS Orders Plugin — Development Continuation Prompt

Use this prompt when continuing development of the VS Orders WordPress plugin with an AI assistant.

---

## Prompt

```
I'm working on a WordPress plugin called "VS Orders" (vs-orders-plugin). It's a weekly delivery ordering system originally built for Visit Sicily NYC (a Sicilian bakery/café in East Village, NYC) but designed to be made generic for other food businesses.

## Current Version: 1.3.0

## Architecture

The plugin is self-contained PHP with vanilla JS frontend (no build step). Here's the complete structure:

### File Map

vs-orders-plugin/
├── vs-orders.php — Main plugin file
│   - Plugin header, constants (VSO_VERSION, VSO_PLUGIN_DIR, VSO_PLUGIN_URL)
│   - Requires all includes/* and admin/* files
│   - admin_init hook: handles delivery toggle + subscriber notify redirects (BEFORE headers sent)
│   - Activation hook: registers CPTs, creates DB table, sets default options, seeds 57 products
│   - Function: vso_seed_products() — populates menu from Visit Sicily NYC
│
├── includes/
│   ├── cpt-products.php
│   │   - CPT: vs_product (public:false, show_ui:false)
│   │   - Taxonomy: vs_product_cat (hierarchical)
│   │   - Meta: _vso_price (float), _vso_description (text), _vso_active ('0'/'1')
│   │   - Term meta: _vso_icon (emoji), _vso_order (int for sorting)
│   │
│   ├── cpt-orders.php
│   │   - CPT: vs_order (public:false, show_ui:false)
│   │   - vso_generate_order_ref() → "VS-YYMMDD-XXXX" format
│   │   - vso_create_order($data) → creates post + all meta, returns {order_id, ref}
│   │   - vso_order_statuses() → array of statuses with label, color, bg
│   │   - Meta keys: _vso_ref, _vso_status (new/confirmed/preparing/delivered/cancelled),
│   │     _vso_items (serialized array of {id,name,price,qty}), _vso_subtotal, _vso_total,
│   │     _vso_customer_name/email/phone/address/zone/notes,
│   │     _vso_payment_status (pending/paid/refunded), _vso_stripe_payment_intent, _vso_created_at
│   │
│   ├── rest-api.php — All REST routes under namespace vs-order/v1
│   │   PUBLIC:
│   │   - GET /products → returns {categories, products, deliveryActive, atCapacity, minimumOrder, weeklyZone, weeklyDeadline, weeklyDeliveryDay, uberEatsUrl, closedMessage, orderCount, maxOrders}
│   │   - POST /orders → validates: delivery active, not at capacity, min order, active products. Server-side price calculation. Increments vso_current_order_count. Sends emails.
│   │   - POST /subscribe → calls vso_add_subscriber() from subscribers.php
│   │   - POST /stripe/create-intent → creates Stripe PaymentIntent (amount in cents, USD, automatic_payment_methods enabled)
│   │   - POST /stripe/webhook → verifies signature, handles payment_intent.succeeded
│   │   ADMIN (manage_options):
│   │   - POST /orders/{id}/status → updates status, sends status email
│   │   - POST /products/{id}/toggle → toggles _vso_active
│   │   - GET /orders/{id}/messages → returns message thread
│   │   - POST /orders/{id}/messages → sends email + logs to DB
│   │   - POST /delivery/toggle → toggles delivery, resets order count on open
│   │
│   ├── shortcode.php
│   │   - [visit_sicily_order] → renders #vso-order-app div + VSO_CONFIG JS object + includes order-app.php
│   │     VSO_CONFIG passes: apiBase, nonce, stripeEnabled, stripePk, stripeTestMode, content:{howTitle, howSteps[], howNote, checkoutNote, confirmTitle, confirmMessage}
│   │   - [visit_sicily_status] → server-rendered PHP widget showing open/closed status with zone info and action buttons
│   │
│   ├── emails.php
│   │   - vso_send_order_confirmation($order_id) → branded HTML email to customer
│   │   - vso_send_admin_notification($order_id) → notification to admin email
│   │   - vso_send_status_update_email($order_id, $new_status) → status change to customer
│   │   - All emails use From: admin_email to avoid SPF/DKIM issues
│   │
│   ├── stripe.php
│   │   - vso_api_stripe_create_intent() → wp_remote_post to Stripe API (no SDK)
│   │   - vso_api_stripe_webhook() → manual signature verification
│   │   - vso_stripe_is_configured() / vso_stripe_is_test_mode() helpers
│   │
│   ├── messaging.php
│   │   - Custom table: {prefix}_vso_messages (id, order_id, direction, channel, subject, body, sender, message_id, status, created_at, read_at)
│   │   - vso_create_messages_table() → dbDelta
│   │   - vso_save_message($data), vso_get_order_messages($order_id)
│   │   - vso_api_send_message() → sends email using wp_mail + logs with status sent/failed
│   │   - vso_process_incoming_email() → parses ref from subject, stores as incoming
│   │
│   └── subscribers.php
│       - vso_add_subscriber({email, name, zone}) → stores in wp_option vso_subscribers + syncs to MailerLite
│       - vso_mailerlite_sync() → wp_remote_post to MailerLite API v2 (non-blocking)
│       - vso_notify_subscribers_delivery_open() → mass email with branded template + order link
│       - vso_subscriber_stats() → total count + zone breakdown + last notify info
│
├── admin/
│   ├── dashboard.php — Admin menu registration (Sicily Orders + 5 submenus) + dashboard page with stats, status chips, delivery toggle, recent orders
│   ├── orders-page.php — Orders list (filterable by status/zone/payment/search) + order detail view with customer info, items table, status select, messaging thread, and PRINT TICKET (80mm thermal format with @media print)
│   ├── products-page.php — Product CRUD with AJAX toggle switches, category filter, modal edit
│   ├── categories-page.php — Category CRUD with emoji icons + drag-and-drop reorder (saves to _vso_order term_meta)
│   ├── settings.php — Form-based settings page (delivery config, content editor, Stripe keys, notifications, MailerLite, subscriber list)
│   ├── messages-page.php — Redirect to orders (messaging is embedded in order detail)
│   └── wp-dashboard-widget.php — WordPress Dashboard widget via wp_dashboard_setup hook
│
├── public/
│   └── order-app.php — Complete frontend in one file (CSS + vanilla JS)
│       - State machine: loading → menu → checkout → confirm
│       - Reads VSO_CONFIG + fetches /products API on load
│       - Menu: collapsible "How it works", zone banner (green=open, red=closed), min order progress bar, sticky category tabs, product cards with +/− buttons, cart bar with total
│       - Checkout: order summary, delivery note, form fields (oninput for instant validation), Stripe Payment Element + Express Checkout Element, submit button enables in real-time
│       - Confirm: ref number, order details, email confirmation note
│       - Closed state: message + "Get Notified" (subscribe form with name/email/zone) + "Order on Uber Eats" link
│       - CSS: touch-action:manipulation (no double-tap zoom), font-size:16px on inputs (no iOS zoom), scrollable categories with visible scrollbar on desktop
│       - No build step, no React, no npm — pure vanilla JS
│
└── templates/ — Reserved for future use

## Key Design Decisions
- No WooCommerce dependency — lightweight CPTs + custom REST API
- No React/npm build — vanilla JS in PHP file for zero deployment complexity
- Stripe API calls via wp_remote_post (no Stripe PHP SDK needed)
- All text content editable from admin (no hardcoded strings in frontend)
- MailerLite sync is optional and non-blocking
- Email From address uses admin_email to avoid SPF/DKIM rejection
- Delivery toggle runs on admin_init hook (not page callback) to avoid header issues
- Form inputs use oninput (not onchange) so submit button enables in real-time
- touch-action:manipulation prevents double-tap zoom on mobile
- Input font-size:16px prevents iOS auto-zoom on focus

## wp_options Keys
vso_delivery_active ('0'/'1'), vso_weekly_zone, vso_weekly_delivery_day, vso_weekly_deadline, vso_minimum_order (float), vso_max_orders (int), vso_current_order_count (int), vso_stripe_publishable_key, vso_stripe_secret_key, vso_stripe_webhook_secret, vso_admin_email, vso_ubereats_url, vso_closed_message, vso_how_title, vso_how_it_works (JSON), vso_how_note, vso_checkout_note, vso_confirm_title, vso_confirm_message, vso_mailerlite_api_key, vso_mailerlite_group_id, vso_subscribers (array), vso_last_subscriber_notify (array), vso_last_order_seq (int)

## Known Bug History (all fixed)
- Toggle delivery caused white screen → moved to admin_init hook
- Place Order button stayed disabled → changed onchange to oninput + updateSubmitBtn()
- Admin messages didn't send → From address SPF/DKIM issue, now uses admin_email
- Categories didn't scroll on desktop → added explicit overflow-x:auto + visible scrollbar
- Double-tap zoom on mobile → touch-action:manipulation on container + interactive elements
- iOS auto-zoom on input focus → font-size:16px on all inputs

## Planned Features
- WhatsApp notifications via Baileys (Node.js sidecar)
- Generic/white-label version with configurable branding + currency
- MercadoPago payment gateway (for Argentina)
- Customer order tracking page by reference number
- Recurring orders / favorites

## Current Task
[DESCRIBE WHAT YOU NEED TO DO HERE]

Please review the relevant files before making changes. When modifying files, provide the complete updated file or precise str_replace edits. The plugin has no build step — changes to PHP files are immediately live.
```

---

## Usage Notes

- **Copy everything between the ``` markers** as your prompt
- **Replace the last line** `[DESCRIBE WHAT YOU NEED TO DO HERE]` with your actual task
- The prompt gives the AI complete context about every file, function, option key, and design decision
- It includes the bug history so the AI doesn't reintroduce fixed bugs
- Upload the specific file(s) you need changed for best results
