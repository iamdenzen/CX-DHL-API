# CX DHL API

Integrates the DHL Parcel DE API (V01PAK shipping labels, returns, Internetmarke
franking, tracking, and manifests) with WordPress/WooCommerce. Provides REST
routes for programmatic use plus a PIN-gated frontend dashboard shortcode for
creating shipments without WP admin access.

- **Version:** 1.2
- **Author:** Creatricx
- **Requires:** WordPress with the REST API enabled. Routes are registered
  under the `wc/v3` namespace, so WooCommerce should be active.

## Features

- Create DHL V01PAK shipping labels (outbound and return) via REST.
- Create Internetmarke (Deutsche Post) franked PDFs.
- Look up orders by tracking number, fetch manifests by date, and track
  shipments.
- `[dhl_dashboard]` shortcode: a simple PIN-gated frontend form for creating
  a shipment label without logging into wp-admin.
- Per-request logging to daily log files, separate from `debug.log`, with
  automatic retention cleanup.
- An audit trail (`wp_cx_dhl_records` table) of every label/PDF generated,
  browsable from a dedicated admin screen, with a per-request "View log"
  link.

## Installation

1. Copy this plugin's directory into `wp-content/plugins/`.
2. Activate it from **Plugins** in wp-admin. Activation creates the
   `wp_cx_dhl_records` table and schedules a daily log-cleanup cron event.
3. Go to **Settings → DHL API** and fill in your credentials (see below).

## Configuration

All settings live under **Settings → DHL API**.

### DHL API Credentials

| Field | Purpose |
|---|---|
| Client ID | DHL API client ID (used for both OAuth flows and as the `DHL-API-Key` header on tracking requests) |
| Client Secret | DHL API client secret |
| Username / Password | DHL account credentials, used for the ROPC (`grant_type=password`) token flow that powers shipping/tracking/manifest endpoints |
| PCF Username / PCF Password | Internetmarke (Post & Paket) portal credentials, used for the `client_credentials` token flow that powers the Internetmarke endpoints |
| Dashboard PIN | The PIN required in the `?pin=` query string to access the `[dhl_dashboard]` shortcode |
| API Mode | `Sandbox` or `Live` — selects the DHL API base URL |

### Shipper Details

The default sender address (name, street, postal code, city, email, phone)
used as the `shipper` on outbound labels and as `consignee` on returns.

### Logging

| Field | Purpose |
|---|---|
| Enable Request Logging | On by default. Logs method/URL/status for every DHL API call (not full request/response bodies, to avoid storing customer PII in the log file) |
| Log Retention (days) | Default 30. A daily WP-Cron job (`cx_dhl_log_cleanup`) deletes log files older than this |

Logs are written to `wp-content/uploads/cx-dhl-logs/YYYY-MM-DD.log`, with
the directory protected by an `.htaccess` + `index.php` on first write.
`Authorization` headers are always redacted before writing.

## REST API

All routes are registered under `wc/v3` and require the
`manage_woocommerce` capability.

| Method | Route | Description |
|---|---|---|
| POST | `/wp-json/wc/v3/dhl/v01pak` | Create a shipping label (shipper = configured shipper, consignee = request payload) |
| POST | `/wp-json/wc/v3/dhl/v01pak-return` | Create a return label (shipper = request payload, consignee = configured shipper) |
| GET | `/wp-json/wc/v3/dhl/v01pak?trackingNumber=...` | Look up an order/shipment by tracking number |
| GET | `/wp-json/wc/v3/dhl/tracking?trackingNumber=...` | Track a shipment |
| GET | `/wp-json/wc/v3/dhl/manifests?date=YYYY-MM-DD` | Fetch manifests for a date |
| POST | `/wp-json/wc/v3/dhl/internetmarke` | Create an Internetmarke shopping cart PDF |
| GET | `/wp-json/wc/v3/dhl/page-formats` | List available Internetmarke page formats |

**Order/label body (`v01pak` and `v01pak-return`):**

```json
{
  "billingNumber": "...",
  "refNo": "...",
  "name": "...",
  "name2": "...",
  "name3": "...",
  "addressStreet": "...",
  "postalCode": "...",
  "city": "...",
  "email": "...",
  "phone": "...",
  "dimensions": { "height": 100, "length": 100, "width": 100 },
  "weight": 500
}
```

Responses use `{ "success": bool, "code": int, "body": {...} }`. The HTTP
status code reflects the actual DHL result (DHL's own status code when
available, otherwise 502 on failure) — check `success` in the body for the
application-level result.

## Dashboard shortcode

`[dhl_dashboard]` renders a frontend form for creating a V01PAK label. It is
gated by the PIN configured in Settings: the page must be visited as
`?pin=<your-pin>` or the shortcode renders nothing. On successful
submission it displays the generated label PDF inline and POSTs the DHL
response to a Make.com webhook (overridable via the `cx_dhl_webhook_url`
filter).

## Records & logs (Settings → DHL API Records)

Every label/PDF-generating action (`order`, `order_return`,
`internetmarke`, `dashboard_order`) is recorded as a row with timestamp,
status/HTTP code, who triggered it (WP user for REST calls, IP for the
dashboard), reference/tracking number, recipient, and an outcome summary.
Read-only lookups (tracking, order lookup, manifests, page formats) are not
recorded here, only in the log files. Each record links to a "View log"
page showing that request's log lines for that day.

## Known limitations

- REST routes live under WooCommerce's `wc/v3` namespace rather than a
  namespace of this plugin's own.
- The dashboard PIN travels in the URL query string, with no rate limiting
  or rotation.
- Credentials (`client_secret`, password, PCF password) are stored in
  plaintext in `wp_options`.

## Project layout

```
cx-dhl-api.php                          Bootstrap: loads includes, activation/deactivation hooks
includes/class-cx-dhl-plugin.php        Orchestrator/facade (CX_DHL_API), wires everything together
includes/class-cx-dhl-client.php        DHL/Internetmarke HTTP client, OAuth flows, token caching
includes/class-cx-dhl-rest-controller.php  REST route registration and callbacks
includes/class-cx-dhl-settings.php      Settings → DHL API admin page
includes/class-cx-dhl-dashboard.php     [dhl_dashboard] shortcode + form handler
includes/class-cx-dhl-logger.php        Daily log files + retention cleanup
includes/class-cx-dhl-records.php       wp_cx_dhl_records table (create/insert/query)
includes/class-cx-dhl-records-list-table.php  WP_List_Table for the records admin screen
includes/class-cx-dhl-records-admin.php Records admin screen
includes/class-cx-dhl-log-viewer.php    Per-request log viewer admin page
legacy/cx-dhl-api-legacy.php            Pre-refactor monolithic version, kept for reference/rollback
```

See [CHANGELOG.md](CHANGELOG.md) for the history of the split from the
legacy monolith and subsequent logging/records additions.
