# Automatic manifest verification

WooCommerce Action Scheduler runs `wlp_verify_manifests` once per minute. Each worker makes at most ten carrier GET requests, spaced one second apart, and stops starting requests after 40 seconds. An unresolved parcel is eligible every 15 minutes; failures back off to four hours. Confirmed transmitted parcels stop polling. Site traffic or a server cron runner must service Action Scheduler; the page exposes the last run and failures.

The worker checks journal labels regardless of shipped/delivered order status. It also discovers primary and split labels on orders modified in the last 21 days, 50 orders per discovery page. This is not an unlimited historical order audit. Evidence is stored separately in `wlp_manifest_verification_v1`; it does not rewrite the submission journal, send notifications, submit manifests, or change delivery status. A carrier PO alone never proves transmission. Matching shipment ID, tracking PIN and transmitted status are required. Errors preserve the last successful evidence and show an explicit retry warning. Old shipments no longer available at the carrier remain unverified, not assumed missing.

The Shipping manifests screen shows carrier status, PO, successful-check time, missing journal entries and disagreements. Existing journal warnings remain available for recovery of local submission/PDF history. Before explicit transmission, selected shipment identities are checked live to prevent resubmitting externally transmitted parcels.

Manager-only GET endpoints:
- `/wp-json/wc/v3/wlp-manifests/status`: minimal cached evidence.
- `/wp-json/wc/v3/wlp-manifests/orders/{id}?shipment_id=...&tracking_number=...`: live confirmation of the current primary label, for the Medusa shipping guard. A changed label or failed check blocks shipping.

Validation: `php tests/manifest-verification.php`, `php tests/manifest-visibility.php`, `node tests/manifest-form.cjs`.
