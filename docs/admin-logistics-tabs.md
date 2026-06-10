# Admin Logistics Tabs

The WooCommerce admin page at `WooCommerce > Logistics` groups orders into three operational tabs:

- `To be shipped`: paid/eligible orders that have not been marked shipped, including orders where a label was purchased but no shipped timestamp exists yet.
- `In transit`: orders with a shipped timestamp, or a completed Woo status with plugin label metadata.
- `Delivered`: orders with delivered logistics metadata, plus completed Woo orders that do not have plugin-created labels.

The admin desk treats label purchase and shipment movement as separate steps, so buying a label does not by itself move the card to `In transit`.

## Tracking Links

Cards show the stored tracking number as a Canada Post link. If the order has `_wlp_tracking_url` or `_medusa_logistics_tracking_url`, that URL is used. If only a tracking number exists, the plugin builds a public Canada Post tracking URL with:

`https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor={tracking_number}`

## Estimated Delivery

The `In transit` tab replaces the package row with `Estimated delivery`.

The plugin reads estimates from:

- `_wlp_expected_delivery_date`
- `_medusa_logistics_expected_delivery_date`

If neither key exists and the order has a tracking number, the plugin tries to refresh the value from Canada Post tracking:

1. `GET /vis/track/pin/{pin}/summary`
2. `GET /vis/track/pin/{pin}/detail`

The detail endpoint can return `changed-expected-date`; when present, that value wins over the summary estimate. The refreshed estimate is written back to `_wlp_expected_delivery_date` and `_wlp_last_polled_at`. If external metadata mirroring is enabled, the matching `_medusa_logistics_*` keys are written too.

Canada Post sandbox tracking often does not include realistic live tracking events or expected delivery dates. In that case, `Not available` is expected and does not indicate a plugin failure.

## Customer Notifications

When `Send Canada Post customer notifications` is enabled, label creation includes Canada Post notification XML for:

- `on-shipment`
- `on-exception`
- `on-delivery`

The setting defaults to enabled. Notifications require the order to have a billing email.

## Tests

Run the lightweight plugin tests with:

```powershell
composer run test:unit
```

Run production-file linting with:

```powershell
composer run lint
```
