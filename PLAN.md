# Tur Takvimi — Tour Calendar & Pre-Order Plugin (Plan)

A resellable, white-label WordPress plugin for mobile/route-based delivery
businesses (food trucks, mobile vendors, scheduled regional delivery).
It turns a static weekly tour calendar into a full local-SEO + pre-order
system. First customer / demo dataset: **Simit Express** (Turkish food,
Germany). The product itself is brand-neutral.

## Decisions locked in
- **Name / slug:** `tur-takvimi` (brand-neutral, for resale).
- **Architecture:** two layers — a dependency-free **Core**, plus an optional
  **Commerce add-on** that requires WooCommerce.
- **SEO pages:** city-level (one page per city).
- **Default language:** Turkish (fully translatable).
- **Payments:** gateway-agnostic via WooCommerce (provider chosen per customer).
- **White-label:** no brand, slug, color, discount %, currency, or working-day
  value is hardcoded — all live in settings.

## Two-layer model

### Layer 1 — Core (no dependencies, resellable base)
Works completely standalone. For a customer who only wants a tour calendar
and location SEO, this is the whole product.

- Custom post types: `tt_location` (city = SEO page), `tt_route` (route package).
- Taxonomy: `tt_region`.
- Tables: `wp_tt_schedule` (materialized tour dates), `wp_tt_postcodes`
  (bundled German PLZ → lat/lng centroids for nearest-stop search).
- Schedule / recurrence engine (every 4 / 5 / 6 weeks → concrete dates).
- Weekly **tour calendar** block (richer than the original mockup).
- **Postcode search** block: PLZ → nearest stop + next visit date.
- City **SEO pages**: schema.org (LocalBusiness / Event / Place), Turkish meta,
  XML sitemap, configurable slug base (e.g. `/teslimat/`).
- **White-label branding** settings: name, logo, colors, header text, working
  days, vehicle count, slug base, currency.
- **CSV/XLSX importer** with column-mapping UI (not hardwired to one sheet).

### Layer 2 — Commerce add-on (requires WooCommerce)
Activated only when a customer sells products / takes pre-payment.
**Products are a commerce-layer concept** — no products means this layer (and
WooCommerce) is simply not installed.

- Products = native WooCommerce products, linked to routes/locations.
- "Bu turda gelen ürünler" (products arriving on this tour) on calendar +
  location pages.
- Cart + checkout via WooCommerce (Stripe / PayPal / Klarna / SEPA — per
  customer, no code change).
- **10% upfront discount** auto-applied to prepaid orders.
- Order locked to a specific **delivery date + stop**.
- Custom order statuses: Prepaid → Out for delivery → Delivered.
- **Driver manifest**: per-date run sheet of stops + prepaid orders.

## Build phases
- **P0 — Scaffold & data:** plugin skeleton, CPTs/taxonomy/tables, i18n,
  branding settings, CSV/XLSX importer (seed from the ESMA/Simit Excel).
- **P1 — Front page (Core):** recurrence engine, calendar block, postcode
  search.
- **P2 — Location SEO pages (Core):** single-city template, schema.org,
  sitemap, meta.
- **P3 — Commerce: products:** Woo detection, product↔route linking,
  "products on this tour" display.
- **P4 — Commerce: pre-pay:** checkout, 10% discount, order-locked-to-date,
  custom statuses, reminders.
- **P5 — Admin polish:** management screens, driver manifest, settings.

## Key technical notes
- Recurrence is **materialized** into `wp_tt_schedule` so calendar/search are
  fast lookups, not per-request computation.
- Postcode search bundles an **open German PLZ dataset** — no external API.
- Core never references WooCommerce classes directly; the Commerce layer hooks
  in only when Woo is active (graceful degradation).
- All commerce/payment handled by WooCommerce — no hand-rolled payments.

## Open items (decide at the relevant phase)
- Map provider: Leaflet/OpenStreetMap (no API key, recommended) vs Google Maps.
- Prepaid cutoff: hard order-deadline before each visit?

## Demo dataset
Simit Express = first configured tenant: 510 German addresses, ~16 regions,
50 route packages, 9-week rotating calendar (Fri–Sun, 2 vehicles), imported
from the working Excel.
