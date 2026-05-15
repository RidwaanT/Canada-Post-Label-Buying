=== Woo Logistics Plugin ===
Contributors: northendtech
Tags: woocommerce, shipping, logistics, canada post, labels
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a WooCommerce logistics desk for Canada Post label creation, printing, and tracking metadata.

== Description ==

Woo Logistics Plugin provides a WooCommerce admin logistics desk for Canadian domestic Canada Post shipments. It supports rate lookup, label purchase, configurable quick-buy service defaults, duplicate-label protection, reprinting, editable package presets, optional product-weight calculation with base package weight, HPOS compatibility, and plugin-owned order metadata.

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

= 0.1.0 =
* Initial V1 development release.
