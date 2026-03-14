# Rocky River Hills – Claude Code Context

## Project
WordPress/WooCommerce plugin suite. PHP 8.x, OceanWP/Elementor, Hostinger.
Plugin dir: `/wp-content/plugins/rt-*/`
Site: rockyriverhills.com | Albemarle, NC

## Conventions
- Prefix: `RT_` (classes), `rt_` (functions/hooks), `rt-` (handles/slugs)
- No trailing `?>` in PHP files
- Nonces on all AJAX. `current_user_can()` before any data write.
- Elementor bypasses `has_shortcode()` — always enqueue frontend assets unconditionally
- Brand: `#A2755A` brown, white text, `#e9e9e9` bg, Poppins font
- Chicago screws + cast acrylic = product assembly method (context for copy tasks)

## Plugin Registry
| Slug | Purpose |
|------|---------|
| rt-traffic-tracker | Analytics, UTM tracking, heatmaps, CSV export, bot filtering |
| rt-image-watermark | Tiled watermark, preserves originals for shopping feeds |
| rt-email-sequences | Early checkout capture, cart abandonment, auto-coupons |
| rt-social-proof | Social proof notifications |
| rt-instagram-poster | Auto-post + product tagging |
| rt-pinterest-poster | Standard API (approved) |
| rt-google-shopping-feed | XML product feed |
| rt-meta-shopping-feed | Meta/Facebook catalog feed |
| rt-schema-markup | JSON-LD structured data |
| rt-upcoming-events | Event listing/display |
| rt-live-scores-ticker | ESPN public API, auto-scroll, WooCommerce product search |

## Defaults (don't restate in prompts)
- Write full working code, no stubs or placeholders
- WordPress coding standards throughout
- Settings via `add_options_page()` + Settings API
- Escape all output: `esc_html()`, `esc_url()`, `wp_kses()`
- Sanitize all input: `sanitize_text_field()`, `absint()`, etc.

## Known Gotchas
- Jetpack zombie crons conflict with WC Payments/Shipping/Tax — products disappear daily if not handled; cron managed server-side
- Elementor renders shortcodes without triggering `has_shortcode()` — never gate asset enqueue on shortcode detection
- OceanWP Free theme — no premium theme features available
