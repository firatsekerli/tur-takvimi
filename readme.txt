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

* `[tur_takvimi_calendar weeks="3"]` — the weekly tour calendar (day list).
* `[tur_takvimi_calendar_month]` — a real month-grid calendar. Optional
  `country="DE"`, `months="1"` (1–3), and `id="123"` to scope to one city. On a
  single city page it defaults to that city automatically.
* `[tur_takvimi_postcode_search]` — the nearest-stop postcode finder.
* `[tur_takvimi_map]` — the delivery-regions explorer (filterable map + stop
  list). Optional `country="DE"` hard-scopes it; `height="520"` sets the map
  height. On multi-country sites it shows a country filter automatically.
* `[tur_takvimi_signup]` — inline signup form (name, email, phone, postcode)
  with an explicit WhatsApp opt-in. Subscribers appear under Tur Takvimi →
  Subscribers; with WhatsApp sending configured (Meta Cloud API or Twilio —
  Tur Takvimi → WhatsApp), opted-in subscribers get a template reminder
  7 days and 2 days before a tour reaches their postcode.

Shortcodes that print their own heading (`[tur_takvimi_calendar]`,
`[tur_takvimi_city_stops]`, `[tur_takvimi_city]`, `[tur_takvimi_whatsapp]`
and `[tur_takvimi_signup]`) accept a `heading` attribute: omit it for the
default, set `heading="no"` (also `false`/`none`/`0`) to hide it when your page
already has a title, or pass any text to override it, e.g.
`[tur_takvimi_calendar heading="Yaklaşan teslimat günleri"]`.

Shortcodes render full-width (100%) of their container; wrap them in a column
or set a max-width on the container to constrain them.

All work in Breakdance, Gutenberg, the classic editor and any theme.

The calendar also exposes an iCalendar feed at `/?tt_ics=1` (optionally
`&country=DE`, `&location=123`, `&route=45`, `&date=2026-06-19`,
`&address=0`, `&download=1`) for subscribing from Google/Apple/Outlook. A
location-scoped feed expands to per-address, time-of-day events (each address
due on a date becomes its own event at its delivery hour); adding `&address=`
(its row index) narrows the feed to a single address, which is what the
"add to calendar" control on each city-page address row links to. Broader
feeds stay as all-day per-city events. Feeds are cached for an hour.

== Changelog ==

= 0.1.0 =
* Initial scaffold: post types, settings, recurrence engine, postcode search,
  calendar + search shortcodes, CSV importer, bundled NL postcode dataset.
