<?php
/**
 * Donor return URL handling.
 *
 * Regression cover for the 1.0.0-rc4 bug: the donation confirmation URL was
 * passed through the PayU return URL, but Give sanitizes gateway route params
 * with give_clean() (sanitize_text_field), which strips percent-encoded
 * sequences. PayU returns the continueUrl re-encoded, so the URL came back with
 * every %XX removed and resolved into a 404 against the site root.
 */

$confirmation = 'https://example.test/support-us?givewp-event=donation-completed'
    . '&givewp-listener=show-donation-confirmation-receipt'
    . '&givewp-receipt-id=37f07d224b5630cb6b4b476437384286'
    . '&givewp-embed-id=give-form-shortcode-1';
$fallback = 'https://example.test/donation-confirmation/';

give_payu_test_section('give_payu_gateway_plain_url(): normalize what Give hands the gateway');
give_payu_test_check(
    'form builder path passes the URL rawurlencoded',
    give_payu_gateway_plain_url(rawurlencode($confirmation)),
    $confirmation
);
give_payu_test_check('legacy path passes the URL plain', give_payu_gateway_plain_url($confirmation), $confirmation);
give_payu_test_check('empty string stays empty', give_payu_gateway_plain_url(''), '');
give_payu_test_check('null becomes an empty string', give_payu_gateway_plain_url(null), '');
give_payu_test_check(
    'a non-URL value is returned untouched',
    give_payu_gateway_plain_url('not-a-url'),
    'not-a-url'
);

give_payu_test_section('give_payu_gateway_safe_return_url(): where the return handler sends the donor');
give_payu_test_check(
    'the stored confirmation URL is used',
    give_payu_gateway_safe_return_url($confirmation, $fallback),
    $confirmation
);
give_payu_test_check('no stored URL falls back', give_payu_gateway_safe_return_url('', $fallback), $fallback);
give_payu_test_check(
    'an off-site URL falls back',
    give_payu_gateway_safe_return_url('https://evil.example.com/x', $fallback),
    $fallback
);
give_payu_test_check(
    'a protocol-relative URL falls back',
    give_payu_gateway_safe_return_url('//evil.example.com/x', $fallback),
    $fallback
);
give_payu_test_check(
    'a relative path falls back instead of resolving against the site root',
    give_payu_gateway_safe_return_url('/support-us', $fallback),
    $fallback
);
give_payu_test_check(
    'a scheme without a host falls back',
    give_payu_gateway_safe_return_url('https://', $fallback),
    $fallback
);
give_payu_test_check(
    'the rc4 mangled value falls back instead of producing a 404',
    give_payu_gateway_safe_return_url('https20y.schtomy.plsupport-usgivewp-eventdonation-completed', $fallback),
    $fallback
);

give_payu_test_section('why the URL cannot travel in the return URL (Give sanitizes route params)');
$encoded_param = rawurlencode($confirmation);            // what rc4 put in the return URL
$returned_by_payu = rawurlencode($encoded_param);        // PayU hands the continueUrl back re-encoded
$after_php_decode = rawurldecode($returned_by_payu);     // PHP decodes $_GET once
$after_give_clean = give_clean($after_php_decode);       // Give sanitizes the route params

give_payu_test_check(
    'an encoded URL loses its separators and is no longer a URL',
    strpos($after_give_clean, '://') !== false,
    false
);
give_payu_test_check(
    'the numeric donation ID and PayU error code survive sanitization',
    give_clean(['givewp-donation-id' => '3634', 'error' => '501']),
    ['givewp-donation-id' => '3634', 'error' => '501']
);
