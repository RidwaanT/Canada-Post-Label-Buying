# Compact manifest workspace

Designed with AGY Gemini 4 Argon (High), then adapted to the existing WordPress forms and carrier controls. The design role supplied layout, hierarchy and CSS; the primary implementation retains the original `finish_day`, recovery, void, restore and PDF handlers and nonce checks.

- Needs submission is the default: flat parcel rows with date, order, destination/service, tracking and eligibility.
- Awaiting confirmation / Needs review is always expanded beneath that queue. Pending transmissions, uncertain purchases/voids and carrier disagreements cannot be selected for submission.
- Submitted contains confirmed records, 20 per page, with existing protected PDF links. Where a batch has several PDFs, its row lists them together; order membership is aggregated at the batch level because the historical journal has no per-PDF parcel mapping. Actual saved handoff locations are shown.
- Carrier-confirmed external records cannot be resubmitted; missing local PDFs are disclosed. Orphan unconfirmed labels remain visible and cannot be selected.
- Selection is limited to the displayed page. Changing parcels or handoff resets physical readiness. Backend eligibility and live preflight remain authoritative.
- Details and recovery stay inside row disclosures. No new carrier action, cron change, automatic submission or order-status change is introduced.

Checks: PHP syntax; `php tests/manifest-visibility.php`; `php tests/manifest-verification.php`; `node tests/manifest-form.cjs`; synthetic desktop/mobile browser checks including actual HTML FormData, selection, readiness reset, review placement, history and overflow.
# Auto-billed reconciliation

The submission queue contains only selectable parcels. Held, shipped, cancelled,
or uncertain records remain in the lower review table on the same page.

Reviewed Canada Post auto-billing notices can be recorded through manager-authenticated
`POST /wc/v3/wlp-manifests/auto-billed` with `customer`, `tracking`, `order` (integer),
`manifest`, `notice_date` (YYYY-MM-DD), and the source Gmail `message_id`.
This is an operator-attested evidence import, not an email authenticity checker.
Only a unique production journal identity can match. Conflicting imports return 409;
identical retries are idempotent. Evidence is stored in non-autoloaded per-identity
WordPress options with the recording user and timestamp, separate from the journal.

Matched parcels appear in history grouped by carrier auto-billed manifest. They are
excluded from selection, direct transmission, reminders and repeated verification.
Unmatched parcels in the same batch stay under review. No carrier request is sent,
no delivery status is changed, and no loss amount or accounting write-off is inferred.
Source notice attachments and fee invoices must establish any penalty-only amount.

