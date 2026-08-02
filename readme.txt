=== Yo Booking ===
Contributors: yoohw
Tags: booking, appointment booking, scheduling, booking calendar, availability
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.0.2
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

= 2.0.2 (Aug 2, 2026) =

* New: Added a Customer Portal Gutenberg block alongside the booking block and grouped both blocks in a dedicated Yo Booking inserter category.
* New: Added a Company logo setting with WordPress Media Library selection, upload support, removal controls, and an inline image preview.
* New: Added per-service frontend payment quotes so full-payment, deposit, and no-collection modes are applied consistently before and after booking.
* New: Added a Notifications Settings tab for sender details, configurable email colors, editable footer text, and a live HTML design preview.
* Improve: Updated the Yo Booking block icon to use the matching UIcons calendar and normalized its visual size in the block inserter.
* Improve: Reorganized General settings into Company information, Regional settings, Booking rules, and Customer booking sections; moved Default currency to Payments and widened settings table labels.
* Improve: Formatted editable monetary values throughout the admin with the configured currency precision and decimal and thousands separators while preserving normalized values for calculations.
* Improve: Added synchronized editable HEX inputs to every color picker and normalized their typography and validation feedback.
* Improve: Redesigned HTML notification emails with the event title in the card header, the centered company logo or brand-colored company name above the card, configurable surfaces and text colors, and a customizable linked footer.
* Improve: Displayed deposit due and remaining balance details on the frontend only when collection mode is Deposit, while keeping full-payment collection active without the deposit summary.
* Improve: Renamed Booking Portal to Customer Portal, moved Appearance above Reports, and removed the Admin icons row from System Status.
* Update: Removed the redundant global notifications_enabled option so notification delivery is controlled by individual templates and event settings.
* Update: Extended localized money formatting and parsing to services, appointments, payment transactions, calendar actions, REST payloads, and payment snapshots.

See `changelog.txt` for the complete release history.
