# Changelog

## 1.0.0 - 2026-08-27

First stable release. Sandbox and production payments verified end to end.

### Added

- Refunds through the PayU Refunds API from the GiveWP donation screen, and handling of PayU refund notifications: a finalized full refund marks the donation as refunded, a canceled refund restores the donation status and records an admin note, and partial refunds are noted for manual review.
- A "Payment description prefix" setting that opens the payment description PayU shows the donor, so the wording is not fixed in the code.
- The PayU logo in the payment method selector, in place of Give's default gateway icon.
- An admin notice when GiveWP is inactive on WordPress versions without `Requires Plugins` support.
- `uninstall.php` cleanup for settings, the OAuth token and internal donation meta.
- License, Plugin URI and Domain Path plugin headers, plus a GPL-2.0 LICENSE file.
- A dependency-free test suite (`php tests/run.php`).

### Changed

- PLN-only donations are enforced: non-PLN donations are rejected at order creation and the gateway is hidden on non-PLN forms, both legacy and Visual Donation Form Builder.
- Donors returning from PayU go through a secure gateway return route that sends them to the donation confirmation page on success and to the failed-donation page otherwise.
- Donations are marked as failed when PayU reports a canceled order.
- The PayU OAuth token is cached between requests and refreshed once on a 401.
- Donor-facing gateway errors use GiveWP exception types, so the specific message is shown instead of a generic notice.
- The PayU `customerIp` field uses `give_get_ip()`, so real donor IPs are sent from behind proxies and CDNs.
- Settings fields follow the order used in the PayU panel, and saving them requires the GiveWP settings capability.
- Webhook payload details are logged only after the signature is verified, and order verification logs are reduced to the compared fields.

### Fixed

- Refunds no longer report success without reaching PayU, so a failed refund request cannot leave a donation marked as refunded.
- Non-PLN donations can no longer complete against a PLN PayU order.
- The webhook lock is released on failure and is owned per request, lock contention is answered with a retryable status, and a replayed completion notification can no longer overwrite a refunded or cancelled donation.
- Signed notifications for donations that no longer exist are acknowledged instead of being retried by PayU for days.
- Polish translation files carry the correct language and plural headers.

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
