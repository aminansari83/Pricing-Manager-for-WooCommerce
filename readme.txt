=== Pricing Manager for WooCommerce ===
Contributors: aminansari83
Tags: woocommerce, pricing, quote, bulk prices, persian
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.0
Requires Plugins: woocommerce
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Persian WooCommerce price validity, previewed bulk edits, private quotes and explicit supplier feeds. Test release for simple and variable products.

== Description ==

Pricing Manager for WooCommerce helps merchants keep products with valid prices available for normal purchase and route uncertain products into a separate quotation workflow.

The Persian RTL administration page is under WooCommerce > مدیریت قیمت ووکامرس. Installation alone does not hide prices or change prices. All bulk edits and file imports require a preview and confirmation. Native WooCommerce product CRUD writes keep catalog prices consistent. Stock quantities and paid order totals are not changed by the plugin.

* Normal purchase, quote and stopped-sale states for products and variations.
* Category-scoped emergency control; server-side validity checks.
* Percentage, fixed amount or fixed price with rounding, sale handling, exclusions, version conflicts and guarded undo.
* Searchable checkbox category/brand/attribute selection and named pricing groups with readable criteria and conflict-aware editing.
* Live AJAX product lookup by name or partial SKU; exact variation SKUs open that variation. Accessible name-filter suggestions preserve the explicit preview scope.
* Frozen row differences and selected counts, separate final approval, page exclusions, recent operations and resumable processing.
* Private UTF-8 CSV operation reports with original currency, capability/nonce checks and spreadsheet-safe identifiers.
* Short name/mobile quote form, optional email, multi-product quote list and a private tracking link.
* Confirmed merchant offers with an expiration, fixed quantities and prices; acceptance enters native WooCommerce checkout.
* CSV and bounded XLSX import by exact SKU, column mapping and explicit currency conversion. No stock import.
* Optional explicit HTTPS JSON supplier feed, hourly scheduling, thresholds and quarantine. Network failures preserve existing stored prices.
* Deactivation defaults to guarded restoration of plugin-owned fields; a setting can retain current prices. Later manual edits, stock, orders and quote/history data are preserved.
* Permission-separated management REST API, WordPress privacy exporter/eraser and verified merchant erasure for phone-only requests.

This is version 0.2.0, a test release. Read GUIDE-FA.txt and TEST-REPORT.txt for the verified coverage and remaining limits. Repository acceptance is subject to WordPress.org manual review. The directory slug and contributor ownership must be confirmed before submission.

== External services ==

The plugin has no mandatory external service, tracking, activation server, account, remote font or remote script. It does not send store or customer information to Andiya.

If the merchant explicitly creates a supplier source and confirms consent, manual fetch or enabled scheduling sends an HTTPS GET request to that merchant-provided URL. The request contains an Accept header, the usual WordPress HTTP request metadata and an optional Bearer credential defined by the merchant in wp-config.php as APG_SOURCE_TOKEN_<source id>. It does not include quote or customer data. No redirect is followed, private addresses are rejected, SSL verification is enabled and credentials are not stored in the plugin tables. The supplier's own terms and privacy policy apply; review those before enabling a source. The source is entirely optional.

Optional email notifications use the store's existing WordPress mail configuration. The plugin provides no SMS service. Supplier-specific, ERP, Torob, marketplace and gateway integrations require separate development and validation.

== Installation ==

1. Install and activate WooCommerce (10.0 or later) with PHP 8.0 or later.
2. Upload and activate pricing-manager-for-woocommerce-0.2.0.zip through Plugins > Add New.
3. Open WooCommerce > مدیریت قیمت ووکامرس. Review settings, currency and deactivation behavior.
4. Test a small category using the preview before enabling emergency mode or automatic feeds.
5. Create a page containing [apg_quote_list] if a separate multi-product quote list is desired.

For multisite, activate individually on each store. Network activation is intentionally rejected. A normal WordPress cron or a working server cron must run the scheduled jobs. Expiration is checked on the server even if cron is late.

== Frequently Asked Questions ==

= Does activating the plugin change prices? =
No. The default sale mode is normal and default validity is unlimited. A manager must explicitly change the scope/settings or confirm a pricing operation.

= What happens when I deactivate it? =
By default each owned field is restored only if it still equals the value last written by the plugin. Subsequent manual edits are preserved. The keep-prices setting retains current prices. Requests and audit history remain available after reactivation. Deactivation clears the emergency mode and scheduling. Page cache/CDN may need a refresh.

= Does an inquiry reserve inventory or receive money? =
No. Stock is checked again when a valid offer is accepted and through native checkout. An offer does not guarantee stock until checkout succeeds.

= Which currencies are supported for conversion? =
IRR (rial), IRT (toman), IRHR (thousand rial) and IRHT (thousand toman). Other currencies can be imported without conversion when the file currency equals the store currency. Currency conversion does not supply live exchange rates.

= Is every WooCommerce extension compatible? =
Version 0.2.0 manages simple and variable products. Subscriptions, bundles, composite products, multiple currencies, product customizers, marketplace and supplier-specific connectors are outside the verified scope. Classic storefront hooks and Store API safeguards are included. Browser/theme/gateway coverage is described in TEST-REPORT.txt.

= How do I import XLSX safely? =
Use the first worksheet with plain text/numeric values. Formulas, macros, external links and excessive compressed archives are rejected. ZIP and DOM PHP extensions are needed. SKU must be stored as text to preserve leading zeros. Empty prices do not become zero.

= Is there a Pro license or required upsell? =
No. All functionality in this package is available without an account or license key. Future add-ons can use the documented REST API and extension hooks; none are required here.

= How do I update the earlier release? =
Upload the new ZIP and use WordPress's Replace current with uploaded option. Keep the plugin active until the upgrader handles replacement. Manual deactivation deliberately runs the chosen restoration policy. The installed folder, text domain, data and REST identifiers retain their earlier andiya-price-guard identity to preserve existing stores. Display names use the newly approved product name.

= Are reports import templates? =
No. Reports include original and planned prices plus the actual row status; a skipped or conflicted row does not mean its planned price was published. Reports require a prices or history capability and an operation nonce; no public report file is created. Use sample-prices.csv for imports.

== Privacy ==

Quote intake stores name, Iranian mobile, optional email, notes and selected products in the store database. Private links grant access to that request; treat them as sensitive. Customer quote lists store product identifiers and names in browser localStorage; contact details are not saved there. Spam protection temporarily retains salted hashes of a network address/mobile number, without retaining the original IP address.

WordPress privacy export/erase works for requests with an email. A manager may anonymize a phone-only request after verifying the requester. Erasure rotates the private key and removes contact and offer notes. Order personal data is managed by WooCommerce's own privacy tools. Closed, accepted and expired requests are cleaned up after the configured retention period; unresolved requests remain until handled. Audit records are retained for up to one year. Deletion on uninstall is off by default and can be explicitly enabled.

== Changelog ==

= 0.2.0 =
* Adopt the approved name Pricing Manager for WooCommerce (مدیریت قیمت ووکامرس).
* Add debounced AJAX name/SKU search, precise variation lookup, keyboard-accessible filter suggestions and stale-response protection.
* Fix access and asset loading for deliberately limited APG-capability staff without granting WooCommerce management.
* Add searchable checkbox filters, clearer optional pricing groups and human-readable scope summaries.
* Add selection metrics, regular price differences, explicit final review and unfinished-operation continuation.
* Add private streaming CSV reports and retain original currencies in historical views.
* Protect group revisions, reset hidden group/source IDs and prevent missing choices from broadening a saved scope.
* Keep the useful free core, native prices, orders, stock and reversible deactivation.

= 0.1.1 =
* Fix real-price validation for fixed reductions and rounding.
* Keep valid rows previewable when another product has no base price or an invalid computed result.
* Allow fixed-price initialization, protect financial value types and make busy-product undo resumable.
* Guard internal restoration from source manual-edit detection.
* Validate signed offers before native cart session purchasability, preserving quote-only items across requests without exposing catalog prices.

= 0.1.0 =
* Initial Persian test release with guarded pricing, quotations, explicit supplier sources and reversible deactivation.
