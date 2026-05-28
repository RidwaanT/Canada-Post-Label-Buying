=== Woo Logistics Plugin ===
Contributors: northendtech
Tags: woocommerce, shipping, logistics, canada post, labels
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 0.1.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a WooCommerce logistics desk for Canada Post label creation, printing, and tracking metadata.

== Description ==

Woo Logistics Plugin provides a WooCommerce admin logistics desk for Canadian domestic Canada Post shipments. It supports rate lookup, label purchase, configurable quick-buy service and package defaults, optional Canada Post signature-required labels, duplicate-label protection, reprinting, editable package presets, optional product-weight calculation with base package weight, HPOS compatibility, optional external logistics metadata mirroring, and plugin-owned order metadata.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/woo-logistics-plugin`.
2. Activate the plugin through the WordPress Plugins screen.
3. Open WooCommerce > Logistics Settings.
4. Enter Canada Post credentials, origin details, eligible statuses, and package presets.
5. Open WooCommerce > Logistics to buy and print labels.

== Frequently Asked Questions ==

= Does v1 support international shipments? =

No. V1 supports Canadian domestic shipments only.

= Does v1 void Canada Post labels? =

No. V1 supports buying and reprinting labels. Void/replacement workflows beyond explicit replacement purchases are planned for a later release.

== Changelog ==

= 0.1.7 =
* Keep newly labeled orders in To be shipped until they have shipped metadata.
* Add immediate print-button rendering after label purchase.
* Add a configurable default package preset for quick buy.

= 0.1.6 =
* Disabled test order creation in production environments.

= 0.1.5 =
* Moved Logistics Settings into the main Logistics page so settings can be opened without a separate submenu.

= 0.1.4 =
* Added logistics tabs for to-be-shipped, in-transit, and delivered orders.
* Added clickable Canada Post tracking links and in-transit delivery estimates.
* Fixed the hidden settings page registration warning in wp-admin.

= 0.1.3 =
* Harden plugin bootstrap for replacement installs on stores with stale duplicate plugin rows.

= 0.1.2 =
* Add optional label metadata mirroring for external logistics systems.
* Respect `CP_USE_SANDBOX` when resolving Canada Post env credentials and endpoint.
* Align the release zip default version with the plugin version.

= 0.1.1 =
* Force refreshed admin assets for the updated label drawer transit-time display.

= 0.1.0 =
* Initial V1 development release.
