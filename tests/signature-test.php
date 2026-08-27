<?php
/**
 * Webhook signature verification.
 *
 * PayU signs the notification body with MD5(body + second key) and sends it in
 * the OpenPayu-Signature header. Nothing in the webhook may change donation
 * state before this passes, so these cases guard the security boundary.
 */

$second_key = 'test-second-key';
$body = '{"order":{"orderId":"ABC123","extOrderId":"give-1-uuid","status":"COMPLETED","totalAmount":"10000"}}';

give_payu_test_set_options([
    'mode' => 'sandbox',
    'pos_id' => '300746',
    'client_id' => '300746',
    'client_secret' => 'secret',
    'second_key' => $second_key,
]);

$signature = md5($body . $second_key);

$request = static function (string $body, ?string $header, string $header_name = 'openpayu-signature'): WP_REST_Request {
    return new WP_REST_Request($body, $header === null ? [] : [$header_name => $header]);
};

give_payu_test_section('give_payu_gateway_verify_signature(): accepted requests');
give_payu_test_check(
    'a correctly signed request passes',
    give_payu_gateway_verify_signature($request($body, "signature={$signature};algorithm=MD5;sender=checkout")),
    true
);
give_payu_test_check(
    'the x-openpayu-signature header is accepted too',
    give_payu_gateway_verify_signature($request($body, "signature={$signature};algorithm=MD5", 'x-openpayu-signature')),
    true
);
give_payu_test_check(
    'header keys and the algorithm name are case-insensitive',
    give_payu_gateway_verify_signature($request($body, "Signature={$signature};Algorithm=md5")),
    true
);
give_payu_test_check(
    'an uppercase signature digest is accepted',
    give_payu_gateway_verify_signature($request($body, 'signature=' . strtoupper($signature) . ';algorithm=MD5')),
    true
);
give_payu_test_check(
    'surrounding whitespace in the header is tolerated',
    give_payu_gateway_verify_signature($request($body, " signature = {$signature} ; algorithm = MD5 ")),
    true
);

give_payu_test_section('give_payu_gateway_verify_signature(): rejected requests');
give_payu_test_check(
    'a missing header is rejected',
    give_payu_gateway_verify_signature($request($body, null)),
    false
);
give_payu_test_check(
    'a tampered body is rejected',
    give_payu_gateway_verify_signature($request($body . ' ', "signature={$signature};algorithm=MD5")),
    false
);
give_payu_test_check(
    'a signature made with a different second key is rejected',
    give_payu_gateway_verify_signature($request($body, 'signature=' . md5($body . 'wrong-key') . ';algorithm=MD5')),
    false
);
give_payu_test_check(
    'a header without key=value pairs is rejected',
    give_payu_gateway_verify_signature($request($body, $signature)),
    false
);
give_payu_test_check(
    'a missing algorithm is rejected',
    give_payu_gateway_verify_signature($request($body, "signature={$signature}")),
    false
);
give_payu_test_check(
    'a non-hex digest of the right length is rejected',
    give_payu_gateway_verify_signature($request($body, 'signature=' . str_repeat('z', 32) . ';algorithm=MD5')),
    false
);
give_payu_test_check(
    'a digest of the wrong length is rejected',
    give_payu_gateway_verify_signature($request($body, 'signature=abc123;algorithm=MD5')),
    false
);
give_payu_test_check(
    'an empty signature value is rejected',
    give_payu_gateway_verify_signature($request($body, 'signature=;algorithm=MD5')),
    false
);

give_payu_test_section('give_payu_gateway_verify_signature(): documented current behaviour');
give_payu_test_check(
    'only MD5 is accepted; a SHA-256 signed notification would be rejected',
    give_payu_gateway_verify_signature(
        $request($body, 'signature=' . hash('sha256', $body . $second_key) . ';algorithm=SHA-256')
    ),
    false
);

give_payu_test_section('give_payu_gateway_verify_signature(): gateway not configured');
give_payu_test_set_options([
    'mode' => 'sandbox',
    'pos_id' => '300746',
    'client_id' => '300746',
    'client_secret' => 'secret',
    'second_key' => '',
]);
give_payu_test_check(
    'with no second key stored, nothing verifies',
    give_payu_gateway_verify_signature($request($body, 'signature=' . md5($body) . ';algorithm=MD5')),
    false
);
