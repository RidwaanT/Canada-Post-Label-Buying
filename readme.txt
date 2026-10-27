=== Woo Logistics Plugin ===
Contributors: northendtech
Tags: woocommerce, shipping, logistics, canada post, labels
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 0.1.237
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a WooCommerce logistics desk for Canada Post label creation, printing, and tracking metadata.

== Description ==

Woo Logistics Plugin provides a WooCommerce admin logistics desk for Canadian domestic Canada Post shipments. It supports rate lookup, label purchase, configurable quick-buy service and package defaults, optional Canada Post signature-required and Card for Pickup labels, duplicate-label protection, reprinting, editable package presets, optional product-weight calculation with base package weight, HPOS compatibility, optional external logistics metadata mirroring, and plugin-owned order metadata.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/woo-logistics-plugin`.
2. Activate the plugin through the WordPress Plugins screen.
3. Open WooCommerce > Logistics Settings.
4. Enter Canada Post credentials, customer and commercial agreement numbers, origin and pickup/drop-off details, eligible statuses, and package presets.
5. Open WooCommerce > Logistics to buy and print labels.
6. Before handoff, open WooCommerce > Shipping manifests, select ready parcels, transmit electronically and wait for confirmation. Mark parcels shipped from Logistics afterward.

== Frequently Asked Questions ==

= Does v1 support international shipments? =

No. V1 supports Canadian domestic shipments only.

= Does v1 void Canada Post labels? =

Yes. Shipping manifests can void confirmed untransmitted labels created by this plugin. Interrupted operations stay blocked until reconciled. Returns use the separate Authorized Returns API and do not enter outbound manifests. Return PDFs are stored privately for later reprinting; creating a return does not send a customer email. Interrupted requests remain blocked for carrier reconciliation.

== Changelog ==

= 0.1.237 =
* Simplify manifests into a flat unsubmitted queue, visible review table and paginated confirmed history.
* Keep submission safeguards and existing recovery/PDF actions.

= 0.1.233 =
* Automatically verify unresolved commercial shipments in paced background batches.
* Display carrier evidence and missing local records, and guard duplicate transmissions.

= 0.1.232 =
* Request Canada Post labels as PDF and report failed downloads without crashing batch printing.

= 0.1.231 =
* Default Starter-tier wholesale shipments to Xpresspost while preserving saved shipping commitments.

= 0.1.230 =
* Request priority shipping without quoting a fixed supplier shipping charge.
* Round combined supplier vial requests up to whole ten-vial kits, preserving customer allocations and exact bottle counts.
* Retain rounded batch request quantities and unallocated extras in supplier history; preserve earlier sent records.

= 0.1.229 =
* Keep Supplier order placed visible while supplier stock is outstanding, including orders with an existing single or split shipment plan.

= 0.1.228 =
* Guide supplier batches through Assign, Send order, Receive + inspect, and Pack + label.
* Record Supplier order placed for every included order with exact item snapshots; exclude placed quantities from new requests.
* Validate the whole batch before saving; guard concurrent/stale updates and repeated clicks. Keep customer shipping, payment, and email separate.
* Simplify wholesale card actions and fix clipped mobile button labels.

= 0.1.227 =
* Read complimentary vial and bottle quantities without omitting extras; name invalid orders in batch errors.

= 0.1.226 =
* Combine selected order demand by product, with one supplier assignment across every matching order.

= 0.1.225 =
* Add supplier checkboxes and bulk assignment across selected orders, with one save action and combined supplier messages.
* Reject stale source allocations and incomplete combined drafts; preserve sent/received history.

= 0.1.224 =
* Add a one-package shipment shortcut with the existing label, packing and customer email preview flow.

= 0.1.223 =
* Itemize GST and provincial tax separately in wholesale quick view, copied quotes and prepared customer emails.

= 0.1.222 =
* Include configured provincial sales tax in wholesale quote summaries and invoice totals.

= 0.1.221 =
* Keep the reminder address selector visible with no eligible parcels. Remember the last selected address in this browser; default to the 260 King St. E Hamilton pickup address. Submission still requires eligible ready parcels.

= 0.1.220 =
* Submit selected ready parcels from the manifest reminder with a pickup/drop-off address selector and background Canada Post confirmation. Preserve uncertain-request protection and existing manifest review. No automatic pickup booking or order-status changes.

= 0.1.219 =
* Keep unrecorded Hamilton stock visibly unknown and preserve it during supplier-only updates.

= 0.1.218 =
* Add Supplier Stock to the WordPress sidebar for private supplier availability, unit-qualified costs and manual Hamilton counts. Preserve unpublished and blocked wholesale listings.

= 0.1.217 =
* End new XP supplier order-request drafts with "+ $59 priority shipping" while retaining exact older text for requests recorded as sent.

= 0.1.216 =
* Remove the introductory sentence from new supplier order-request drafts while retaining exact text for requests already recorded as sent.

= 0.1.215 =
* Use the owner's updated extra-$50 wording in XP supplier order-request drafts without changing stock checks or sending a message.

= 0.1.214 =
* Fix review photo uploads from the WordPress post editor by moving the upload form outside the editor form and displaying a saved photo count and success notice.

= 0.1.213 =
* Let staff add several consent-confirmed wholesale review photos in one submission and return to the same review editor afterward.

= 0.1.212 =
* Add a staff-only, consent-confirmed manual uploader for additional approved wholesale review photos. It never imports email attachments, emails a buyer, or changes a review reward.

= 0.1.211 =
* Display approved wholesale review photos as a gallery and explicitly remove image metadata before saving uploads.

= 0.1.210 =
* Limit wholesale review rewards and invitations to one credit per customer, while preserving existing issued credits.

= 0.1.209 =
* Let a verified wholesale buyer add a missing photo to an existing review later, with fresh publication consent and no credit change.

= 0.1.208 =
* Makes the wholesale review invitation explicitly offer text-only and photo-review reward options, including the secure photo upload.

= 0.1.207 =
* Award C$150 only when the submitted review has a saved, resolvable image attachment; text-only or missing-image reviews receive C$50.

= 0.1.206 =
* Record one manually sent batch supplier draft across all included orders, with paid-order blockers explained in the batch panel.


= 0.1.205 =
* Add a selected-order Batch supplier request button that combines private JKL, XP, and Hamilton allocations without exposing pricing.


= 0.1.204 =
* Send owner-approved historic review invitations within the approved admin action when WordPress cron is unavailable.


= 0.1.203 =
* Add the owner-approved historical wholesale review invitation campaign for confirmed-delivered orders, excluding recorded opt-outs, prior invitations and reviews.

= 0.1.202 =
* Add North End Tech Inc.'s mailing address to wholesale review invitations.

= 0.1.201 =
* Require a C$1,000 before-tax wholesale order for a C$150 photo review credit and state both credit minimums in the invitation, portal, and credit email.

= 0.1.200 =
* Put Fulfillment sources directly on every wholesale Logistics card and open the JKL, XP, and Hamilton plan immediately from that button.
* Clarify that saving a source plan never sends a supplier message, including for paid orders. Keep supplier messages as manual copy-and-send drafts.
* Shorten supplier drafts for repeat ordering: remove dispatch-date questions and ask for XP's extra $50 priority shipping only in the order request.
* Remove the packing race timer, controls, and local statistics from Logistics.

= 0.1.199 =
* Add private per-item JKL, XP, and Hamilton source allocation to wholesale Logistics quick view.
* Generate price-free supplier stock checks and post-payment order requests, including XP's $50 priority request; record sent, confirmed, and received steps manually without contacting suppliers.

= 0.1.198 =
* Include the Wholesale Refund & Return Policy link in copied quotes, wholesale email templates and printable wholesale invoices. No messages are sent by this update.

= 0.1.197 =
* Give delivered wholesale orders their own Wholesale delivered tab, separate from retail deliveries. Preserve split-package classification and delivered-order controls without changing saved order state.

= 0.1.196 =
* Restore commercial return creation through Authorized Returns v2 with duplicate and uncertain-request protection, private PDF retention, and explicit customer messaging.
* Preserve the customer origin in return estimates; do not apply the outbound deposit location or signature option.

= 0.1.195 =
* Route fully delivered wholesale orders to Delivered; retain partial packages in their proper queue. Increase wholesale carrier capacity to 20 lookups hourly with an hourly parcel cooldown.
* Add separately enabled, deduplicated final-delivery confirmations. Disabled by default pending message approval; old delivery dates are excluded. Retail and review emails are unchanged.

= 0.1.194 =
* Wholesale carrier polling now reconciles confirmed physical scans and revised delivery estimates into customer account tracking, independently for each package. Labels alone remain Preparing; no shipment email or Woo status change is triggered by reconciliation.

= 0.1.193 =
* Check wholesale carrier delivery even when manual handoff was missed. Confirmed delivery counts toward shipped quantities without inventing a handoff date.
* Add a bounded Canada Post delivery refresh to split packages and honor saved shipping facts in wholesale queue classification.

= 0.1.192 =
* Add the existing full-order Invoice + guide link to each split package beside its packing list and label.

= 0.1.191 =
* Add the Medium retail box (17 x 11 x 6 cm, 0.5 kg starting weight) to split-package labels, including purchase-failure form recovery.

= 0.1.190 =
* Default the first draft package to the store date. Use the existing rate and label flow for delivery estimates instead of separate draft estimate requests.

= 0.1.189 =
* Sync carrier-confirmed wholesale delivery independently of review consent, with fair polling and package-edit locking. Review email consent is unchanged.

= 0.1.188 =
* Show the next package estimated send date when creating a split or dividing an existing draft package, without requiring a carrier estimate.
* Include the existing secure wholesale account link in quote follow-ups and label-ready customer notes. Preserve account ownership and guest email verification.

= 0.1.187 =
* Show a manifest reminder after every successful retail, bulk or split-package label purchase. Keep packing dismisses only the current reminder.
* Read saved parcel eligibility and carrier confirmation state without transmitting anything. Open manifest review separately to preserve the packing screen.

= 0.1.186 =
* Group split-package dispatch, box measurements, Canada Post rates and label purchase in one panel. Show package-specific errors and carrier recovery links.
* Invalidate rates when any parcel input changes and refresh persisted package state after failed or interrupted purchases without retrying a purchase.
* Stop background carrier estimates when opening saved packages; preserve saved shipment dates and purchase protections.

= 0.1.185 =
* Give each new wholesale review credit a one-time code. Show it in the customer portal and support an approval-gated email with instructions to enter it in the next wholesale quote Notes field. Require that code in the buyer's saved quote notes before staff can apply the credit.

= 0.1.184 =
* Show staff the next and last wholesale carrier check so delayed WordPress scheduled events are visible.

= 0.1.183 =
* Check Canada Post tracking for opted-in, shipped wholesale orders and record confirmed delivery for the review timer, including each split package. Woo order status and review invitation setting remain unchanged.

= 0.1.182 =
* Increase new wholesale text-review credits to C$50; keep photo-review credits at C$150 total.

= 0.1.181 =
* Increase new wholesale photo-review credits to C$150; retain C$10 text credits, rating independence, existing credit balances and invitation settings.

= 0.1.173 =
* Require a commercial agreement for rates and labels; remove non-contract purchase fallback.
* Save one carrier result per physical package and protect interrupted operations from duplicate purchases.
* Add parcel selection, held parcels, multiple electronic manifests per day, read-only recovery and void controls.
* Require a confirmed manifest before marking known contract labels shipped.
* Correct domestic paper-manifest and scan-based billing guidance.

= 0.1.170 =
* Add a 28 x 22 x 6 cm small-box choice for split-package labels, with a 1.5 kg starting packed weight. Keep the existing large box available.

= 0.1.169 =
* Add staff-only real wholesale order browsing and read-only customer-facing previews in My Account, without changing customer ownership or messaging permissions.

= 0.1.167 =
* Match wholesale package cards to the retail account design, preserving authorized quote projection and existing message forms.

= 0.1.166 =
* Initialize WooCommerce notices and sessions before wholesale account and review form actions to prevent admin-post fatal errors.

= 0.1.164 =
* Add read-only retail account tracking cards with catalogue blue styling and account navigation icons. Saved shipment state only; no new messages or carrier polling.

= 0.1.162 =
* Add separate wholesale experience reviews, optional photo rewards, delivery-aware opt-in invitations and guarded quote-credit application. Invitations remain disabled pending owner approval.

= 0.1.161 =
* Automatically email wholesale tracking and the account link after confirmed package or whole-order handoff. Persisted event guards prevent duplicate and uncertain retries; notification failures remain visible without undoing shipment state.

= 0.1.160 =
* Add staff-only fictional customer dashboard preview with dummy Priority tracking and disabled messaging. No sample orders are saved.

= 0.1.159 =
* Add customer-owned wholesale quotes and shipments in My Account, email-verified guest access, and quote-specific messages to wholesale support.

= 0.1.158 =
* Add per-package Canada Post tracking URLs to split update previews and copied text, with clickable links in escaped HTML emails.

= 0.1.157 =
* Expose purchased package labels, packing sheets and invoices directly on split-order cards.
* Render wholesale invoice items and tax-inclusive totals from the saved quote breakdown, matching the wholesale quote workflow without changing financial records.

= 0.1.156 =
* Fetch carrier estimates immediately on dispatch input and when opening a future-dated unlabelled package. No save or label purchase required; purchased shipment dates remain unchanged.

= 0.1.155 =
* Add direct Split package buttons to draft package rows on Logistics cards. Open item selection immediately and recheck the current package before allowing edits.

= 0.1.154 =
* Allow joined orders to split draft packages with automatic remainder allocation. Purchased labels and shipped packages remain locked.
* Deduplicate joined fulfillment cards and expose original-order links; customer previews describe separate packages accurately.

= 0.1.153 =
* Suggest matching paid wholesale orders by customer email and complete shipping address.
* Join two eligible orders into one shared package, packing list and label while preserving financial records. Explicit customer update preview includes both order numbers. No automatic email on join.

= 0.1.152 =
* Default split estimates to Priority and fetch delivery dates on dispatch/service changes, including unsaved first/later plans. No label purchase or automatic order mutation.

= 0.1.151 =
* Show the saved shipping recipient and full destination directly below the split-dialog order number, with missing-field warnings. No address or order mutations.

= 0.1.150 =
* Use the split package's selected dispatch date for Canada Post rates and automatically save the selected service's delivery estimate on label purchase. Reject stale date estimates before purchase.

= 0.1.149 =
* Show a prominent split-order ribbon on wholesale cards with each saved package's quantity and label/handoff/delivery state. No fulfillment mutations.

= 0.1.148 =
* Prefill split-package labels with the owner's wholesale box default: 1.5 kg, 31 x 23.5 x 12 cm. Values remain editable, with a reset-to-default button.

= 0.1.147 =
* Send a reviewed split-shipment email directly from its preview, with recipient binding, duplicate-send protection and explicit mailer status. No automatic fulfillment changes.

= 0.1.146 =
* Compact card action rows and long customer notes, keeping a prominent pre-packing review prompt.

= 0.1.145 =
* Use the reconciled split catalogue for compact packing lists, retaining complimentary items and avoiding duplicate native/quote items.

= 0.1.144 =
* Make wholesale cards packing-first and group secondary Quick View controls into expandable sections. Preserve packing notes, extras, split and fulfillment actions.

= 0.1.143 =
* Recognize complimentary vial quantities in saved wholesale product lists when preparing split packages.

= 0.1.142 =
* Check products to plan the first package and automatically place remaining quantities in the later package.
* Add saved-plan customer previews with package contents, true shipment status, separate tracking and optional delivery estimates. No automatic sending.

= 0.1.141 =
* Expose internal-only shipped and delivered buttons directly on paid wholesale cards, with explicit whole-order confirmations.
* Keep active split shipments on package-level actions and disable already-recorded fulfillment actions.

= 0.1.140 =
* Add quantity-validated split packages with independent labels, tracking, packing lists and handoff status.
* Keep partially shipped orders in To ship until every item is handed off; preserve order totals and suppress automatic package emails.

= 0.1.139 =
* Use the wholesale payment-received email for Woo mobile app and REST API status updates.
* Include the saved quote delivery estimate and wholesale preparation timing in the payment-received email.

= 0.1.138 =
* Show internal-only Mark shipped on packed Wholesale to ship orders, including older orders without a plugin-created label.
* Show internal-only Mark delivered for shipped wholesale orders on cards and in Quick View without sending the WooCommerce completed-order email.

= 0.1.137 =
* Added a private, read-only wholesale operations REST API for compact profit, workflow, and Meta CRM reporting without customer identity or product-level supplier costs.

= 0.1.136 =
* Add an explicit internal-only option for marking a reconciled wholesale payment Processing without emailing the customer.

= 0.1.135 =
* Use recorded partial payments as collected revenue in the wholesale profit panel instead of treating an unpaid quote total as money received.

= 0.1.134 =
* Add a guarded partial-payment recorder to wholesale quote workflows.
* Show the cumulative amount recorded and remaining balance without changing status, emailing the customer, starting fulfillment, or reporting a purchase.

= 0.1.133 =
* Send deduplicated, CRM-labelled QualifiedLead and Purchase outcomes through the existing Meta for WooCommerce Conversions API connection.
* Respect the wholesale quote's saved Meta-measurement consent state and never send product details.

= 0.1.132 =
* Automatically send the wholesale payment-received email when a wholesale order changes to Processing.
* Suppress the generic WooCommerce processing email for wholesale orders so customers receive one status email.

= 0.1.131 =
* Add a searchable store-wide supplier-cost manager with quick product and strength entry.
* Add editable per-kit costs and configurable Starter-tier product-margin signals without changing customer prices.

= 0.1.130 =
* Rename the wholesale order action to Copy customer quote for clearer staff guidance.

= 0.1.129 =
* Show a separately itemized wholesale credit in the final-quote email preview and generated payment instructions.
* Add one customer-facing business day to wholesale delivery estimates for known destinations outside Ontario.

= 0.1.128 =
* Add the self-service testing documentation policy and Lab Results link to every non-refund wholesale customer email update.

= 0.1.127 =
* Add the self-service Lab Results policy and permanent hub link to wholesale payment-received and shipping emails.

= 0.1.126 =
* Add permanent per-product supplier costs directly in the wholesale profit panel.
* Recalculate automatic supplier cost and profit across all wholesale orders from saved per-10-vial-kit defaults.
* Preserve per-order supplier-cost overrides for exceptional orders.

= 0.1.125 =
* Add a Print invoice button to every order Quick View using the existing secured invoice and guide flow.

= 0.1.124 =
* Add private staff-only notes to every wholesale quote card and Quick View.
* Save quote notes without changing order status or notifying the customer.

= 0.1.123 =
* Show customer-facing outbound tracking directly on shipped wholesale cards.
* Reflow the six-step wholesale timeline into a readable two-row grid in narrow cards.

= 0.1.122 =
* Replace the three broad wholesale buckets with a six-step operational timeline: Quote, Stock, Payment, Hamilton, Pack + label, and Shipped.
* Let a final-quote order advance through explicit payment verification, with an optional payment-received email preview.

= 0.1.121 =
* Prevent long wholesale next-step buttons from collapsing the instruction column on narrower Logistics screens.

= 0.1.120 =
* Add a guarded internal workflow-step selector for correcting wholesale order placement without sending email.
* Keep Shipped gated behind the Confirm shipped action and record an inspection when Ready to ship is selected.

= 0.1.119 =
* Immediately add a newly purchased Canada Post label's tracking to the wholesale customer-shipment fields.
* Replace the wholesale label action with Confirm shipped once label tracking is available, with an optional email-preview checkbox.
* Clarify the internal supplier-to-Hamilton and customer-outbound tracking paths.

= 0.1.118 =
* Keep each wholesale card focused on one blue next-step action.
* Add an unchecked Notify customer by email checkbox beneath relevant internal actions; it opens a preview after the step and never sends automatically.
* Keep the progress steps informational so shipment handoff is confirmed only through the current action card.

= 0.1.117 =
* Add internal wholesale workflow actions and customer-update previews.
* Add fulfillment-in-progress and shipped-with-tracking choices to the customer-update picker.

= 0.1.116 =
* Stop treating wholesale label creation on a Completed WooCommerce order as carrier handoff.
* Add an obvious Confirm shipped action in the current task and clickable Shipped progress step, with a confirmation prompt and no automatic email.
* Keep generic Mark shipped actions synchronized with the wholesale workflow.
* Remove stale duplicate next-step text and clarify that the quote release option is only the requested shipping service.

= 0.1.115 =
* Let paid wholesale orders move directly to Ready to ship when stock is already physically on hand and inspected, without fictitious supplier tracking.
* Automatically build the official Canada Post tracking link when an inbound Canada Post tracking number is saved without a link.
* Keep create/view and print shipping-label actions available in order Quick View.

= 0.1.114 =
* Treat Processing or Completed as authoritative for paid wholesale orders, even when an older quote-stage marker remains saved.

= 0.1.113 =
* Itemize wholesale payment emails with per-vial pricing, line totals, subtotal, tax, and the final tax-inclusive total.

= 0.1.112 =
* Use the tax-inclusive wholesale quote total in final payment emails.
* Open, center, and focus the editable live email preview from guided workflow review buttons.

= 0.1.111 =
* Add create/view and print shipping-label actions to order Quick View.

= 0.1.110 =
* Load wholesale customer-email previews only when opened, keeping Quotes and Quick View fast as order volume grows.

= 0.1.109 =
* Add the guided three-stage wholesale workflow: Quotes, Wholesale to ship, and Wholesale shipped.
* Show one current task and the next task on every wholesale card, with workload filters for action, waiting, and ready-to-ship orders.
* Keep supplier inbound tracking separate from customer outbound tracking.
* Add manual customer tracking with an exact email preview before marking a wholesale order shipped.

= 0.1.108 =
* Show wholesale customer email, phone number, and delivery province on Logistics cards and Quick View.

= 0.1.107 =
* Keep item prices and totals fully visible on compact wholesale quote cards.

= 0.1.106 =
* Show the customer name on wholesale quote cards even before shipping details are supplied.
* Display itemized wholesale pricing with subtotal, estimated tax, and tax-inclusive total.
* Add a copy-ready wholesale quote breakdown for WhatsApp.

= 0.1.101 =
* Preselect Card for Pickup or Signature Required from each retail order's saved checkout preference when creating Canada Post labels.
* Apply the same saved protection preference to bulk quick-buy labels.

= 0.1.18 =
* Preserve current logistics features while cleaning up stale Woo prep merge changes.
* Keep logistics admin bootstrap off customer-facing frontend requests, checkout, and unrelated AJAX requests.
* Preserve plugin settings and Canada Post credentials on uninstall unless data deletion is explicitly enabled.

= 0.1.16 =
* Add a medium crop for printable invoices with four to six order items.

= 0.1.15 =
* Crop the printable reconstitution guide and move calculator/disclaimer text into a compact invoice footer.

= 0.1.14 =
* Add compact item pricing and order totals to the printable packing slip.

= 0.1.13 =
* Refine the printable packing slip header and customer-facing order summary.

= 0.1.12 =
* Add a printable packing slip with the PeptideGo reconstitution guide.

= 0.1.11 =
* Keep print-label nonce URLs usable after AJAX label creation by returning unescaped URLs to JavaScript.

= 0.1.10 =
* Add a per-label Card for Pickup option for Canada Post label purchases.

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
