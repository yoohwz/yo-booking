=== Yo Booking ===
Contributors: yoohw
Tags: booking, appointment booking, scheduling, booking calendar, availability
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress appointment booking with service scheduling, staff availability, customer self-service, notifications, and offline payments.

== Description ==

Yo Booking is a complete WordPress appointment booking plugin for service businesses that need a practical way to manage schedules, customers, payments, and day-to-day booking operations.

Customers can book appointments through a responsive step-by-step form, choose a service and staff member, find an available time, select an offline payment method, and manage an existing booking. Administrators get a focused booking dashboard, interactive calendar, customer records, email notifications, reports, audit logs, and maintenance tools inside WordPress.

The core plugin works without WooCommerce or an external booking service. Online payment gateways are intentionally handled through separately distributed add-ons, while the core includes Pay locally and Bank transfer methods.

= Appointment booking and calendar management =

* Create and manage service categories, services, staff members, and customers.
* Assign staff members to the services they provide.
* Manage appointments in list, month, week, day, and agenda views.
* Drag, drop, or resize calendar events with conflict validation and rollback.
* Filter appointments and update statuses, payments, notes, and schedules from the admin area.
* Track pending, confirmed, cancelled, completed, no-show, and rescheduled appointments.
* Export authorized appointment, customer, and report data to CSV.

= Staff availability and scheduling =

* Configure global business hours and individual staff schedules.
* Add multiple working periods to the same day.
* Create date exceptions for holidays, leave, closures, or special opening hours.
* Set lead time, booking window, and appointment slot intervals.
* Prevent overlapping appointments with server-side availability and conflict checks.
* Use the timezone, date format, and time format configured in WordPress Settings.

= Frontend booking experience =

* Add the booking form with the Yo Booking block or the `[yo-booking]` shortcode.
* Guide customers through service, staff, date, time, details, and review steps.
* Allow guest booking or prefill details for signed-in customers.
* Let customers select a preferred staff member or the first available option.
* Customize colors, layout density, button styling, progress steps, prices, and service descriptions.
* Preview desktop and mobile booking styles before publishing changes.
* Provide clear loading, validation, empty, success, and error states.

= Customer self-service =

* Add a signed-in customer portal with the `[yo-booking-portal]` shortcode.
* Show upcoming and past appointments with booking and payment details.
* Give customers secure token-based cancel and reschedule links.
* Enforce configurable cancellation and rescheduling deadlines.
* Allow administrators to override customer-facing booking restrictions when needed.

= Email notifications and reminders =

* Send emails for booking creation, confirmation, cancellation, rescheduling, completion, reminders, and payment activity.
* Customize email subjects, content, recipients, timing, and placeholders.
* Attach ICS calendar files to supported appointment emails.
* Preview templates with appointment data, send test emails, and retry failed deliveries.
* Review notification logs and process queued reminders through WordPress Cron.

= Offline payments and currency formatting =

* Offer Pay locally and Bank transfer during the booking flow.
* Configure bank details and customer-facing payment instructions.
* Request no payment, a deposit, or the full appointment amount.
* Track unpaid, partially paid, paid, and refunded booking balances.
* Record manual payment and refund entries with references and immutable booking totals.
* Select from the WooCommerce currency catalog without requiring WooCommerce.
* Configure currency position, thousand separator, decimal separator, and decimal precision.
* Extend payment processing through the documented provider contract for gateway add-ons.

= Operations, privacy, and security =

* Monitor today's schedule, upcoming workload, booking status, payment follow-up, recent activity, and popular services from the dashboard.
* Use Booking Manager and Booking Staff roles with scoped WordPress capabilities.
* Restrict staff accounts to appointments assigned to their linked staff profile.
* Review immutable audit records for important booking, payment, customer, service, staff, and settings changes.
* Use WordPress personal data export and erasure tools for booking customer data.
* Configure data retention and run scheduled or manual cleanup tasks.
* Create password-encrypted same-site backups and restore managed booking data.
* Check booking tables and scheduled tasks through WordPress Site Health.

= Integrations for developers =

* Create hashed API keys with separate appointment and customer scopes.
* Use server-to-server REST endpoints for authorized appointment and customer workflows.
* Send signed outbound webhooks through a retry queue with delivery logs.
* Register separately distributed payment providers through the gateway add-on contract.

API authentication, webhook signatures, payloads, and examples are documented in `docs/integrations.md`. The payment add-on contract is documented in `docs/payment-addons.md`.

== Shortcodes and Block ==

= Booking form =

Use the **Yo Booking** block in the WordPress block editor, or add `[yo-booking]` to any page.

= Customer portal =

Add `[yo-booking-portal]` to a page that is intended for signed-in customers.

== Installation ==

1. Install Yo Booking from the WordPress Plugins screen, or upload it to the `wp-content/plugins/yo-booking` directory.
2. Activate the plugin and open **Yo Booking > Dashboard**.
3. Create service categories and services, then add staff members and assign their services.
4. Configure business hours, staff schedules, exceptions, lead time, and booking window under **Availability** and **Settings**.
5. Review offline payment methods, currency display, notifications, and customer policies in **Settings**.
6. Customize the frontend booking interface under **Yo Booking > Appearance**.
7. Add the **Yo Booking** block or `[yo-booking]` shortcode to your booking page.
8. Optionally add `[yo-booking-portal]` to a page for signed-in customer self-service.
9. Send a test notification and create a test booking before accepting live appointments.

== Frequently Asked Questions ==

= How do I add appointment booking to a page? =

Insert the **Yo Booking** block in the block editor or place `[yo-booking]` in the page content. The same shortcode is displayed with a copy button under **Yo Booking > Appearance**.

= Can customers book without creating an account? =

Yes. Guest booking can be enabled or disabled in Booking settings. Signed-in customers can have their saved contact details prefilled automatically.

= Can customers cancel or reschedule their own appointments? =

Yes. Yo Booking provides secure token-based links and a signed-in customer portal. You can define how many hours before an appointment cancellation or rescheduling remains available.

= Does Yo Booking include online card payments? =

No. The core plugin includes Pay locally and Bank transfer, plus manual payment, deposit, balance, and refund tracking. Online gateways are designed as optional add-ons so payment integrations remain separate from the booking core.

= Does Yo Booking require WooCommerce? =

No. Yo Booking is a standalone appointment scheduling plugin. Its currency selector follows the WooCommerce currency catalog for familiar global coverage, but WooCommerce is not required.

= Which timezone and date formats does the plugin use? =

Customer-facing and admin booking dates follow the timezone, date format, and time format in **Settings > General**. Database timestamps remain normalized for reliable scheduling and API use.

= How are appointment emails sent? =

Yo Booking sends notifications through the standard WordPress email system and processes reminders with WordPress Cron. Reliable site email delivery still depends on the hosting mail setup or an SMTP plugin.

= Is customer booking data compatible with WordPress privacy tools? =

Yes. The plugin registers personal data exporters and erasers, provides privacy policy guidance, supports retention cleanup, and records marketing consent when that setting is enabled.

= What happens to booking data when I uninstall the plugin? =

Booking data is retained by default. To remove plugin tables, settings, roles, and capabilities during uninstall, first enable **Remove all data on uninstall** in the Maintenance settings. This action cannot be undone without a backup.

= Can developers connect another system to Yo Booking? =

Yes. Scoped API keys, REST endpoints, signed outbound webhooks, and payment provider hooks are available. See `docs/integrations.md` for API and webhook details, and `docs/payment-addons.md` for payment gateway implementation.

== Privacy ==

Yo Booking stores booking, customer, staff, service, notification, payment, audit, and integration delivery data in the WordPress database as required by enabled features.

The plugin does not send booking data to an external booking service by default. When an administrator configures an outbound webhook or a third-party add-on, data is sent to the endpoint or provider selected by that administrator. Site owners are responsible for documenting those destinations and their privacy terms.

Use the WordPress **Export Personal Data** and **Erase Personal Data** tools to process customer requests. Review retention settings, notification content, payment instructions, and connected integrations before accepting live bookings.

== Changelog ==

= 2.0.0 =
* Rebuild Yo Booking from a clean foundation with versioned database migrations, structured settings, and configurable uninstall cleanup.
* Add service categories, services, staff assignments, customer profiles, scoped booking roles, and ownership-aware administration.
* Add global and staff availability, split working hours, date exceptions, lead time, booking windows, slot intervals, caching, and conflict-safe scheduling.
* Add a responsive frontend booking flow with a Gutenberg block, shortcode, guest booking, signed-in autofill, appearance controls, and desktop/mobile previews.
* Add appointment lifecycle management, interactive calendar views, drag-and-drop rescheduling, quick and bulk actions, internal notes, operational reports, and CSV exports.
* Add token-protected cancellation and rescheduling plus a signed-in customer portal for upcoming and past appointments.
* Add customizable email templates, reminders, ICS attachments, delivery logs, previews, test emails, queue locking, and failed-delivery retries.
* Add Pay locally and Bank transfer methods, deposit and full-payment modes, currency formatting, immutable totals, payment/refund ledger entries, and an add-on contract for online gateways.
* Add scoped API keys, integration REST endpoints, signed outbound webhooks, queued retries, and delivery logs.
* Add privacy exporters and erasers, retention cleanup, audit logs, Site Health checks, encrypted same-site backup and restore, rate limits, and release security hardening.
* Synchronize plugin timezone, date, and time display with WordPress settings and align the admin interface with each user's WordPress color scheme.
* Add release gates for migrations, concurrency, security, dependencies, PHP compatibility, notifications, backup round trips, critical booking workflows, Plugin Check compliance, and the current POT translation template.
