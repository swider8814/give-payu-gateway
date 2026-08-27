# Give PayU Gateway

PayU payment gateway for GiveWP/Give donations.

## Status

Stable release for one-time offsite donations:

- Give payment gateway ID: `payu`
- Visual Donation Form Builder support
- sandbox and production modes
- PLN donations only (the gateway is hidden and rejected for other currencies)
- PayU order creation through OAuth and Orders API (OAuth token cached between requests)
- REST webhook endpoint for order notification verification
- cancelled/failed PayU payments mark the donation as failed
- refunds through the PayU Refunds API from the GiveWP donation screen
- PayU refund notifications mark fully refunded donations as refunded
- donors returning from PayU are routed to the success or failed page based on the payment outcome
- configurable payment description prefix
- PayU logo in the payment method selector
- English source strings with Polish translation
- sandbox payment flow verified end to end
- production payment verified end to end

## Installation

Download the ZIP attached to the latest release:

```text
https://github.com/swider8814/give-payu-gateway/releases/latest
```

In WordPress go to:

```text
Plugins > Add New > Upload Plugin
```

Upload `give-payu-gateway-<version>.zip`, install it, then activate **Give PayU Gateway**.

Alternatively, copy this directory to:

```text
wp-content/plugins/give-payu-gateway
```

Then activate **Give PayU Gateway** in WordPress.

## Configuration

Go to:

```text
Donations > Settings > Payment Gateways > PayU
```

Set:

- Mode: sandbox or production
- Payment description prefix: optional
- POS ID: PayU merchant POS identifier
- Second key (MD5)
- OAuth client ID
- OAuth client secret

Use **Test PayU API access** after saving credentials to verify that the selected mode and OAuth credentials are valid.

## PayU Field Mapping

The settings fields follow the same order as the PayU panel:

- POS ID (pos_id) -> POS ID
- Second key (MD5) -> Second key (MD5)
- OAuth protocol - client_id -> OAuth client ID
- OAuth protocol - client_secret -> OAuth client secret

## Webhook

The plugin registers this REST endpoint:

```text
/wp-json/give-payu-gateway/v1/status
```

This URL is sent to PayU as `notifyUrl`. Payment completion is based on the PayU notification plus order verification through the PayU API, not on the return URL alone.

The same endpoint also receives PayU refund notifications: a finalized full refund marks the donation as refunded, and a `CANCELED` order notification marks a pending donation as failed.

For a full sandbox payment test, the WordPress site must be reachable by PayU over public HTTPS. A local `localhost` site can create orders, but it cannot receive the PayU status notification.

## Sandbox Test Checklist

- Install Give and this gateway on a public HTTPS WordPress test site.
- Configure sandbox credentials in `Donations > Settings > Payment Gateways > PayU`.
- Enable PayU as a payment gateway in Give.
- Create or open a Visual Donation Form Builder form with PLN amounts.
- Make a sandbox donation and complete payment on PayU.
- Confirm the donation changes from `Pending` to `Complete`.
- Check `Donations > Tools > Logs` for PayU entries if the status does not update.

## Troubleshooting

If a donation stays `Pending`:

- Make sure the WordPress site is publicly reachable over HTTPS.
- Confirm the webhook URL works: `/wp-json/give-payu-gateway/v1/status`.
- Check that sandbox credentials are used only in sandbox mode, and production credentials only in production mode.
- Use **Test PayU API access** in the gateway settings.
- Check `Donations > Tools > Logs` for PayU entries.

## Production Readiness

Before using production mode, confirm that:

- the production PayU account is active,
- the WordPress domain is configured in PayU if required,
- production POS ID, OAuth client ID, OAuth client secret and second key are entered,
- **Test PayU API access** succeeds in production mode,
- a small live payment is completed and verified end-to-end.

## Payment Description

PayU shows a payment description to the donor, on the payment page and in its confirmation email. It is built as:

```text
<Payment description prefix> - <donation form title>
```

Set the prefix in the gateway settings, for example `SCh Tomy`, giving `SCh Tomy - Donation for religious worship`. When a donation has no form title, the donation ID is used instead: `SCh Tomy #3634`. With the setting empty the description opens with the translated word for donation.

The description is capped at 80 characters, so keep the prefix short enough to leave room for the form title.

## PayU Logo

The gateway shows the PayU logo instead of Give's default gateway icon when this file is present:

```text
assets/img/payu.svg
```

Use the official PayU logo from the PayU brand assets. Without the file, Give's own icon is used and nothing breaks.

## Tests

Run the test suite with PHP only, no dependencies:

```bash
php tests/run.php
```

It covers webhook signature verification, amount conversion, `extOrderId` parsing, settings sanitization and donor return URL handling.

## Local Test Environment

For local WordPress testing with Docker:

```bash
docker-compose up -d
```

WordPress runs at:

```text
http://localhost:8080
```

Default local test credentials:

```text
admin / admin
```
