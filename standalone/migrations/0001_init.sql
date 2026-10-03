-- VS Orders — initial schema (replaces WP CPTs, taxonomy, term/post meta, wp_options and wp_vso_messages)

CREATE TABLE categories (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  slug        TEXT NOT NULL UNIQUE,
  name        TEXT NOT NULL,
  icon        TEXT NOT NULL DEFAULT '',
  sort_order  INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE products (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  category_id  INTEGER REFERENCES categories(id) ON DELETE SET NULL,
  name         TEXT NOT NULL,
  description  TEXT NOT NULL DEFAULT '',
  price        REAL NOT NULL DEFAULT 0,
  image_url    TEXT NOT NULL DEFAULT '',
  active       INTEGER NOT NULL DEFAULT 1,
  sort_order   INTEGER NOT NULL DEFAULT 0,
  created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);
CREATE INDEX idx_products_category ON products(category_id);
CREATE INDEX idx_products_active   ON products(active);

CREATE TABLE orders (
  id                     INTEGER PRIMARY KEY AUTOINCREMENT,
  ref                    TEXT NOT NULL UNIQUE,
  status                 TEXT NOT NULL DEFAULT 'new'
                         CHECK (status IN ('new','confirmed','preparing','delivered','cancelled')),
  subtotal               REAL NOT NULL,
  total                  REAL NOT NULL,
  customer_name          TEXT NOT NULL,
  customer_email         TEXT NOT NULL,
  customer_phone         TEXT NOT NULL DEFAULT '',
  customer_address       TEXT NOT NULL DEFAULT '',
  customer_zone          TEXT NOT NULL DEFAULT '',
  notes                  TEXT NOT NULL DEFAULT '',
  payment_status         TEXT NOT NULL DEFAULT 'pending'
                         CHECK (payment_status IN ('pending','paid','refunded')),
  stripe_payment_intent  TEXT,
  created_at             TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
  updated_at             TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);
CREATE INDEX idx_orders_status     ON orders(status);
CREATE INDEX idx_orders_zone       ON orders(customer_zone);
CREATE INDEX idx_orders_created_at ON orders(created_at);
-- A paid PaymentIntent can back exactly one order.
CREATE UNIQUE INDEX idx_orders_stripe_pi ON orders(stripe_payment_intent) WHERE stripe_payment_intent IS NOT NULL;

-- Snapshot of name/price at order time; product_id kept only as a soft link.
CREATE TABLE order_items (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id    INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  product_id  INTEGER REFERENCES products(id) ON DELETE SET NULL,
  name        TEXT NOT NULL,
  price       REAL NOT NULL,
  qty         INTEGER NOT NULL
);
CREATE INDEX idx_order_items_order ON order_items(order_id);

CREATE TABLE messages (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id    INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  direction   TEXT NOT NULL DEFAULT 'outgoing' CHECK (direction IN ('outgoing','incoming')),
  channel     TEXT NOT NULL DEFAULT 'email',
  subject     TEXT NOT NULL DEFAULT '',
  body        TEXT NOT NULL,
  sender      TEXT NOT NULL DEFAULT '',
  message_id  TEXT NOT NULL DEFAULT '',
  status      TEXT NOT NULL DEFAULT 'sent',
  created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
  read_at     TEXT
);
CREATE INDEX idx_messages_order ON messages(order_id);

CREATE TABLE subscribers (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  email       TEXT NOT NULL UNIQUE COLLATE NOCASE,
  name        TEXT NOT NULL DEFAULT '',
  zone        TEXT NOT NULL DEFAULT '',
  ml_synced   INTEGER NOT NULL DEFAULT 0,
  created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

CREATE TABLE settings (
  key    TEXT PRIMARY KEY,
  value  TEXT NOT NULL DEFAULT ''
);

CREATE TABLE admin_users (
  email       TEXT PRIMARY KEY COLLATE NOCASE,
  role        TEXT NOT NULL CHECK (role IN ('admin','manager')),
  created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

INSERT OR IGNORE INTO settings (key, value) VALUES
  ('delivery_active', '0'),
  ('weekly_zone', ''),
  ('weekly_delivery_day', ''),
  ('weekly_deadline', ''),
  ('minimum_order', '100'),
  ('max_orders', '0'),
  ('current_order_count', '0'),
  ('last_order_seq', '0'),
  ('ubereats_url', 'https://www.ubereats.com/store/visit-sicily-86-east-7th-street/IyYwTzfDX9eSRteM088nig'),
  ('closed_message', 'We''re preparing the next delivery round! Subscribe to get notified when we come to your area.'),
  ('how_title', 'How Our Delivery Works'),
  ('how_steps', '[{"title":"Check the weekly area","desc":"We open deliveries for one area each week."},{"title":"Place your order","desc":"Order from the menu before the deadline."},{"title":"Personal delivery","desc":"Nicolas & Vincenzo drive to you, 11AM–7PM."},{"title":"We message you","desc":"We''ll text before arrival."}]'),
  ('how_note', 'Minimum order: {{minimum}} · Areas change weekly · Orders close at capacity'),
  ('checkout_note', 'Nicolas & Vincenzo will deliver personally between 11AM–7PM. We''ll message you before arrival.'),
  ('confirm_title', 'Grazie mille!'),
  ('confirm_message', 'Your order has been received! We''ll message you before arrival.'),
  ('admin_email', ''),
  ('stripe_mode', 'test'),
  ('mailerlite_group_id', ''),
  ('last_subscriber_notify', ''),
  ('business_name', 'Visit Sicily NYC'),
  ('business_address', '86 E 7th St, New York, NY 10003'),
  ('business_phone', '(917) 261-2033'),
  ('business_website', 'visitsicilynyc.com'),
  ('business_instagram', '@visitsicilynyc'),
  ('order_page_url', '');
