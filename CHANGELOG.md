# Changelog

All notable changes to the CX DHL API plugin are recorded here, newest first.

## 2026-08-20 (2) — Dedicated logging + a generated-records audit trail

Two additions, kept separate on purpose and tied together by a `request_id`:

### Per-day log files, separate from WordPress's debug.log

- New `CX_DHL_Logger` (`includes/class-cx-dhl-logger.php`) writes to
  `wp-content/uploads/cx-dhl-logs/YYYY-MM-DD.log` instead of `error_log()`.
  The directory is created and protected on first write (`.htaccess` +
  `index.php`, same convention plugins like WooCommerce use for their own
  log folders).
- Every DHL API request/response and both auth token flows now log through
  this class. The `Authorization` header is always redacted before writing.
  Full request/response bodies are deliberately **not** dumped to the log
  file (unlike the old unconditional `error_log()` calls) - only method,
  URL, and status - to avoid duplicating customer PII into yet another
  place on disk. Full detail on what was generated lives in the records
  table instead (see below).
- New Settings > DHL API > Logging section: "Enable Request Logging"
  (default on) and "Log Retention (days)" (default 30). A daily WP-Cron job
  (`cx_dhl_log_cleanup`) deletes log files older than the retention window.
  Record rows are never auto-deleted by this.

### `wp_cx_dhl_records` table — audit trail of everything generated

- New `CX_DHL_Records` (`includes/class-cx-dhl-records.php`) creates/upgrades
  a dedicated DB table and inserts one row per **generating** action: a
  V01PAK label (`order`), a return label (`order_return`), an Internetmarke
  PDF (`internetmarke`), or a dashboard form submission (`dashboard_order`).
  Read-only lookups (tracking, order-by-tracking-number, manifests,
  page-formats) are intentionally not recorded here - they're still visible
  in the log files, just not as an audit row, by explicit choice.
- Each row stores: timestamp, type, success/failure + HTTP code, who
  triggered it (WP user for REST calls, IP for the PIN-gated dashboard),
  reference/tracking number, recipient name/city, a short outcome summary,
  and the `request_id` that ties it back to that day's log file.
- New Settings > DHL API Records admin screen (`CX_DHL_Records_List_Table`,
  a standard `WP_List_Table`) lists, filters (by type/status), and searches
  these rows, with a "View log" link per row that opens
  `CX_DHL_Log_Viewer` - a hidden admin page showing just that request's log
  lines for that day.
- Table creation runs on plugin activation, and is also checked on every
  `admin_init` (`CX_DHL_Records::maybe_create_table`, versioned via the
  `cx_dhl_db_version` option) - the plugin was already active before this
  change, so activation hooks alone won't fire for an in-place update.

### No external contract change

REST routes, request/response payload shapes, auth flows, and the
dashboard's visible behavior are unaffected. All record/log writes are
wrapped so a failure there degrades to "no record/no log line," never to a
broken response for the caller.

## 2026-08-20 — Split monolith into focused classes, fix bugs, keep external contract

The plugin was a single 1020-line `CX_DHL_API` class (`cx-dhl-api-legacy.php`) mixing
admin settings, the frontend dashboard, REST routes, and two DHL OAuth flows. It's now
split into `cx-dhl-api.php` (bootstrap) + `includes/class-cx-dhl-*.php`. The legacy file
is preserved at `legacy/cx-dhl-api-legacy.php` for reference/rollback (not a live plugin).

### No change (verified against the legacy file)

- REST route paths, HTTP methods, args, and `permission_callback` logic
  (`wc/v3` namespace, all `/dhl/*` routes) — untouched.
- Both DHL OAuth flows: grant types, credential option keys, token endpoints,
  transient keys and TTLs (`cx_dhl_api_token` / 1700s, `cx_dhl_api_im_token` / 86000s).
- DHL request/response body shapes for every endpoint (shipment/consignee/shipper
  fields, dimensions, Internetmarke cart/positions, tracking, manifests).
- `[dhl_dashboard]` shortcode markup, form fields, PIN-in-URL gating behavior,
  and the Make.com webhook call on successful label creation.
- `CX_DHL_API` class name and every original public method signature — kept as
  delegating wrappers in `includes/class-cx-dhl-plugin.php` in case anything
  outside this plugin (e.g. theme code) calls them directly.

### Fixed

- **Sensitive data in logs**: `error_log()` was unconditionally logging the full
  request/response on every DHL API call, including the `Authorization: Bearer`
  token and customer PII (Internetmarke positions). Logging is now gated behind
  `WP_DEBUG` and the Authorization header is redacted before logging.
- **Dead validation check**: `if ($base_url && empty($base_url))` in three places
  (`make_request`, `make_auth_request`, `im_make_auth_request`) could never be
  true — a missing/invalid `mode` option silently produced a broken URL instead
  of failing. Replaced with a real `empty($base_url)` check, and `mode` now
  defaults to `sandbox` instead of warning on an undefined array key.
- **`cx_default()` undefined function**: called at 12 sites in the dashboard
  form but never defined in this plugin (or apparently anywhere else), meaning
  the shortcode fatal-errored whenever rendered. Now defined (guarded with
  `function_exists`, so any pre-existing definition elsewhere still wins) as a
  sticky-form helper that reprints the visitor's last POSTed value for that field.
- **PIN comparison**: switched from `!==` to `hash_equals()` (timing-safe),
  same pass/fail outcomes as before.
- **Settings page invalidated the cached token on every page view** (not just on
  save) via `delete_transient()` in `settings_page_html()`. Now only fires when
  the settings option is actually saved (`update_option_cx_dhl_api_settings`).
- Dropped a dead `$telefon` variable in the form handler that was parsed from
  `$_POST['cx_telefon']` (a field that doesn't exist in the form — the real
  phone field is `cx_phone`) and never used.
- Deduplicated `dhl_create_order` / `dhl_create_order_return` (near-identical
  shipper/consignee swap) and the two token/auth-request method pairs into
  shared private helpers. Public method behavior is unchanged.
- Hardcoded Make.com webhook URL is now overridable via the
  `cx_dhl_webhook_url` filter (default value unchanged).

### Changed (approved deviation from "no external change")

- REST responses for a **failed** DHL request now return a real HTTP error
  status (DHL's own code when available, otherwise 502) instead of always
  200 OK. The JSON body shape (`success`/`code`/`body` keys) is unchanged —
  only the transport-level status code is now meaningful. A consumer that
  reads `response.success` from the body sees no change; a consumer that
  checks the HTTP status will now see accurate codes.

### Not changed — flagged for a future decision

- REST routes are registered under WooCommerce's own `wc/v3` namespace rather
  than a namespace of this plugin's own (e.g. `cx-dhl/v1`).
- The dashboard PIN travels in the URL query string and gates access to
  creating real shipments — no rate limiting, and no rotation.
- Credentials (`client_secret`, `password`, `pcf_password`) are stored in
  plaintext in `wp_options`.

### Verification performed

- `php -l` on every new/moved file — no syntax errors.
- Manual line-by-line diff of each REST callback and both auth flows against
  the legacy file to confirm request bodies, headers, endpoints, and
  transient keys/TTLs are unchanged.
- **Not yet done**: live smoke test (no WordPress install in this environment).
  Before deploying: save Settings once, load `[dhl_dashboard]` with a valid PIN,
  and exercise one request per REST route on staging.
