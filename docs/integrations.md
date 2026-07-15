# Yo Booking Integrations

## Outbound webhooks

Configure endpoints under **Yo Booking > Settings > Integrations > Webhook endpoints**. Production endpoints must use a public HTTPS URL. The signing secret is shown once after creation or rotation.

Supported events:

- `appointment.created`
- `appointment.updated`
- `appointment.status_changed`
- `appointment.rescheduled`
- `payment.status_changed`
- `payment.transaction_recorded`
- `payment.refunded`

Each request contains a JSON snapshot and these headers:

```text
Content-Type: application/json
X-Yo-Booking-Event: appointment.created
X-Yo-Booking-Delivery: 123
X-Yo-Booking-Timestamp: 1783938000
X-Yo-Booking-Signature: sha256=...
```

Verify the signature by calculating:

```text
sha256=HMAC_SHA256("{timestamp}.{raw_request_body}", signing_secret)
```

Use a constant-time comparison and reject timestamps outside an acceptable replay window. The raw request body must be used without parsing or reformatting it first.

HTTP status codes from `200` through `299` mark a delivery successful. Other responses retry after approximately 1 minute, 5 minutes, 30 minutes, and 2 hours. A delivery is marked failed after five attempts. Administrators can retry failed deliveries from the delivery log.

## Integration API

Create API keys under **Yo Booking > Settings > Integrations > API keys**. Keys are shown once and stored only as hashes.

Send the key as a bearer token:

```text
Authorization: Bearer yb_live_...
```

Base path:

```text
/wp-json/yo-booking/v1/integrations/
```

Endpoints and required capabilities:

| Method | Endpoint | Capability |
| --- | --- | --- |
| `GET` | `appointments` | `read_appointments` |
| `GET` | `appointments/{id}` | `read_appointments` |
| `POST` | `appointments/{id}/status` | `write_appointments` |
| `GET` | `customers` | `read_customers` |

Appointment list filters include `status`, `payment_status`, `service_id`, `staff_id`, `from`, `to`, `page`, and `per_page`. The maximum page size is 100.

Status update example:

```json
{
  "status": "confirmed",
  "reason": "Confirmed by external CRM"
}
```

Revoke a key immediately when an integration no longer requires access. Use separate keys with the minimum required capabilities for each external system.
