# Woo Logistics Plugin

Standalone WooCommerce plugin for a logistics desk focused on Canada Post label creation and printing.

## Current Scope

- WooCommerce admin logistics page.
- Canada Post settings page.
- Fetch Canada Post rates by package preset.
- Configurable default Canada Post service and package preset for quick buy.
- Optional Canada Post signature-required labels.
- Optional shipment weight calculation from WooCommerce product weights, with an optional base package weight.
- Create Canada Post non-contract shipments.
- Store label, tracking, shipment, service, and print metadata on Woo orders.
- Stream the stored Canada Post label artifact for printing.

## Local Setup

1. Place or symlink this folder into `wp-content/plugins/woo-logistics-plugin`.
2. Activate **Woo Logistics Plugin** in WordPress.
3. Open WooCommerce > Logistics Settings and add Canada Post credentials and origin details.
4. Open WooCommerce > Logistics to create and print labels.

## Canada Post Metadata

The plugin stores label state in Woo order meta using `_wlp_*` keys. The important fields are:

- `_wlp_shipment_id`
- `_wlp_tracking_number`
- `_wlp_tracking_url`
- `_wlp_label_artifact_url`
- `_wlp_service_code`
- `_wlp_service_name`
- `_wlp_label_created_at`
- `_wlp_packaging_preset_id`
- `_wlp_packaging_preset_name`

Packaging preset metadata is written from the package preset selected for the label purchase. If no preset id is provided by a future purchase path, `_wlp_packaging_preset_id` is stored blank rather than inferred from service or order details.

When **Mirror label metadata for external logistics systems** is enabled in Logistics Settings, the plugin also writes the same values to hidden `_medusa_logistics_*` keys for external dashboards. The mirrored keys use the same suffixes, for example `_medusa_logistics_tracking_number` and `_medusa_logistics_label_artifact_url`.

This plugin version does not support voiding or cancelling Canada Post labels. Medusa reversal handling is manual until a Woo-side void workflow writes explicit reversal metadata.

## Development

Composer is needed for development tooling and runtime PDF normalization dependencies:

```powershell
composer install
composer lint
composer test:unit
```

Build an installable zip that includes runtime dependencies:

```powershell
.\scripts\build-release-zip.ps1
```

## Canada Post Sandbox Probe

Use the standalone probe before testing inside WordPress:

```powershell
$env:CP_DEVELOPMENT_USER="..."
$env:CP_DEVELOPMENT_PASSWORD="..."
$env:CP_CUSTOMER_NUMBER="..."
$env:CP_ORIGIN_POSTAL_CODE="M5V3L9"
$env:CP_DESTINATION_POSTAL_CODE="K1A0B1"
php scripts/canada-post-sandbox-probe.php
```

The script prints HTTP status and returned service/rate summaries only. It does not print credentials.

## Hosted Demo Runbook

Railway + Cloudflare setup notes for the hosted WordPress/WooCommerce demo live in:

- [docs/railway-wordpress-demo.md](docs/railway-wordpress-demo.md)
