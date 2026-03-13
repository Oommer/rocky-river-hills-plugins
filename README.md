# Rocky River Hills — WordPress Plugin Suite

Custom WordPress/WooCommerce plugins and site code for [rockyriverhills.com](https://rockyriverhills.com) — handmade stadium coasters & wall art, Albemarle, NC.

Built and maintained by Brady Kohler in collaboration with Claude (Anthropic).

---

## Site Details

| Detail | Value |
|---|---|
| **URL** | rockyriverhills.com |
| **Host** | Hostinger |
| **Server path** | `/home/u648786421/domains/rockyriverhills.com/public_html/` |
| **Theme** | OceanWP Free + Elementor |
| **Content width** | 1140–1180px |
| **Caching** | Hostinger Automatic Cache (server-level, NOT LiteSpeed plugin) |
| **Brand color** | `#A2755A` (brown), white text on buttons |
| **Font** | Poppins |

---

## Plugin Index

### 1. `rt-traffic-tracker` — Real-Time Traffic Tracker
**Current version:** 3.2.1

Self-hosted analytics dashboard. Replacement for Matomo/Google Analytics.

**Features:**
- Real-time visitor cards (5-minute window, auto-refresh every 30s)
- World map with Leaflet + OpenStreetMap (city-clustered markers, green = active last 2hrs, blue = historical)
- Visitor profiles with full session history
- Bot filtering: empty UAs, 100+ keyword blocklist, browser engine validation, data center IP blocking (AWS/GCP/Azure/DO/Hetzner/OVH)
- WooCommerce conversion funnel (Shop → Product → Cart → Checkout → Order Complete)
- UTM campaign tracking
- Site search query logging
- Exit page analysis
- New vs returning visitor breakdown with pie charts
- Peak hours heatmap
- CSV export
- Weekly email digest (WP cron)
- Entry pages analysis
- Visitor flow diagrams
- WordPress dashboard widget
- Page load times (browser Performance API, stored in ms)
- Purge button (double-confirmed, TRUNCATE TABLE)

**DB table:** `wp_rt_traffic_tracker`
**Geo lookup:** ip-api.com (country, city, lat, lng)
**Assets:** Chart.js, Leaflet — admin only, never loaded on frontend

---

### 2. `rt-email-sequences` — Email Sequences
**Current version:** 1.3.0

Automated WooCommerce email flows and abandoned cart recovery.

**Features:**
- Early email capture on checkout (before order complete)
- Cart restoration recovery links
- Auto-generated unique coupons per customer
- Abandoned cart recovery (replaces standalone abandoned cart plugin)
- Revenue recovery dashboard

**Email template branding:**
- Background: `#f0eeeb` (warm beige)
- Font: Poppins
- Signature divider: three brown dots (`#A2755A`) flanked by horizontal bars
- Footer: "Handmade stadium coasters & wall art — Albemarle, NC"
- Logo: must be PNG (GIF does not render reliably in email clients)

**Note:** Fixed corruption bug caused by WordPress magic quotes + tab-aware toggle handling. Uses `wp_unslash()` on all saved settings.

---

### 3. `rt-google-shopping` — Google Shopping Feed
**Current version:** 1.3.1

WooCommerce → Google Merchant Center product feed.

**Features:**
- XML feed generation
- Diagnostic tool
- `g:quantity` attribute support
- Shipping labels: `coasters` @ $6.99, `stadiums` @ $9.99, free over $100
- Settings race condition fix
- 0-products bug fix (WooCommerce `is_visible()` filter)

**Bug fixed in 1.3.1:** `schedule_regeneration()` declared `private` but registered as WP hook — caused fatal error when any plugin triggered `woocommerce_update_product`.

---

### 4. `rt-meta-shopping` — Meta Shopping Feed
**Current version:** 1.1.1

WooCommerce → Meta/Facebook product catalog feed.

**Features:**
- Product catalog XML/CSV feed
- Checkout URL handler for Meta Commerce integration
- Setup guide included
- Meta Catalog ID: `1610766919969813`

**Bug fixed in 1.1.1:** Same `private` method / WP hook fatal error as Google Shopping (fixed same session).

---

### 5. `rt-instagram-poster` — Instagram Poster
**Current version:** 3.4

Auto-posts WooCommerce products to Instagram with Shopping product tagging.

**Features:**
- Carousel post support
- Instagram Shopping product tagging
- Category-interleaved post scheduling
- Permanent System User token (no expiry)

**Key IDs:**
| Key | Value |
|---|---|
| Meta Catalog ID | `1610766919969813` |
| Instagram User ID | `17841457163070838` |
| Facebook Page ID | `110995055209248` |
| App ID | `2539917699858917` |

**Required token permissions:** `business_management`, `catalog_management`, `instagram_basic`, `instagram_content_publish`, `instagram_manage_comments`, `instagram_shopping_tag_products`, `pages_read_engagement`

**Tag position:** `x: 0.8, y: 0.8` (bottom-right)

**Tagging flow:** Publish via `graph.instagram.com` → look up Page → IG Business Account → most recent Graph API media ID → get carousel children → tag each child with `updated_tags` + `x`/`y` via `graph.facebook.com`

**Known quirk:** Firefox's "Don't show again" on confirm dialogs can suppress plugin confirm() calls. Fix via `about:config` → `dom.successive_dialog_time_limit = 0`.

---

### 6. `rt-pinterest-poster` — Pinterest Poster
**Current version:** 1.4.5

Auto-posts WooCommerce products to Pinterest. Standard API (approved).

**Features:**
- Sandbox / Production mode toggle
- Inline board editor (name + description)
- Category-interleaved scheduling (prevents same stadium products posting consecutively)
- Smart short description truncation (no mid-word cuts)
- Strips heading lines ("Specifications:", "Product Features:") from pin descriptions

---

### 7. `rt-upcoming-events` — Upcoming Events
**Current version:** 1.3.2

Events display plugin for the frontend.

**Features:**
- Column width controls
- Full-width preview matching 1140px site layout
- Smart Apple/Google Maps detection

---

### 8. `rt-live-scores-ticker` — Live Scores Ticker
**Current version:** 2.0.0

ESPN API-powered live scores ticker bar for the site.

**Features:**
- ESPN public scoreboard API endpoints
- Server-side PHP rendering (no AJAX dependency for initial load)
- ESPN-style dark ticker bar
- Team logos, records, scores
- Auto-scrolling with seamless loop
- WooCommerce product scanning: extracts team names from product titles, nicknames from descriptions
- Auto-generates search shop links: `?s=cincinnati&post_type=product`

---

### 9. `rt-schema-markup` — Schema Markup
**Current version:** 1.0.0

Replaces WooCommerce's basic product schema with comprehensive JSON-LD.

**Schema types:** Product, Organization, WebSite, BreadcrumbList, LocalBusiness (optional)

---

### 10. `wc-admin-review-manager` — Admin Review Manager
**Current version:** 1.0.0

Admin UI for adding product reviews without waiting for customer submissions.

**Location:** WooCommerce → Add Reviews

**Features:**
- Product selector dropdown
- Reviewer name, email, star rating (1–5), review text
- "Verified Purchase" badge: `#A2755A` brown background, white text, rounded pill
- Properly updates WooCommerce review meta fields and clears cached data

---

## Site Code (`site-code/`)

Snippets deployed via **Insert Headers and Footers** plugin or Elementor Custom CSS.

### `announcement-bar.css`
Scrolling top announcement bar. Two alternating messages on a 40-second cycle with fade in/out at edges:
1. "FREE SHIPPING ON ALL ORDERS OVER $100"
2. "DON'T SEE YOUR TEAM? MESSAGE US. WE CAN MAKE IT!"

Uses single `body:before` pseudo-element with content switching at the 50% keyframe mark.

### `cookie-banner.html`
CCPA-compliant cookie consent banner (bottom bar). Poppins font, brown circular close button (`#A2755A`), "OK!" / "Nope!" buttons, localStorage persistence.

### `svg-dividers.html`
Two SVG section dividers:
- "DISCOVER OUR NEWEST ARRIVALS"
- "CHECK OUT OUR BEST SELLERS"

Style: `#A2755A` brown + `#6B6B6B` gray, Poppins, horizontal bars + three brown dots. Mobile scale fix: `transform: scale(2.0)` on screens ≤768px (via `.bestsellers-svg` and `.newarrivals-svg` wrapper divs).

### `dark-mode-fixes.css`
Darkify plugin dark mode customizations:
- Blog pages only: links `#A2755A`, hover `#835B47`
- Sub-menus: brown background + white text, site-wide
- Uses `html.darkify_dark_mode_enabled` + `body.single-post` / `body.blog` selectors

### `hamburger-fix.css`
3-line hamburger menu white in Darkify dark mode. Targets `.hamburger-inner` and `::before`/`::after` pseudo-elements only — does not affect cart or search icons.

---

## Known Issues & Notes

- **Elementor bypasses `has_shortcode()`** — all frontend assets must enqueue unconditionally, not conditionally on shortcode detection
- **Hostinger OPcache** — after PHP file updates, manually clear OPcache in hPanel or hard-reload. Stale files can cause plugins to behave as old versions
- **Shop page disappearing** — caused by WooCommerce Payments/Shipping/Tax plugins recreating Jetpack cron jobs even after Jetpack deletion. Workaround: server-level cron every minute clears WC transients + flushes permalinks
- **WP hook callbacks must be `public`** — any method registered via `add_action()` or `add_filter()` must be `public`, not `private`. PHP fatal error otherwise when WordPress tries to call it externally
- **Email logo** — must be PNG. GIF format does not render reliably across Gmail, Outlook, and other email clients

---

## Development Workflow

1. Upload current plugin zip to Claude
2. Describe the change or bug
3. Claude returns updated zip with bumped version number
4. Replace files via FTP or WordPress plugin uploader
5. Clear Hostinger OPcache if needed
6. Update files in this repo via GitHub Desktop
7. Commit with message format: `plugin-name vX.X.X — description of change`
