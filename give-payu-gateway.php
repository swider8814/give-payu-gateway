<?php
/**
 * Plugin Name: Give PayU Gateway
 * Description: PayU payment gateway for GiveWP/Give donations.
 * Version: 1.0.0-rc1
 * Requires at least: 6.0
 * Requires PHP: 7.2
 * Requires Plugins: give
 * Author: Daniel Świderski
 * Author URI: https://8814.pl
 * Text Domain: give-payu-gateway
 */

if (!defined('ABSPATH')) {
    exit;
}

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Framework\PaymentGateways\Commands\PaymentRefunded;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\PaymentGateway;

const GIVE_PAYU_GATEWAY_OPTION = 'give_payu_gateway_options';
const GIVE_PAYU_GATEWAY_VERSION = '1.0.0-rc1';

register_activation_hook(__FILE__, 'give_payu_gateway_activate');
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'give_payu_gateway_plugin_action_links');

function give_payu_gateway_is_give_active(): bool
{
    return class_exists('Give') || function_exists('Give') || defined('GIVE_VERSION');
}

function give_payu_gateway_activate(): void
{
    if (give_payu_gateway_is_give_active()) {
        return;
    }

    deactivate_plugins(plugin_basename(__FILE__));
    wp_die(
        esc_html__('Give PayU Gateway requires the Give plugin to be active.', 'give-payu-gateway'),
        esc_html__('Plugin dependency missing', 'give-payu-gateway'),
        ['back_link' => true]
    );
}

function give_payu_gateway_plugin_action_links(array $links): array
{
    array_unshift(
        $links,
        sprintf('<a href="%s">%s</a>', esc_url(give_payu_gateway_settings_url()), esc_html__('Settings', 'give-payu-gateway'))
    );

    return $links;
}

add_action('plugins_loaded', static function () {
    load_plugin_textdomain('give-payu-gateway', false, dirname(plugin_basename(__FILE__)) . '/languages');

    if (!give_payu_gateway_is_give_active()) {
        return;
    }

    add_filter('give_get_sections_gateways', static function (array $sections): array {
        $sections['payu'] = __('PayU', 'give-payu-gateway');
        return $sections;
    });

    add_filter('give_get_settings_gateways', static function (array $settings): array {
        return function_exists('give_get_current_setting_section') && give_get_current_setting_section() === 'payu'
            ? give_payu_gateway_give_settings()
            : $settings;
    });

    add_filter('give_admin_field_get_value', 'give_payu_gateway_get_give_setting_value', 10, 4);
    add_filter('give_admin_settings_sanitize_option_' . GIVE_PAYU_GATEWAY_OPTION, 'give_payu_gateway_sanitize_give_setting_value', 10, 3);
    add_filter('give_save_options_gateways_payu', '__return_false');
    add_action('give_update_options_gateways_payu', 'give_payu_gateway_save_give_settings');
    add_action('admin_init', 'give_payu_gateway_handle_test_access');
    add_action('give_admin_field_give_payu_gateway_test_access', 'give_payu_gateway_render_test_access_field', 10, 2);

    add_action('rest_api_init', static function () {
        register_rest_route('give-payu-gateway/v1', '/status', [
            'methods' => 'POST',
            'callback' => 'give_payu_gateway_handle_status',
            'permission_callback' => '__return_true',
        ]);
    });
});

function give_payu_gateway_default_options(): array
{
    return [
        'mode' => 'sandbox',
        'pos_id' => '',
        'client_id' => '',
        'client_secret' => '',
        'second_key' => '',
    ];
}

function give_payu_gateway_options(): array
{
    return array_merge(give_payu_gateway_default_options(), (array) get_option(GIVE_PAYU_GATEWAY_OPTION, []));
}

function give_payu_gateway_sanitize_options($input): array
{
    $input = (array) $input;
    $current = give_payu_gateway_options();

    return [
        'mode' => (($input['mode'] ?? 'sandbox') === 'production') ? 'production' : 'sandbox',
        'pos_id' => preg_replace('/\D+/', '', (string) ($input['pos_id'] ?? '')),
        'client_id' => preg_replace('/\D+/', '', (string) ($input['client_id'] ?? '')),
        'client_secret' => in_array(($input['client_secret'] ?? ''), ['', '***'], true) ? $current['client_secret'] : sanitize_text_field($input['client_secret']),
        'second_key' => in_array(($input['second_key'] ?? ''), ['', '***'], true) ? $current['second_key'] : sanitize_text_field($input['second_key']),
    ];
}

function give_payu_gateway_give_settings(): array
{
    $options = give_payu_gateway_options();

    return [
        [
            'id' => 'give_payu_gateway_settings',
            'type' => 'title',
            'title' => __('PayU Settings', 'give-payu-gateway'),
            'desc' => __('Configure PayU credentials for sandbox or production payments.', 'give-payu-gateway'),
        ],
        [
            'id' => GIVE_PAYU_GATEWAY_OPTION . '[mode]',
            'name' => __('Mode', 'give-payu-gateway'),
            'type' => 'select',
            'default' => $options['mode'],
            'options' => [
                'sandbox' => __('Sandbox', 'give-payu-gateway'),
                'production' => __('Production', 'give-payu-gateway'),
            ],
        ],
        [
            'id' => GIVE_PAYU_GATEWAY_OPTION . '[pos_id]',
            'name' => give_payu_gateway_required_label(__('POS ID', 'give-payu-gateway')),
            'type' => 'text',
            'default' => $options['pos_id'],
            'attributes' => ['inputmode' => 'numeric', 'required' => 'required'],
        ],
        [
            'id' => GIVE_PAYU_GATEWAY_OPTION . '[client_id]',
            'name' => give_payu_gateway_required_label(__('OAuth client ID', 'give-payu-gateway')),
            'type' => 'text',
            'default' => $options['client_id'],
            'attributes' => ['inputmode' => 'numeric', 'required' => 'required'],
        ],
        [
            'id' => GIVE_PAYU_GATEWAY_OPTION . '[client_secret]',
            'name' => give_payu_gateway_required_label(__('OAuth client secret', 'give-payu-gateway')),
            'type' => 'password',
            'default' => '',
            'desc' => $options['client_secret'] ? __('Saved. Leave as *** to keep the current key.', 'give-payu-gateway') : '',
            'attributes' => ['required' => 'required'],
        ],
        [
            'id' => GIVE_PAYU_GATEWAY_OPTION . '[second_key]',
            'name' => give_payu_gateway_required_label(__('Second key (MD5)', 'give-payu-gateway')),
            'type' => 'password',
            'default' => '',
            'desc' => $options['second_key'] ? __('Saved. Leave as *** to keep the current key.', 'give-payu-gateway') : '',
            'attributes' => ['required' => 'required'],
        ],
        [
            'id' => 'give_payu_gateway_test_access',
            'name' => __('Test connection', 'give-payu-gateway'),
            'type' => 'give_payu_gateway_test_access',
        ],
        [
            'id' => 'give_payu_gateway_settings',
            'type' => 'sectionend',
        ],
    ];
}

function give_payu_gateway_required_label(string $label): string
{
    return sprintf(
        '%s <span class="give-required-indicator" aria-hidden="true">*</span><span class="screen-reader-text">%s</span>',
        esc_html($label),
        esc_html__('required', 'give-payu-gateway')
    );
}

function give_payu_gateway_get_give_setting_value($value, string $option_name, string $field_id, $default)
{
    if (preg_match('/^' . preg_quote(GIVE_PAYU_GATEWAY_OPTION, '/') . '\[([a-z_]+)\]$/', $field_id, $matches)) {
        $options = give_payu_gateway_options();
        return in_array($matches[1], ['client_secret', 'second_key'], true)
            ? ($options[$matches[1]] !== '' ? '***' : '')
            : ($options[$matches[1]] ?? $default);
    }

    return $value;
}

function give_payu_gateway_sanitize_give_setting_value($value, array $option, $raw_value)
{
    if (empty($option['id']) || !preg_match('/^' . preg_quote(GIVE_PAYU_GATEWAY_OPTION, '/') . '\[([a-z_]+)\]$/', $option['id'], $matches)) {
        return $value;
    }

    $key = $matches[1];
    $current = give_payu_gateway_options();

    if ($key === 'mode') {
        return $raw_value === 'production' ? 'production' : 'sandbox';
    }

    if (in_array($key, ['pos_id', 'client_id'], true)) {
        return preg_replace('/\D+/', '', (string) $raw_value);
    }

    if (in_array($key, ['client_secret', 'second_key'], true)) {
        return ($raw_value === '' || $raw_value === '***') ? $current[$key] : sanitize_text_field((string) $raw_value);
    }

    return null;
}

function give_payu_gateway_save_give_settings(): void
{
    $raw = isset($_POST[GIVE_PAYU_GATEWAY_OPTION]) ? wp_unslash($_POST[GIVE_PAYU_GATEWAY_OPTION]) : [];
    $options = give_payu_gateway_sanitize_options((array) $raw);

    foreach (['pos_id', 'client_id', 'client_secret', 'second_key'] as $key) {
        if ($options[$key] === '') {
            Give_Admin_Settings::add_error(
                'give-payu-gateway-required-fields',
                __('PayU settings were not saved. All PayU fields are required.', 'give-payu-gateway')
            );
            return;
        }
    }

    update_option(GIVE_PAYU_GATEWAY_OPTION, $options, false);
}

add_action('givewp_register_payment_gateway', static function ($registrar) {
    give_payu_gateway_register_gateway_class();

    if (class_exists('GivePayUGateway')) {
        $registrar->registerGateway(GivePayUGateway::class);
    }
});

function give_payu_gateway_base_url(): string
{
    return give_payu_gateway_options()['mode'] === 'production'
        ? 'https://secure.payu.com'
        : 'https://secure.snd.payu.com';
}

function give_payu_gateway_request_token()
{
    $options = give_payu_gateway_options();
    $response = wp_remote_post(give_payu_gateway_base_url() . '/pl/standard/user/oauth/authorize', [
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        'timeout' => 20,
        'body' => [
            'grant_type' => 'client_credentials',
            'client_id' => $options['client_id'],
            'client_secret' => $options['client_secret'],
        ],
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
    if (wp_remote_retrieve_response_code($response) !== 200 || empty($decoded['access_token'])) {
        return new WP_Error('give_payu_gateway_oauth_error', 'PayU OAuth request failed.', [
            'statusCode' => wp_remote_retrieve_response_code($response),
            'response' => is_array($decoded) ? $decoded : wp_remote_retrieve_body($response),
        ]);
    }

    return (string) $decoded['access_token'];
}

function give_payu_gateway_api_request(string $method, string $path, array $body = [])
{
    $token = give_payu_gateway_request_token();
    if (is_wp_error($token)) {
        return $token;
    }

    $args = [
        'method' => $method,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ],
        'timeout' => 20,
        'redirection' => 0,
    ];

    if ($body) {
        $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $response = wp_remote_request(give_payu_gateway_base_url() . $path, $args);
    if (is_wp_error($response)) {
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $raw_body = (string) wp_remote_retrieve_body($response);
    $decoded = json_decode($raw_body, true);

    if ($status_code < 200 || $status_code >= 400) {
        return new WP_Error('give_payu_gateway_http_error', 'PayU API returned an HTTP error.', [
            'statusCode' => $status_code,
            'response' => is_array($decoded) ? $decoded : $raw_body,
        ]);
    }

    if (is_array($decoded)) {
        $decoded['_location'] = wp_remote_retrieve_header($response, 'location');
        return $decoded;
    }

    return [
        '_location' => wp_remote_retrieve_header($response, 'location'),
        '_raw' => $raw_body,
    ];
}

function give_payu_gateway_error_context(WP_Error $error): array
{
    return [
        'message' => $error->get_error_message(),
        'data' => $error->get_error_data(),
    ];
}

function give_payu_gateway_handle_test_access(): void
{
    if (
        !is_admin()
        || !current_user_can('manage_options')
        || empty($_GET['give_payu_gateway_test_access'])
        || empty($_GET['_wpnonce'])
        || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'give_payu_gateway_test_access')
    ) {
        return;
    }

    $result = give_payu_gateway_request_token();
    $status = is_wp_error($result) ? 'failed' : 'success';

    give_payu_gateway_log('Test access result.', [
        'status' => $status,
        'response' => is_wp_error($result) ? give_payu_gateway_error_context($result) : ['token' => 'ok'],
    ], $status === 'success' ? 'success' : 'warning');

    wp_safe_redirect(add_query_arg('give_payu_gateway_test_access_result', $status, give_payu_gateway_settings_url()));
    exit;
}

function give_payu_gateway_render_test_access_field(array $field, $settings = null): void
{
    $result = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['give_payu_gateway_test_access_result'])
        ? sanitize_key(wp_unslash($_GET['give_payu_gateway_test_access_result']))
        : '';
    $url = wp_nonce_url(add_query_arg('give_payu_gateway_test_access', '1', give_payu_gateway_settings_url()), 'give_payu_gateway_test_access');
    ?>
    <tr valign="top">
        <th scope="row" class="titledesc"><?php echo wp_kses_post($field['name']); ?></th>
        <td class="give-forminp give-forminp-<?php echo esc_attr($field['type']); ?>">
            <a class="button-secondary" href="<?php echo esc_url($url); ?>"><?php esc_html_e('Test PayU API access', 'give-payu-gateway'); ?></a>
            <?php if ($result === 'success') : ?>
                <p class="give-field-description" style="color:#2271b1;"><?php esc_html_e('Connection successful.', 'give-payu-gateway'); ?></p>
            <?php elseif ($result === 'failed') : ?>
                <p class="give-field-description" style="color:#b32d2e;"><?php esc_html_e('Connection failed. Check mode and OAuth credentials.', 'give-payu-gateway'); ?></p>
            <?php endif; ?>
        </td>
    </tr>
    <?php
}

function give_payu_gateway_settings_url(): string
{
    return add_query_arg(
        [
            'post_type' => 'give_forms',
            'page' => 'give-settings',
            'tab' => 'gateways',
            'section' => 'payu',
        ],
        admin_url('edit.php')
    );
}

function give_payu_gateway_log(string $message, array $context = [], string $type = 'info'): void
{
    $line = $message . ($context ? ' ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '');

    if (class_exists('\Give\Log\LogFactory') && in_array($type, ['error', 'warning', 'notice', 'success', 'info', 'debug'], true)) {
        \Give\Log\LogFactory::make($type, $message, 'Payment', 'PayU', $context)->save();
        return;
    }

    if (function_exists('give_record_log')) {
        give_record_log('PayU', $line, 0, $type);
        return;
    }

    error_log('[Give PayU] ' . $line);
}

function give_payu_gateway_amount_to_minor($amount): int
{
    $decimal = is_object($amount) && method_exists($amount, 'formatToDecimal')
        ? $amount->formatToDecimal()
        : (string) $amount;

    return (int) round(((float) $decimal) * 100);
}

function give_payu_gateway_parse_donation_id(string $ext_order_id): int
{
    return preg_match('/^give-([0-9]+)-/', $ext_order_id, $matches) ? (int) $matches[1] : 0;
}

function give_payu_gateway_transaction_description(Donation $donation): string
{
    $form_title = trim(wp_strip_all_tags((string) ($donation->formTitle ?? '')));

    if ($form_title !== '') {
        return mb_substr(sprintf(__('Donation - %s', 'give-payu-gateway'), $form_title), 0, 80);
    }

    return mb_substr(sprintf(__('Donation #%s', 'give-payu-gateway'), $donation->id), 0, 80);
}

function give_payu_gateway_donation_status_value(Donation $donation): string
{
    return is_object($donation->status) && method_exists($donation->status, 'getValue')
        ? (string) $donation->status->getValue()
        : (string) $donation->status;
}

function give_payu_gateway_acquire_webhook_lock(int $donation_id): bool
{
    $lock_key = '_give_payu_gateway_webhook_lock';

    if (add_post_meta($donation_id, $lock_key, time(), true)) {
        return true;
    }

    $locked_at = (int) get_post_meta($donation_id, $lock_key, true);
    if ($locked_at && $locked_at < time() - 10 * MINUTE_IN_SECONDS) {
        delete_post_meta($donation_id, $lock_key);
        return add_post_meta($donation_id, $lock_key, time(), true);
    }

    return false;
}

function give_payu_gateway_verify_signature(WP_REST_Request $request): bool
{
    $header = $request->get_header('openpayu-signature') ?: $request->get_header('x-openpayu-signature');
    if (!$header) {
        return false;
    }

    $parts = [];
    foreach (explode(';', $header) as $part) {
        $pair = array_map('trim', explode('=', $part, 2));
        if (count($pair) === 2) {
            $parts[strtolower($pair[0])] = $pair[1];
        }
    }

    $signature = strtolower((string) ($parts['signature'] ?? ''));
    if (
        give_payu_gateway_options()['second_key'] === ''
        || strtoupper((string) ($parts['algorithm'] ?? '')) !== 'MD5'
        || strlen($signature) !== 32
        || !ctype_xdigit($signature)
    ) {
        return false;
    }

    $expected = md5($request->get_body() . give_payu_gateway_options()['second_key']);
    return hash_equals($expected, $signature);
}

function give_payu_gateway_handle_status(WP_REST_Request $request): WP_REST_Response
{
    $payload = (array) $request->get_json_params();
    $order = (array) ($payload['order'] ?? []);

    give_payu_gateway_log('Webhook received.', [
        'extOrderId' => (string) ($order['extOrderId'] ?? ''),
        'orderId' => (string) ($order['orderId'] ?? ''),
        'status' => (string) ($order['status'] ?? ''),
    ]);

    if (!give_payu_gateway_verify_signature($request)) {
        give_payu_gateway_log('Webhook rejected: invalid signature.', [], 'error');
        return new WP_REST_Response(['error' => 'Invalid signature'], 400);
    }

    $ext_order_id = (string) ($order['extOrderId'] ?? '');
    $donation_id = give_payu_gateway_parse_donation_id($ext_order_id);
    if (!$donation_id || !class_exists(Donation::class)) {
        return new WP_REST_Response(['error' => 'Donation not found'], 404);
    }

    $donation = Donation::find($donation_id);
    if (!$donation) {
        return new WP_REST_Response(['error' => 'Donation not found'], 404);
    }

    $expected_ext_order_id = (string) get_post_meta($donation_id, '_give_payu_gateway_ext_order_id', true);
    if ($expected_ext_order_id === '' || !hash_equals($expected_ext_order_id, $ext_order_id)) {
        give_payu_gateway_log('Webhook rejected: order mismatch.', [
            'donationId' => $donation_id,
            'expectedExtOrderId' => $expected_ext_order_id,
            'receivedExtOrderId' => $ext_order_id,
        ], 'error');
        return new WP_REST_Response(['error' => 'Order mismatch'], 400);
    }

    $expected_amount = give_payu_gateway_amount_to_minor($donation->amount);
    if ((int) ($order['totalAmount'] ?? 0) !== $expected_amount || (string) ($order['currencyCode'] ?? '') !== 'PLN') {
        give_payu_gateway_log('Webhook rejected: amount or currency mismatch.', [
            'donationId' => $donation_id,
            'expectedAmount' => $expected_amount,
            'receivedAmount' => (int) ($order['totalAmount'] ?? 0),
            'receivedCurrency' => (string) ($order['currencyCode'] ?? ''),
        ], 'error');
        return new WP_REST_Response(['error' => 'Amount or currency mismatch'], 400);
    }

    if ((string) ($order['merchantPosId'] ?? '') !== give_payu_gateway_options()['pos_id']) {
        give_payu_gateway_log('Webhook rejected: POS mismatch.', [
            'donationId' => $donation_id,
            'receivedPosId' => (string) ($order['merchantPosId'] ?? ''),
        ], 'error');
        return new WP_REST_Response(['error' => 'POS mismatch'], 400);
    }

    $expected_order_id = (string) get_post_meta($donation_id, '_give_payu_gateway_order_id', true);
    if ($expected_order_id !== '' && !hash_equals($expected_order_id, (string) ($order['orderId'] ?? ''))) {
        give_payu_gateway_log('Webhook rejected: order ID mismatch.', [
            'donationId' => $donation_id,
            'expectedOrderId' => $expected_order_id,
            'receivedOrderId' => (string) ($order['orderId'] ?? ''),
        ], 'error');
        return new WP_REST_Response(['error' => 'Order ID mismatch'], 400);
    }

    if ((string) ($order['status'] ?? '') !== 'COMPLETED') {
        give_payu_gateway_log('Webhook ignored: order is not completed.', [
            'donationId' => $donation_id,
            'status' => (string) ($order['status'] ?? ''),
        ], 'warning');
        return new WP_REST_Response(['status' => 'ok'], 200);
    }

    if (give_payu_gateway_donation_status_value($donation) === DonationStatus::COMPLETE) {
        return new WP_REST_Response(['status' => 'ok'], 200);
    }

    if (!give_payu_gateway_acquire_webhook_lock($donation_id)) {
        return new WP_REST_Response(['status' => 'ok'], 200);
    }

    $verified = give_payu_gateway_api_request('GET', '/api/v2_1/orders/' . rawurlencode((string) $order['orderId']));
    $verified_order = is_wp_error($verified) ? [] : (array) ($verified['orders'][0] ?? []);

    $verified_matches = (string) ($verified_order['status'] ?? '') === 'COMPLETED'
        && (string) ($verified_order['extOrderId'] ?? '') === $ext_order_id
        && (int) ($verified_order['totalAmount'] ?? 0) === $expected_amount
        && (string) ($verified_order['currencyCode'] ?? '') === 'PLN'
        && (string) ($verified_order['merchantPosId'] ?? '') === give_payu_gateway_options()['pos_id'];

    if (is_wp_error($verified) || !$verified_matches) {
        give_payu_gateway_log('Order verification failed.', [
            'donationId' => $donation_id,
            'orderId' => (string) ($order['orderId'] ?? ''),
            'response' => is_wp_error($verified) ? give_payu_gateway_error_context($verified) : $verified,
        ], 'error');
        delete_post_meta($donation_id, '_give_payu_gateway_webhook_lock');
        return new WP_REST_Response(['error' => 'Verification failed'], 400);
    }

    $payment_id = '';
    foreach ((array) ($payload['properties'] ?? $verified['properties'] ?? []) as $property) {
        if (($property['name'] ?? '') === 'PAYMENT_ID') {
            $payment_id = sanitize_text_field((string) ($property['value'] ?? ''));
            break;
        }
    }

    $donation->status = DonationStatus::COMPLETE();
    $donation->gatewayTransactionId = (string) $order['orderId'];
    $donation->save();

    update_post_meta($donation_id, '_give_payu_gateway_order_id', (string) $order['orderId']);
    update_post_meta($donation_id, '_give_payu_gateway_payment_id', $payment_id);
    delete_post_meta($donation_id, '_give_payu_gateway_webhook_lock');

    DonationNote::create([
        'donationId' => $donation_id,
        'content' => $payment_id
            ? sprintf(__('PayU payment verified (payment %s).', 'give-payu-gateway'), $payment_id)
            : __('PayU payment verified.', 'give-payu-gateway'),
    ]);

    give_payu_gateway_log('Order verified.', [
        'donationId' => $donation_id,
        'orderId' => (string) $order['orderId'],
        'paymentId' => $payment_id,
    ], 'success');

    return new WP_REST_Response(['status' => 'ok'], 200);
}

function give_payu_gateway_register_gateway_class(): void
{
    if (class_exists('GivePayUGateway', false) || !class_exists(PaymentGateway::class)) {
        return;
    }

    class GivePayUGateway extends PaymentGateway
    {
        public static function id(): string
        {
            return 'payu';
        }

        public function getId(): string
        {
            return self::id();
        }

        public function getName(): string
        {
            return __('PayU', 'give-payu-gateway');
        }

        public function getPaymentMethodLabel(): string
        {
            return __('PayU', 'give-payu-gateway');
        }

        public function enqueueScript(int $formId)
        {
            wp_enqueue_script(
                'give-payu-gateway',
                plugin_dir_url(__FILE__) . 'assets/js/give-payu-gateway.js',
                ['react', 'wp-element'],
                GIVE_PAYU_GATEWAY_VERSION,
                true
            );
        }

        public function formSettings(int $formId): array
        {
            return [
                'message' => __('You will be redirected to PayU to complete the donation.', 'give-payu-gateway'),
            ];
        }

        public function getLegacyFormFieldMarkup(int $formId, array $args): string
        {
            return '<div class="give-payu-gateway-help-text"><p>' . esc_html__('You will be redirected to PayU to complete the donation.', 'give-payu-gateway') . '</p></div>';
        }

        public function createPayment(Donation $donation, $gatewayData)
        {
            $options = give_payu_gateway_options();
            foreach (['pos_id', 'client_id', 'client_secret', 'second_key'] as $key) {
                if ($options[$key] === '') {
                    throw new Exception(__('PayU gateway is not configured.', 'give-payu-gateway'));
                }
            }

            $amount = give_payu_gateway_amount_to_minor($donation->amount);
            $ext_order_id = sprintf('give-%d-%s', $donation->id, wp_generate_uuid4());
            $name = trim((string) $donation->firstName . ' ' . (string) $donation->lastName);

            $body = [
                'notifyUrl' => rest_url('give-payu-gateway/v1/status'),
                'continueUrl' => give_get_success_page_uri(),
                'customerIp' => give_payu_gateway_customer_ip(),
                'merchantPosId' => $options['pos_id'],
                'description' => give_payu_gateway_transaction_description($donation),
                'currencyCode' => 'PLN',
                'totalAmount' => (string) $amount,
                'extOrderId' => $ext_order_id,
                'buyer' => [
                    'email' => (string) $donation->email,
                    'firstName' => (string) $donation->firstName,
                    'lastName' => (string) $donation->lastName,
                    'language' => 'pl',
                ],
                'products' => [
                    [
                        'name' => give_payu_gateway_transaction_description($donation),
                        'unitPrice' => (string) $amount,
                        'quantity' => '1',
                    ],
                ],
            ];

            $created = give_payu_gateway_api_request('POST', '/api/v2_1/orders', $body);
            $redirect_url = is_wp_error($created) ? '' : (string) ($created['redirectUri'] ?? $created['_location'] ?? '');

            if (is_wp_error($created) || $redirect_url === '') {
                give_payu_gateway_log('Order creation failed.', [
                    'donationId' => $donation->id,
                    'extOrderId' => $ext_order_id,
                    'response' => is_wp_error($created) ? give_payu_gateway_error_context($created) : $created,
                ], 'error');

                throw new Exception(__('PayU order creation failed.', 'give-payu-gateway'));
            }

            update_post_meta($donation->id, '_give_payu_gateway_ext_order_id', $ext_order_id);
            update_post_meta($donation->id, '_give_payu_gateway_order_id', sanitize_text_field((string) ($created['orderId'] ?? '')));

            give_payu_gateway_log('Order created.', [
                'donationId' => $donation->id,
                'extOrderId' => $ext_order_id,
                'amount' => $amount,
                'currency' => 'PLN',
            ], 'success');

            return new RedirectOffsite($redirect_url);
        }

        public function refundDonation(Donation $donation): PaymentRefunded
        {
            return new PaymentRefunded();
        }
    }
}

function give_payu_gateway_customer_ip(): string
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '127.0.0.1';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
}
