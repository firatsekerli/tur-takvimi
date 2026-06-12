=== Tur Takvimi ===
Contributors: turtakvimi
Tags: tour, calendar, delivery, route, local-seo, preorder, woocommerce
Requires at least: 6.2
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Resellable, white-label tour calendar + location SEO + pre-order system for
mobile and route-based delivery businesses.

== Description ==

Tur Takvimi turns a weekly delivery tour into a customer-facing system:

* Weekly tour calendar (server-rendered for SEO).
* Postcode search: a customer types their postcode and sees the nearest stop
  and the next visit date.
* City-level SEO/geo pages (one page per location).
* A recurrence engine that materializes routes that repeat every N weeks.
* Fully white-label: brand name, colors, slug base, country, currency and
  more live in settings — nothing is hardcoded.

The plugin has two layers:

1. **Core** (this plugin) — works standalone, no dependencies.
2. **Commerce add-on** (requires WooCommerce) — products per tour, cart and
   checkout, an upfront-payment discount, and orders locked to a delivery
   date and stop. Loads only when WooCommerce is active.

== Shortcodes ==

* `[tur_takvimi_calendar weeks="3"]` — the weekly tour calendar.
* `[tur_takvimi_postcode_search]` — the nearest-stop postcode finder.

Both work in Breakdance, Gutenberg, the classic editor and any theme.

== Changelog ==

= 0.1.0 =
* Initial scaffold: post types, settings, recurrence engine, postcode search,
  calendar + search shortcodes, CSV importer, bundled NL postcode dataset.
