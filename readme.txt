=== Yo Booking ===
Contributors: yoohw
Tags: booking, appointment booking, scheduling, booking calendar, availability
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress appointment booking with staff availability, customer self-service, notifications, offline payments, and integrations.

== Description ==

[Product page](https://yoohw.com/product/yo-booking/) | [Documentation](https://docs.yoohw.com/category/yo-booking/) | [Support](https://workspace.yoohw.com/)

Yo Booking is a standalone WordPress appointment booking plugin for service businesses. It combines a customer booking flow with service and staff scheduling, appointment operations, notifications, offline payments, customer self-service, and developer integrations.

The core plugin does not require WooCommerce or an external booking service. It includes Pay locally and Bank transfer. Online payment gateways are handled by separately distributed add-ons.

= Services, staff, and availability =

* Manage service categories, services, staff assignments, and customer profiles.
* Configure business hours, individual staff schedules, split working periods, and date exceptions.
* Set lead time, booking window, slot interval, cancellation deadline, and rescheduling deadline.
* Prevent overlapping appointments through server-side availability and conflict checks.
* Follow the timezone, date format, and time format configured in WordPress.

= Booking and customer self-service =

* Add the responsive booking flow with the Yo Booking block or `[yo-booking]` shortcode.
* Support guest booking and signed-in customer detail prefilling.
* Let customers select a service, preferred or first-available staff member, date, time, and payment method.
* Customize colors, layout density, buttons, progress display, prices, and service descriptions.
* Add `[yo-booking-portal]` for signed-in customers to review upcoming and past appointments.
* Provide secure token-based cancellation and rescheduling links with configurable deadlines.

= Appointment operations and notifications =

* Manage appointments in list, month, week, day, and agenda views.
* Drag, drop, or resize calendar events with conflict validation and rollback.
* Track pending, confirmed, cancelled, completed, no-show, and rescheduled appointments.
* Update schedules, status, payment details, notes, and customer records from WordPress admin.
* Send customizable creation, confirmation, cancellation, rescheduling, completion, reminder, and payment emails.
* Attach ICS calendar files, preview templates, send tests, retry failures, and review delivery logs.
* Export authorized appointment, customer, and report data to CSV.

= Payments, administration, and recovery =

* Offer Pay locally and Bank transfer with no-payment, deposit, or full-payment modes.
* Track unpaid, partially paid, paid, and refunded balances.
* Record manual payments and refunds with references while preserving booking totals.
* Use Booking Manager and Booking Staff roles with scoped capabilities and staff ownership restrictions.
* Review audit records for important booking, payment, customer, service, staff, and settings changes.
* Use WordPress personal data export and erasure tools and configurable retention cleanup.
* Create password-encrypted same-site backups and restore managed booking data.
* Check database tables and scheduled tasks through WordPress Site Health.

= Developer integrations =

* Create hashed API keys with separate appointment and customer scopes.
* Use authorized REST endpoints for server-to-server workflows.
* Send signed outbound webhooks through a retry queue with delivery logs.
* Register separately distributed payment gateways through the documented provider contract.

See `docs/integrations.md` for API and webhook details and `docs/payment-addons.md` for the payment provider contract.

== Installation ==

1. Install the plugin through the WordPress Plugins screen, or upload it to `/wp-content/plugins/yo-booking/`.
2. Activate Yo Booking and open **Yo Booking > Dashboard**.
3. Create service categories, services, staff, and service assignments.
4. Configure business hours, staff schedules, exceptions, booking limits, and customer policies.
5. Review payment methods, currency display, notifications, and Appearance settings.
6. Add the Yo Booking block or `[yo-booking]` to the booking page.
7. Optionally add `[yo-booking-portal]` to a page for signed-in customers.
8. Send a test notification and complete a test booking before accepting live appointments.

== Frequently Asked Questions ==

= Does Yo Booking require WooCommerce? =

No. It is a standalone appointment scheduling plugin. The currency list follows the familiar WooCommerce catalog, but WooCommerce is not required.

= How do I add the booking form? =

Insert the Yo Booking block in the block editor or add `[yo-booking]` to a page.

= Can customers book without an account? =

Yes, when guest booking is enabled. Signed-in customers can have saved contact details prefilled.

= Can customers cancel or reschedule an appointment? =

Yes. Secure token links and the signed-in customer portal support self-service within the deadlines configured by the administrator.

= Does the core plugin include online card payments? =

No. The core includes Pay locally, Bank transfer, deposits, balance tracking, and manual payment or refund records. Online gateways use separate add-ons.

= How are emails and reminders sent? =

They use the WordPress email system, while reminders are processed by WordPress Cron. Reliable delivery still depends on the site's mail or SMTP configuration.

= Does it support WordPress privacy tools? =

Yes. The plugin registers personal data exporters and erasers, provides retention controls, and includes privacy guidance.

= What happens to data on uninstall? =

Booking data is retained by default. To remove managed tables, settings, roles, and capabilities, enable **Remove all data on uninstall** before uninstalling. Back up first.

= Can another system connect to Yo Booking? =

Yes. The plugin provides scoped API keys, REST endpoints, signed webhooks, and a payment-provider contract for developers.

== Privacy ==

Yo Booking stores the booking, customer, staff, service, notification, payment, audit, and integration-delivery data needed by enabled features in the WordPress database.

It does not send booking data to an external booking service by default. Data is sent externally only when an administrator configures a webhook or third-party add-on. Site owners are responsible for documenting those destinations and reviewing their privacy terms.

== Changelog ==

= 2.0.1 =

* New: Added appointment list sorting by earliest or latest appointment time and oldest or newest creation time while preserving active filters and pagination.
* New: Replaced raw WordPress user ID inputs in staff and customer add/edit forms with searchable user selectors.
* New: Added intl-tel-input to phone fields across the admin and frontend, including international validation, E.164 normalization, and responsive country selection.
* New: Added persistent ISO-2 phone countries for customers and staff and preserved the customer phone country in immutable appointment snapshots.
* New: Added a Default phone country setting with resolution from the saved profile, existing E.164 number, the guest's current session, the Yo Booking setting, then the WordPress locale without a WooCommerce dependency or hardcoded US fallback.
* Improve: Refined Appointment List and Calendar tab spacing, added balanced list/table margins, and vertically centered row selection checkboxes.
* Improve: Refined spacing around the payment ledger transaction form, Add transaction button, transaction history, filter cards, and adjacent admin cards.
* Improve: Prevented time, interval, add/remove, and copy actions from changing disabled weekdays in Business Hours and Staff Hours.
* Improve: Displayed the Exceptions Staff member field only when the selected scope applies to an individual staff member.
* Improve: Displayed Reminder offset only for notification events that use reminder scheduling.
* Improve: Standardized form label, control, helper-text, checkbox, and action alignment throughout the plugin and constrained oversized desktop editor forms to more readable widths.
* Improve: Expanded the Appearance live preview to cover every booking step instead of showing only a partial booking flow.
* Improve: Corrected spacing between the icon and text in the phone country search field.
* Update: Extended phone country data to frontend booking requests, admin appointment APIs, integration REST responses, signed webhook payloads, privacy erasure, and database migrations.
* Update: Upgraded WordPress Coding Standards and related development tooling to patched releases.

See `changelog.txt` for the full 2.0.1 details and complete release history.
