# Changelog

## 1.0.0-rc4 - 2026-08-27

- Fixed the donation confirmation page after returning from PayU: the donor was redirected to the bare success page, which reported a missing donation identifier. The gateway now carries Give's own success and cancel/failed URLs (they include the receipt key) through the secure return route, following the pattern used by Give's PayPal Standard and offsite example gateways.
- Validated the return redirect against the site host so tampered route arguments cannot redirect donors off-site.

## 1.0.0-rc3 - 2026-08-27

- Implemented real refunds through the PayU Refunds API; a failed refund request no longer marks the donation as refunded.
- Added handling of PayU refund notifications (full refunds mark the donation as refunded; canceled refunds restore the donation status and add an admin note); refund notifications are processed idempotently under the per-donation lock.
- Enforced PLN-only donations: non-PLN donations are rejected at order creation and the gateway is hidden on non-PLN forms (both legacy and Visual Form Builder forms).
- Routed donors returning from PayU through a secure gateway return handler that picks the success or failed page based on the payment outcome.
- Marked donations as failed when PayU reports a `CANCELED` order.
- Hardened the webhook lock: token-based ownership check, value-scoped release, release on exceptions, and a 503 response on lock contention so PayU retries instead of dropping the notification.
- Prevented replayed `COMPLETED` notifications from overwriting refunded or cancelled donations (donation state is re-checked under the lock).
- Moved webhook logging after signature verification and reduced logged verification data to the compared fields only.
- Acknowledged signed notifications for missing donations with HTTP 200 to stop PayU retry storms.
- Cached the PayU OAuth token in a transient and retried once on 401 responses.
- Used GiveWP exception types so donors see specific gateway error messages.
- Used `give_get_ip()` for the PayU `customerIp` field so real client IPs are sent behind proxies/CDNs.
- Reordered the settings fields to match the PayU panel (POS ID, second key, OAuth client ID, OAuth client secret).
- Added an admin notice when GiveWP is inactive on WordPress versions without `Requires Plugins` support.
- Added `uninstall.php` cleanup for options, the OAuth token transient, and webhook locks.
- Added License, Plugin URI, and Domain Path plugin headers plus a GPL-2.0 LICENSE file.
- Added translators comments, fixed the Polish translation headers, and added new refund/cancel strings.

## 1.0.0-rc2 - 2026-06-01

- Hardened PayU webhook signature validation.
- Added optional PayU order ID mismatch protection.
- Cleared webhook processing lock after successful completion.
- Rebuilt release package for the second release candidate.

## 1.0.0-rc1 - 2026-06-01

- Promoted PayU gateway to release candidate after successful sandbox payment verification.
- Documented production payment test as pending.
- Rebuilt release package for the release candidate.

## 0.1.2 - 2026-06-01

- Marked PayU sandbox payment flow as tested successfully.
- Rebuilt release package after sandbox verification.

## 0.1.1 - 2026-06-01

- Added development notes.
- Matched repository ignore rules with the gateway template.
- Rebuilt release package with current repository files.

## 0.1.0 - 2026-05-31

- Added PayU gateway for GiveWP/Give one-time offsite donations.
- Added sandbox and production settings.
- Added OAuth access test.
- Added PayU order creation and offsite redirect.
- Added webhook signature verification and API order verification.
- Added Visual Donation Form Builder support.
- Added Polish translation files.
