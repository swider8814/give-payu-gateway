# Give PayU Gateway

PayU payment gateway for GiveWP/Give donations.

## Status

Early release for one-time offsite donations:

- Give payment gateway ID: `payu`
- Visual Donation Form Builder support
- sandbox and production modes
- PayU order creation through OAuth and Orders API
- REST webhook endpoint for order notification verification
- English source strings with Polish translation
- local WordPress activation tested
- sandbox payment flow pending PayU sandbox credentials and public HTTPS webhook URL

## Installation

Download the latest release ZIP:

```text
https://github.com/swider8814/give-payu-gateway/releases/latest/download/give-payu-gateway.zip
```

In WordPress go to:

```text
Plugins > Add New > Upload Plugin
```

Upload `give-payu-gateway.zip`, install it, then activate **Give PayU Gateway**.

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
- POS ID: PayU merchant POS identifier
- OAuth client ID
- OAuth client secret
- Second key (MD5)

Use **Test PayU API access** after saving credentials to verify that the selected mode and OAuth credentials are valid.

## PayU Field Mapping

Use these values from the PayU panel:

- POS identifier -> POS ID
- OAuth protocol - client_id -> OAuth client ID
- OAuth protocol - client_secret -> OAuth client secret
- Second key -> Second key (MD5)

## Webhook

The plugin registers this REST endpoint:

```text
/wp-json/give-payu-gateway/v1/status
```

This URL is sent to PayU as `notifyUrl`. Payment completion is based on the PayU notification plus order verification through the PayU API, not on the return URL alone.

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
