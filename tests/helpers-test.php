<?php
/**
 * Amount conversion, order ID parsing and settings sanitization.
 */

give_payu_test_section('give_payu_gateway_amount_to_minor(): PayU expects minor units');
give_payu_test_check('a decimal string converts', give_payu_gateway_amount_to_minor('100.00'), 10000);
give_payu_test_check('a float converts', give_payu_gateway_amount_to_minor(100.5), 10050);
give_payu_test_check('the smallest amount converts', give_payu_gateway_amount_to_minor('0.01'), 1);
give_payu_test_check('a float-error prone amount converts', give_payu_gateway_amount_to_minor('19.99'), 1999);
give_payu_test_check('a whole number converts', give_payu_gateway_amount_to_minor(50), 5000);

$money_with_minor = new class {
    public function formatToMinorAmount(): int
    {
        return 12345;
    }

    public function formatToDecimal(): string
    {
        return '999.99';
    }
};
give_payu_test_check(
    "Give's exact minor amount is preferred over decimal conversion",
    give_payu_gateway_amount_to_minor($money_with_minor),
    12345
);

$money_decimal_only = new class {
    public function formatToDecimal(): string
    {
        return '123.45';
    }
};
give_payu_test_check(
    'a Money object without formatToMinorAmount falls back to its decimal value',
    give_payu_gateway_amount_to_minor($money_decimal_only),
    12345
);

give_payu_test_section('give_payu_gateway_parse_donation_id(): only the plugin\'s own extOrderId format');
give_payu_test_check(
    'the donation ID is read from the generated format',
    give_payu_gateway_parse_donation_id('give-3634-7f4a1c2e-1111-2222-3333-444455556666'),
    3634
);
give_payu_test_check('a foreign extOrderId yields no donation', give_payu_gateway_parse_donation_id('order-3634-x'), 0);
give_payu_test_check(
    'a non-numeric donation segment yields no donation',
    give_payu_gateway_parse_donation_id('give-abc-uuid'),
    0
);
give_payu_test_check(
    'a value without the trailing separator yields no donation',
    give_payu_gateway_parse_donation_id('give-3634'),
    0
);
give_payu_test_check('an empty value yields no donation', give_payu_gateway_parse_donation_id(''), 0);
give_payu_test_check(
    'a prefixed value is not accepted',
    give_payu_gateway_parse_donation_id('xgive-3634-uuid'),
    0
);

give_payu_test_section('give_payu_gateway_logo_url(): branding follows the shipped asset');
$logo_present = file_exists(dirname(__DIR__) . '/assets/img/payu.svg');
give_payu_test_check(
    $logo_present
        ? 'the logo URL is exposed while assets/img/payu.svg ships'
        : 'no logo URL while assets/img/payu.svg is absent, so Give keeps its own icon',
    give_payu_gateway_logo_url() !== '',
    $logo_present
);

give_payu_test_section('give_payu_gateway_sanitize_options(): saved credentials');
give_payu_test_set_options([
    'mode' => 'production',
    'pos_id' => '300746',
    'client_id' => '300746',
    'client_secret' => 'stored-secret',
    'second_key' => 'stored-second-key',
]);

$unchanged = give_payu_gateway_sanitize_options([
    'mode' => 'production',
    'pos_id' => '300746',
    'client_id' => '300746',
    'client_secret' => '***',
    'second_key' => '***',
]);
give_payu_test_check('the masked secret keeps the stored value', $unchanged['client_secret'], 'stored-secret');
give_payu_test_check('the masked second key keeps the stored value', $unchanged['second_key'], 'stored-second-key');

$emptied = give_payu_gateway_sanitize_options([
    'mode' => 'production',
    'pos_id' => '300746',
    'client_id' => '300746',
    'client_secret' => '',
    'second_key' => '',
]);
give_payu_test_check('an empty secret field keeps the stored value', $emptied['client_secret'], 'stored-secret');

$replaced = give_payu_gateway_sanitize_options([
    'mode' => 'sandbox',
    'pos_id' => ' 12 34 ',
    'client_id' => '300746abc',
    'client_secret' => 'new-secret',
    'second_key' => 'new-second-key',
]);
give_payu_test_check('a new secret replaces the stored value', $replaced['client_secret'], 'new-secret');
give_payu_test_check('non-digits are stripped from the POS ID', $replaced['pos_id'], '1234');
give_payu_test_check('non-digits are stripped from the client ID', $replaced['client_id'], '300746');
give_payu_test_check('the mode is taken from the whitelist', $replaced['mode'], 'sandbox');

$unknown_mode = give_payu_gateway_sanitize_options(['mode' => 'staging']);
give_payu_test_check('an unknown mode falls back to sandbox', $unknown_mode['mode'], 'sandbox');
