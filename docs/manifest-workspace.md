# Compact manifest workspace

Designed with AGY Gemini 4 Argon (High), then adapted to the existing WordPress forms and carrier controls. The design role supplied layout, hierarchy and CSS; the primary implementation retains the original `finish_day`, recovery, void, restore and PDF handlers and nonce checks.

- Needs submission is the default: flat parcel rows with date, order, destination/service, tracking and eligibility.
- Awaiting confirmation / Needs review is always expanded beneath that queue. Pending transmissions, uncertain purchases/voids and carrier disagreements cannot be selected for submission.
- Submitted contains confirmed records, 20 per page, with existing protected PDF links. Where a batch has several PDFs, its row lists them together; order membership is aggregated at the batch level because the historical journal has no per-PDF parcel mapping. Actual saved handoff locations are shown.
- Carrier-confirmed external records cannot be resubmitted; missing local PDFs are disclosed. Orphan unconfirmed labels remain visible and cannot be selected.
- Selection is limited to the displayed page. Changing parcels or handoff resets physical readiness. Backend eligibility and live preflight remain authoritative.
- Details and recovery stay inside row disclosures. No new carrier action, cron change, automatic submission or order-status change is introduced.

Checks: PHP syntax; `php tests/manifest-visibility.php`; `php tests/manifest-verification.php`; `node tests/manifest-form.cjs`; synthetic desktop/mobile browser checks including actual HTML FormData, selection, readiness reset, review placement, history and overflow.
