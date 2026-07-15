# Currency catalog

Yo Booking uses the current ISO 4217 List One published by the official Maintenance Agency, SIX Financial Information AG. The bundled catalog snapshot is dated 2026-01-01 and contains 178 unique alphabetic codes.

Each entry stores:

- Three-letter alphabetic code.
- Three-digit numeric code.
- Currency or funds name.
- ISO minor-unit precision where defined.
- Internal decimal precision used by the booking money engine.

ISO entries with `N.A.` minor units, including precious metals and accounting units, retain `iso_minor_units => null`. Yo Booking uses four decimal places internally for those entries because its payment schema supports up to four decimal places and must not silently discard fractional values.

The `yo_booking_currencies` filter remains available for add-ons that need to add private settlement units or adjust display metadata. Historical codes are intentionally excluded from the default selector.
