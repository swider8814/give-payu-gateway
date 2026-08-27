<?php
/**
 * Plugin Name: Give PayU Gateway
 * Plugin URI: https://github.com/swider8814/give-payu-gateway
 * Description: PayU payment gateway for GiveWP/Give donations.
 * Version: 1.0.0-rc4
 * Requires at least: 6.0
 * Requires PHP: 7.2
 * Requires Plugins: give
 * Author: Daniel Świderski
 * Author URI: https://8814.pl
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: give-payu-gateway
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Framework\Http\Response\Types\RedirectResponse;
use Give\Framework\PaymentGateways\Commands\PaymentRefunded;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\PaymentGateway;

const GIVE_PAYU_GATEWAY_OPTION = 'give_payu_gateway_options';
const GIVE_PAYU_GATEWAY_VERSION = '1.0.0-rc4';
const GIVE_PAYU_GATEWAY_TOKEN_TRANSIENT = 'give_payu_gateway_oauth_token';

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

function give_payu_gateway_render_missing_give_notice(): void
{
    if (!current_user_can('activate_plugins')) {
        return;
    }

    printf(
        '<div class="notice notice-error"><p>%s</p></div>',
        esc_html__('Give PayU Gateway requires the Give plugin to be active.', 'give-payu-gateway')
    );
}

function give_payu_gateway_filter_enabled_gateways($gateways, $form_id = 0)
{
    if (
        is_array($gateways)
        && isset($gateways['payu'])
        && function_exists('give_get_currency')
        && strtoupper((string) give_get_currency($form_id ?: null)) !== 'PLN'
    ) {
        unset($gateways['payu']);
    }

    return $gateways;
}

add_action('plugins_loaded', static function () {
    load_plugin_textdomain('give-payu-gateway', false, dirname(plugin_basename(__FILE__)) . '/languages');

    if (!give_payu_gateway_is_give_active()) {
        add_action('admin_notices', 'give_payu_gateway_render_missing_give_notice');
        return;
    }

    add_filter('give_enabled_payment_gateways', 'give_payu_gateway_filter_enabled_gateways', 10, 2);
    add_filter('givewp_donation_form_enabled_gateways', 'give_payu_gateway_filter_enabled_gateways', 10, 2);

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
            'id' => GIVE_PAYU_GATEWAY_OPTION . '[second_key]',
            'name' => give_payu_gateway_required_label(__('Second key (MD5)', 'give-payu-gateway')),
            'type' => 'password',
            'default' => '',
            'desc' => $options['second_key'] ? __('Saved. Leave as *** to keep the current key.', 'give-payu-gateway') : '',
            'attributes' => ['required' => 'required'],
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
    if (!current_user_can('manage_give_settings') && !current_user_can('manage_options')) {
        return;
    }

    $raw = isset($_POST[GIVE_PAYU_GATEWAY_OPTION]) ? wp_unslash($_POST[GIVE_PAYU_GATEWAY_OPTION]) : [];
    $options = give_payu_gateway_sanitize_options((array) $raw);

    foreach (['pos_id', 'second_key', 'client_id', 'client_secret'] as $key) {
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

function give_payu_gateway_request_token(bool $force_refresh = false)
{
    $options = give_payu_gateway_options();
    $credentials_hash = md5($options['mode'] . '|' . $options['client_id'] . '|' . $options['client_secret']);

    if (!$force_refresh) {
        $cached = get_transient(GIVE_PAYU_GATEWAY_TOKEN_TRANSIENT);
        if (is_array($cached) && ($cached['credentials'] ?? '') === $credentials_hash && !empty($cached['token'])) {
            return (string) $cached['token'];
        }
    }

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

    $token = (string) $decoded['access_token'];
    $expires_in = (int) ($decoded['expires_in'] ?? 0);
    if ($expires_in > 120) {
        set_transient(GIVE_PAYU_GATEWAY_TOKEN_TRANSIENT, [
            'credentials' => $credentials_hash,
            'token' => $token,
        ], $expires_in - 60);
    }

    return $token;
}

function give_payu_gateway_api_request(string $method, string $path, array $body = [], bool $retry_on_auth_error = true)
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

    if ($status_code === 401 && $retry_on_auth_error) {
        delete_transient(GIVE_PAYU_GATEWAY_TOKEN_TRANSIENT);
        return give_payu_gateway_api_request($method, $path, $body, false);
    }

    $raw_body = (string) wp_remote_retrieve_body($response);
    $decoded = json_decode($raw_body, true);

    if ($status_code < 200 || $status_code >= 400) {
        return new WP_Error('give_payu_gateway_http_error', 'PayU API returned an HTTP error.', [
            'statusCode' => $status_code,
            'response' => is_array($decoded) ? $decoded : $raw_body,
        ]);
    }

    $location = wp_remote_retrieve_header($response, 'location');
    if (is_array($location)) {
        $location = (string) end($location);
    }

    if (is_array($decoded)) {
        $decoded['_location'] = (string) $location;
        return $decoded;
    }

    return [
        '_location' => (string) $location,
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

    $result = give_payu_gateway_request_token(true);
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
    if (is_object($amount) && method_exists($amount, 'formatToMinorAmount')) {
        return (int) $amount->formatToMinorAmount();
    }

    $decimal = is_object($amount) && method_exists($amount, 'formatToDecimal')
        ? $amount->formatToDecimal()
        : (string) $amount;

    return (int) round(((float) $decimal) * 100);
}

function give_payu_gateway_donation_currency(Donation $donation): string
{
    $amount = $donation->amount;

    // GiveWP's Money exposes getCurrency() through __call, which method_exists() cannot see.
    if (is_object($amount) && is_callable([$amount, 'getCurrency'])) {
        try {
            $currency = $amount->getCurrency();

            if (is_object($currency) && is_callable([$currency, 'getCode'])) {
                return strtoupper((string) $currency->getCode());
            }

            if (is_string($currency)) {
                return strtoupper($currency);
            }
        } catch (Throwable $exception) {
            // Fall through to the site currency.
        }
    }

    return function_exists('give_get_currency') ? strtoupper((string) give_get_currency()) : '';
}

function give_payu_gateway_plain_url($url): string
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }

    // Give passes these URLs rawurlencoded on the form builder path and plain on the legacy path.
    $decoded = rawurldecode($url);

    return preg_match('#^https?://#i', $decoded) ? $decoded : $url;
}

function give_payu_gateway_payment_exception(string $message): Exception
{
    $exception_class = '\\Give\\Framework\\PaymentGateways\\Exceptions\\PaymentGatewayException';

    return class_exists($exception_class) ? new $exception_class($message) : new Exception($message);
}

function give_payu_gateway_parse_donation_id(string $ext_order_id): int
{
    return preg_match('/^give-([0-9]+)-/', $ext_order_id, $matches) ? (int) $matches[1] : 0;
}

function give_payu_gateway_transaction_description(Donation $donation): string
{
    $form_title = trim(wp_strip_all_tags((string) ($donation->formTitle ?? '')));

    if ($form_title !== '') {
        /* translators: %s: donation form title. */
        return mb_substr(sprintf(__('Donation - %s', 'give-payu-gateway'), $form_title), 0, 80);
    }

    /* translators: %s: donation ID. */
    return mb_substr(sprintf(__('Donation #%s', 'give-payu-gateway'), $donation->id), 0, 80);
}

function give_payu_gateway_donation_status_value(Donation $donation): string
{
    return is_object($donation->status) && method_exists($donation->status, 'getValue')
        ? (string) $donation->status->getValue()
        : (string) $donation->status;
}

function give_payu_gateway_new_webhook_lock_value(): string
{
    return time() . ':' . wp_generate_uuid4();
}

function give_payu_gateway_acquire_webhook_lock(int $donation_id, string $lock_value): bool
{
    $lock_key = '_give_payu_gateway_webhook_lock';

    if (add_post_meta($donation_id, $lock_key, $lock_value, true)) {
        // add_post_meta(..., true) is check-then-insert, not atomic; confirm this
        // request owns the winning row before proceeding.
        if ((string) get_post_meta($donation_id, $lock_key, true) === $lock_value) {
            return true;
        }

        delete_post_meta($donation_id, $lock_key, $lock_value);
        return false;
    }

    $current = (string) get_post_meta($donation_id, $lock_key, true);
    $locked_at = (int) $current;
    if ($locked_at && $locked_at < time() - 10 * MINUTE_IN_SECONDS) {
        delete_post_meta($donation_id, $lock_key, $current);

        if (add_post_meta($donation_id, $lock_key, $lock_value, true)) {
            if ((string) get_post_meta($donation_id, $lock_key, true) === $lock_value) {
                return true;
            }

            delete_post_meta($donation_id, $lock_key, $lock_value);
        }
    }

    return false;
}

function give_payu_gateway_release_webhook_lock(int $donation_id, string $lock_value): void
{
    // Value-scoped delete so a stale request cannot remove a newer owner's lock.
    delete_post_meta($donation_id, '_give_payu_gateway_webhook_lock', $lock_value);
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
    if (!give_payu_gateway_verify_signature($request)) {
        give_payu_gateway_log('Webhook rejected: invalid signature.', [], 'error');
        return new WP_REST_Response(['error' => 'Invalid signature'], 400);
    }

    // Parse the same bytes the signature covered, independent of the Content-Type header.
    $payload = json_decode((string) $request->get_body(), true);
    $payload = is_array($payload) ? $payload : [];

    if (isset($payload['refund'])) {
        return give_payu_gateway_handle_refund_notification($payload);
    }

    $order = (array) ($payload['order'] ?? []);
    $ext_order_id = (string) ($order['extOrderId'] ?? '');
    $received_order_id = sanitize_text_field((string) ($order['orderId'] ?? ''));
    $order_status = (string) ($order['status'] ?? '');

    give_payu_gateway_log('Webhook received.', [
        'extOrderId' => $ext_order_id,
        'orderId' => $received_order_id,
        'status' => $order_status,
    ]);

    $donation_id = give_payu_gateway_parse_donation_id($ext_order_id);
    $donation = $donation_id && class_exists(Donation::class) ? Donation::find($donation_id) : null;
    if (!$donation) {
        // Signed notification for a donation this site no longer has; acknowledge so PayU stops retrying.
        give_payu_gateway_log('Webhook ignored: donation not found.', ['extOrderId' => $ext_order_id], 'warning');
        return new WP_REST_Response(['status' => 'ok'], 200);
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

    if ($received_order_id === '') {
        give_payu_gateway_log('Webhook rejected: missing PayU order ID.', ['donationId' => $donation_id], 'error');
        return new WP_REST_Response(['error' => 'Missing order ID'], 400);
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
    if ($expected_order_id !== '' && !hash_equals($expected_order_id, $received_order_id)) {
        give_payu_gateway_log('Webhook rejected: order ID mismatch.', [
            'donationId' => $donation_id,
            'expectedOrderId' => $expected_order_id,
            'receivedOrderId' => $received_order_id,
        ], 'error');
        return new WP_REST_Response(['error' => 'Order ID mismatch'], 400);
    }

    $donation_status = give_payu_gateway_donation_status_value($donation);

    if ($order_status === 'CANCELED') {
        if (in_array($donation_status, [DonationStatus::PENDING, DonationStatus::PROCESSING], true)) {
            $donation->status = DonationStatus::FAILED();
            $donation->save();

            DonationNote::create([
                'donationId' => $donation_id,
                /* translators: %s: PayU order ID. */
                'content' => sprintf(__('PayU payment was cancelled or failed (PayU order %s).', 'give-payu-gateway'), $received_order_id),
            ]);

            give_payu_gateway_log('Order cancelled at PayU; donation marked as failed.', [
                'donationId' => $donation_id,
                'orderId' => $received_order_id,
            ], 'warning');
        }

        return new WP_REST_Response(['status' => 'ok'], 200);
    }

    if ($order_status !== 'COMPLETED') {
        give_payu_gateway_log('Webhook ignored: order is not completed.', [
            'donationId' => $donation_id,
            'status' => $order_status,
        ], 'warning');
        return new WP_REST_Response(['status' => 'ok'], 200);
    }

    if ($donation_status === DonationStatus::COMPLETE) {
        return new WP_REST_Response(['status' => 'ok'], 200);
    }

    if (!in_array($donation_status, [DonationStatus::PENDING, DonationStatus::PROCESSING, DonationStatus::FAILED, DonationStatus::ABANDONED], true)) {
        // Do not let a replayed COMPLETED notification overwrite refunded/cancelled donations.
        give_payu_gateway_log('Webhook ignored: donation is not in a completable state.', [
            'donationId' => $donation_id,
            'donationStatus' => $donation_status,
        ], 'warning');
        return new WP_REST_Response(['status' => 'ok'], 200);
    }

    $lock_value = give_payu_gateway_new_webhook_lock_value();
    if (!give_payu_gateway_acquire_webhook_lock($donation_id, $lock_value)) {
        // Another request is completing this donation; a non-200 response makes PayU retry later.
        return new WP_REST_Response(['error' => 'Donation is being processed'], 503);
    }

    try {
        $verified = give_payu_gateway_api_request('GET', '/api/v2_1/orders/' . rawurlencode($received_order_id));
        $verified_order = is_wp_error($verified) ? [] : (array) ($verified['orders'][0] ?? []);

        $verified_matches = (string) ($verified_order['status'] ?? '') === 'COMPLETED'
            && (string) ($verified_order['extOrderId'] ?? '') === $ext_order_id
            && (int) ($verified_order['totalAmount'] ?? 0) === $expected_amount
            && (string) ($verified_order['currencyCode'] ?? '') === 'PLN'
            && (string) ($verified_order['merchantPosId'] ?? '') === give_payu_gateway_options()['pos_id'];

        if (is_wp_error($verified) || !$verified_matches) {
            give_payu_gateway_log('Order verification failed.', [
                'donationId' => $donation_id,
                'orderId' => $received_order_id,
                'response' => is_wp_error($verified)
                    ? give_payu_gateway_error_context($verified)
                    : array_intersect_key($verified_order, array_flip(['status', 'extOrderId', 'totalAmount', 'currencyCode', 'merchantPosId'])),
            ], 'error');
            return new WP_REST_Response(['error' => 'Verification failed'], 400);
        }

        // Re-read under the lock: a concurrent request (e.g. a refund notification)
        // may have changed the donation between the guard checks and lock acquisition.
        $donation = Donation::find($donation_id);
        $donation_status = $donation ? give_payu_gateway_donation_status_value($donation) : '';
        if (!$donation || $donation_status === DonationStatus::COMPLETE) {
            return new WP_REST_Response(['status' => 'ok'], 200);
        }

        if (!in_array($donation_status, [DonationStatus::PENDING, DonationStatus::PROCESSING, DonationStatus::FAILED, DonationStatus::ABANDONED], true)) {
            give_payu_gateway_log('Webhook ignored: donation is not in a completable state.', [
                'donationId' => $donation_id,
                'donationStatus' => $donation_status,
            ], 'warning');
            return new WP_REST_Response(['status' => 'ok'], 200);
        }

        $payment_id = '';
        foreach ((array) ($payload['properties'] ?? $verified['properties'] ?? []) as $property) {
            if (($property['name'] ?? '') === 'PAYMENT_ID') {
                $payment_id = sanitize_text_field((string) ($property['value'] ?? ''));
                break;
            }
        }

        $donation->status = DonationStatus::COMPLETE();
        $donation->gatewayTransactionId = $received_order_id;
        $donation->save();

        update_post_meta($donation_id, '_give_payu_gateway_order_id', $received_order_id);
        update_post_meta($donation_id, '_give_payu_gateway_payment_id', $payment_id);

        DonationNote::create([
            'donationId' => $donation_id,
            'content' => $payment_id
                /* translators: %s: PayU payment ID. */
                ? sprintf(__('PayU payment verified (payment %s).', 'give-payu-gateway'), $payment_id)
                : __('PayU payment verified.', 'give-payu-gateway'),
        ]);

        give_payu_gateway_log('Order verified.', [
            'donationId' => $donation_id,
            'orderId' => $received_order_id,
            'paymentId' => $payment_id,
        ], 'success');

        return new WP_REST_Response(['status' => 'ok'], 200);
    } catch (Throwable $exception) {
        give_payu_gateway_log('Webhook processing failed.', [
            'donationId' => $donation_id,
            'orderId' => $received_order_id,
            'error' => $exception->getMessage(),
        ], 'error');
        return new WP_REST_Response(['error' => 'Processing failed'], 500);
    } finally {
        give_payu_gateway_release_webhook_lock($donation_id, $lock_value);
    }
}

function give_payu_gateway_handle_refund_notification(array $payload): WP_REST_Response
{
    $refund = (array) $payload['refund'];
    $ext_order_id = (string) ($payload['extOrderId'] ?? '');
    $refund_id = sanitize_text_field((string) ($refund['refundId'] ?? ''));
    $refund_status = strtoupper((string) ($refund['status'] ?? ''));

    give_payu_gateway_log('Refund notification received.', [
        'extOrderId' => $ext_order_id,
        'refundId' => $refund_id,
        'refundStatus' => $refund_status,
    ]);

    $donation_id = give_payu_gateway_parse_donation_id($ext_order_id);
    $donation = $donation_id && class_exists(Donation::class) ? Donation::find($donation_id) : null;
    if (!$donation) {
        give_payu_gateway_log('Refund notification ignored: donation not found.', ['extOrderId' => $ext_order_id], 'warning');
        return new WP_REST_Response(['status' => 'ok'], 200);
    }

    $expected_ext_order_id = (string) get_post_meta($donation_id, '_give_payu_gateway_ext_order_id', true);
    if ($expected_ext_order_id === '' || !hash_equals($expected_ext_order_id, $ext_order_id)) {
        give_payu_gateway_log('Refund notification ignored: order mismatch.', ['donationId' => $donation_id], 'warning');
        return new WP_REST_Response(['status' => 'ok'], 200);
    }

    if (!in_array($refund_status, ['FINALIZED', 'CANCELED'], true)) {
        give_payu_gateway_log('Refund notification ignored: interim refund status.', [
            'donationId' => $donation_id,
            'refundId' => $refund_id,
            'refundStatus' => $refund_status,
        ], 'warning');
        return new WP_REST_Response(['status' => 'ok'], 200);
    }

    $lock_value = give_payu_gateway_new_webhook_lock_value();
    if (!give_payu_gateway_acquire_webhook_lock($donation_id, $lock_value)) {
        // Another request is updating this donation; a non-200 response makes PayU retry later.
        return new WP_REST_Response(['error' => 'Donation is being processed'], 503);
    }

    try {
        if ($refund_id !== '' && get_post_meta($donation_id, '_give_payu_gateway_refund_' . $refund_id, true)) {
            // Redelivered notification for an already processed refund.
            return new WP_REST_Response(['status' => 'ok'], 200);
        }

        // Re-read under the lock: a concurrent webhook may have changed the donation.
        $donation = Donation::find($donation_id);
        if (!$donation) {
            return new WP_REST_Response(['status' => 'ok'], 200);
        }

        $is_full_refund = (int) ($refund['amount'] ?? 0) === give_payu_gateway_amount_to_minor($donation->amount)
            && (string) ($refund['currencyCode'] ?? '') === 'PLN';
        $donation_status = give_payu_gateway_donation_status_value($donation);
        $refund_label = $refund_id !== '' ? $refund_id : '-';

        if ($refund_status === 'CANCELED') {
            if ($is_full_refund && $donation_status === DonationStatus::REFUNDED) {
                $donation->status = DonationStatus::COMPLETE();
                $donation->save();

                DonationNote::create([
                    'donationId' => $donation_id,
                    /* translators: %s: PayU refund ID. */
                    'content' => sprintf(__('PayU refund %s was canceled. The donor was not refunded; donation status was restored to completed.', 'give-payu-gateway'), $refund_label),
                ]);
            } else {
                DonationNote::create([
                    'donationId' => $donation_id,
                    /* translators: %s: PayU refund ID. */
                    'content' => sprintf(__('PayU refund %s was canceled. The donor was not refunded; verify the donation in the PayU merchant panel.', 'give-payu-gateway'), $refund_label),
                ]);
            }

            give_payu_gateway_log('Refund canceled at PayU.', [
                'donationId' => $donation_id,
                'refundId' => $refund_id,
                'fullRefund' => $is_full_refund,
            ], 'error');
        } else {
            if ($is_full_refund && $donation_status !== DonationStatus::REFUNDED) {
                $donation->status = DonationStatus::REFUNDED();
                $donation->save();

                DonationNote::create([
                    'donationId' => $donation_id,
                    'content' => $refund_id
                        /* translators: %s: PayU refund ID. */
                        ? sprintf(__('PayU refund finalized (refund %s). Donation marked as refunded.', 'give-payu-gateway'), $refund_id)
                        : __('PayU refund finalized. Donation marked as refunded.', 'give-payu-gateway'),
                ]);
            } elseif (!$is_full_refund) {
                DonationNote::create([
                    'donationId' => $donation_id,
                    'content' => sprintf(
                        /* translators: 1: PayU refund ID, 2: refunded amount with currency. */
                        __('PayU partial refund finalized (refund %1$s, amount %2$s). Verify the donation manually.', 'give-payu-gateway'),
                        $refund_label,
                        number_format(((int) ($refund['amount'] ?? 0)) / 100, 2, '.', '') . ' ' . sanitize_text_field((string) ($refund['currencyCode'] ?? ''))
                    ),
                ]);
            }

            give_payu_gateway_log('Refund notification processed.', [
                'donationId' => $donation_id,
                'refundId' => $refund_id,
                'fullRefund' => $is_full_refund,
            ], 'success');
        }

        if ($refund_id !== '') {
            // Mark as processed only after the work is done so a mid-process crash lets PayU retry.
            add_post_meta($donation_id, '_give_payu_gateway_refund_' . $refund_id, $refund_status, true);
        }

        return new WP_REST_Response(['status' => 'ok'], 200);
    } catch (Throwable $exception) {
        give_payu_gateway_log('Refund notification processing failed.', [
            'donationId' => $donation_id,
            'refundId' => $refund_id,
            'error' => $exception->getMessage(),
        ], 'error');
        return new WP_REST_Response(['error' => 'Processing failed'], 500);
    } finally {
        give_payu_gateway_release_webhook_lock($donation_id, $lock_value);
    }
}

function give_payu_gateway_register_gateway_class(): void
{
    if (class_exists('GivePayUGateway', false) || !class_exists(PaymentGateway::class)) {
        return;
    }

    class GivePayUGateway extends PaymentGateway
    {
        public $secureRouteMethods = ['handleReturnFromPayU'];

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
            foreach (['pos_id', 'second_key', 'client_id', 'client_secret'] as $key) {
                if ($options[$key] === '') {
                    throw give_payu_gateway_payment_exception(__('PayU gateway is not configured.', 'give-payu-gateway'));
                }
            }

            $currency = give_payu_gateway_donation_currency($donation);
            if ($currency !== 'PLN') {
                give_payu_gateway_log('Order creation rejected: unsupported currency.', [
                    'donationId' => $donation->id,
                    'currency' => $currency,
                ], 'error');

                throw give_payu_gateway_payment_exception(__('PayU donations support only the PLN currency.', 'give-payu-gateway'));
            }

            $amount = give_payu_gateway_amount_to_minor($donation->amount);
            $ext_order_id = sprintf('give-%d-%s', $donation->id, wp_generate_uuid4());

            // Give builds the donation confirmation URLs (they carry the receipt key the
            // confirmation page needs), so carry them through the return route.
            $gateway_data = is_array($gatewayData) ? $gatewayData : (array) $gatewayData;
            $success_url = give_payu_gateway_plain_url($gateway_data['successUrl'] ?? '');
            $failed_url = give_payu_gateway_plain_url($gateway_data['failedUrl'] ?? ($gateway_data['cancelUrl'] ?? ''));

            $body = [
                'notifyUrl' => rest_url('give-payu-gateway/v1/status'),
                'continueUrl' => $this->generateSecureGatewayRouteUrl('handleReturnFromPayU', $donation->id, [
                    'givewp-donation-id' => $donation->id,
                    'givewp-return-url' => rawurlencode($success_url),
                    'givewp-failed-url' => rawurlencode($failed_url),
                ]),
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

                throw give_payu_gateway_payment_exception(__('PayU order creation failed.', 'give-payu-gateway'));
            }

            update_post_meta($donation->id, '_give_payu_gateway_ext_order_id', $ext_order_id);

            $created_order_id = sanitize_text_field((string) ($created['orderId'] ?? ''));
            if ($created_order_id !== '') {
                update_post_meta($donation->id, '_give_payu_gateway_order_id', $created_order_id);
            }

            give_payu_gateway_log('Order created.', [
                'donationId' => $donation->id,
                'extOrderId' => $ext_order_id,
                'amount' => $amount,
                'currency' => 'PLN',
            ], 'success');

            return new RedirectOffsite($redirect_url);
        }

        public function handleReturnFromPayU(array $queryParams)
        {
            $donation_id = (int) ($queryParams['givewp-donation-id'] ?? 0);
            $failed = !empty($queryParams['error']);

            give_payu_gateway_log('Donor returned from PayU.', [
                'donationId' => $donation_id,
                'error' => $failed ? sanitize_text_field((string) $queryParams['error']) : '',
            ], $failed ? 'warning' : 'info');

            $fallback = $failed && function_exists('give_get_failed_transaction_uri')
                ? give_get_failed_transaction_uri()
                : give_get_success_page_uri();

            $url = trim((string) ($queryParams[$failed ? 'givewp-failed-url' : 'givewp-return-url'] ?? ''));
            // Route args are not covered by the route signature, so keep the redirect on this site.
            $url = wp_validate_redirect($url !== '' ? $url : $fallback, $fallback);

            if (class_exists(RedirectResponse::class)) {
                return new RedirectResponse($url);
            }

            wp_safe_redirect($url);
            exit;
        }

        public function refundDonation(Donation $donation): PaymentRefunded
        {
            $order_id = (string) $donation->gatewayTransactionId;
            if ($order_id === '') {
                $order_id = (string) get_post_meta($donation->id, '_give_payu_gateway_order_id', true);
            }

            if ($order_id === '') {
                throw give_payu_gateway_payment_exception(
                    __('PayU order ID is missing for this donation. Refund the payment in the PayU merchant panel.', 'give-payu-gateway')
                );
            }

            $result = give_payu_gateway_api_request('POST', '/api/v2_1/orders/' . rawurlencode($order_id) . '/refunds', [
                'refund' => ['description' => __('Donation refund', 'give-payu-gateway')],
            ]);

            if (is_wp_error($result) || strtoupper((string) ($result['status']['statusCode'] ?? '')) !== 'SUCCESS') {
                give_payu_gateway_log('Refund request failed.', [
                    'donationId' => $donation->id,
                    'orderId' => $order_id,
                    'response' => is_wp_error($result) ? give_payu_gateway_error_context($result) : $result,
                ], 'error');

                throw give_payu_gateway_payment_exception(
                    __('PayU refund request failed. Refund the payment in the PayU merchant panel.', 'give-payu-gateway')
                );
            }

            $refund_id = sanitize_text_field((string) ($result['refund']['refundId'] ?? ''));

            DonationNote::create([
                'donationId' => $donation->id,
                'content' => $refund_id
                    /* translators: %s: PayU refund ID. */
                    ? sprintf(__('PayU refund initiated (refund %s).', 'give-payu-gateway'), $refund_id)
                    : __('PayU refund initiated.', 'give-payu-gateway'),
            ]);

            give_payu_gateway_log('Refund initiated.', [
                'donationId' => $donation->id,
                'orderId' => $order_id,
                'refundId' => $refund_id,
            ], 'success');

            return new PaymentRefunded();
        }
    }
}

function give_payu_gateway_customer_ip(): string
{
    // give_get_ip() resolves the real client IP behind trusted proxies/CDNs.
    $ip = function_exists('give_get_ip') ? trim((string) strtok((string) give_get_ip(), ',')) : '';

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    }

    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
}
