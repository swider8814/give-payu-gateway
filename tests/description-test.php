<?php
/**
 * The payment description PayU shows the donor and puts in its confirmation email.
 *
 * The opening words come from the "Payment description prefix" setting; with the
 * setting empty the plugin keeps its own translated wording.
 */

use Give\Donations\Models\Donation;

$donation = static function (array $attributes): Donation {
    return new Donation($attributes);
};

$with_prefix = static function (string $prefix): void {
    give_payu_test_set_options(array_merge(give_payu_gateway_default_options(), [
        'pos_id' => '300746',
        'description_prefix' => $prefix,
    ]));
};

give_payu_test_section('give_payu_gateway_transaction_description(): configured prefix');
$with_prefix('SCh Tomy');
give_payu_test_check(
    'the prefix opens the description, followed by the form title',
    give_payu_gateway_transaction_description($donation([
        'id' => 3634,
        'formTitle' => 'Darowizna na cele kultu religijnego',
    ])),
    'SCh Tomy - Darowizna na cele kultu religijnego'
);
give_payu_test_check(
    'without a form title the donation ID is appended instead',
    give_payu_gateway_transaction_description($donation(['id' => 3634, 'formTitle' => ''])),
    'SCh Tomy #3634'
);
give_payu_test_check(
    'markup in the form title is stripped',
    give_payu_gateway_transaction_description($donation([
        'id' => 1,
        'formTitle' => 'Wsparcie <strong>parafii</strong>',
    ])),
    'SCh Tomy - Wsparcie parafii'
);

give_payu_test_section('give_payu_gateway_transaction_description(): no prefix configured');
$with_prefix('');
give_payu_test_check(
    'the translated default wording is used with the form title',
    give_payu_gateway_transaction_description($donation(['id' => 3634, 'formTitle' => 'Kult religijny'])),
    'Donation - Kult religijny'
);
give_payu_test_check(
    'the translated default wording is used with the donation ID',
    give_payu_gateway_transaction_description($donation(['id' => 3634, 'formTitle' => ''])),
    'Donation #3634'
);

give_payu_test_section('give_payu_gateway_transaction_description(): PayU length limit');
$with_prefix('SCh Tomy');
$long = give_payu_gateway_transaction_description($donation([
    'id' => 1,
    'formTitle' => str_repeat('Bardzo długi tytuł formularza ', 10),
]));
give_payu_test_check('the description is capped at 80 characters', mb_strlen($long), 80);
give_payu_test_check(
    'the prefix survives the truncation',
    mb_substr($long, 0, 11),
    'SCh Tomy - '
);
give_payu_test_check(
    'multibyte characters are counted, not bytes',
    mb_strlen(give_payu_gateway_transaction_description($donation([
        'id' => 1,
        'formTitle' => str_repeat('ąćęłńóśźż', 20),
    ]))),
    80
);

give_payu_test_section('give_payu_gateway_sanitize_description_prefix(): stored value');
give_payu_test_check(
    'surrounding whitespace is trimmed',
    give_payu_gateway_sanitize_description_prefix('  SCh Tomy  '),
    'SCh Tomy'
);
give_payu_test_check(
    'markup is stripped',
    give_payu_gateway_sanitize_description_prefix('<b>SCh Tomy</b>'),
    'SCh Tomy'
);
give_payu_test_check(
    'the prefix is capped so the form title still fits',
    mb_strlen(give_payu_gateway_sanitize_description_prefix(str_repeat('x', 200))),
    60
);
give_payu_test_check(
    'an empty prefix stays empty',
    give_payu_gateway_sanitize_description_prefix(''),
    ''
);
give_payu_test_check(
    'a null prefix becomes an empty string',
    give_payu_gateway_sanitize_description_prefix(null),
    ''
);
