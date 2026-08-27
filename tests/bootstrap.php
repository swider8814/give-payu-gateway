<?php
/**
 * Test bootstrap: loads the plugin outside WordPress.
 *
 * The stubs below cover only what the plugin touches at load time or inside the
 * tested functions. WordPress and GiveWP functions whose exact behaviour the
 * plugin depends on (sanitize_text_field, wp_validate_redirect, give_clean) are
 * ported closely enough for these tests; everything else is a no-op. Keep them
 * in sync when the plugin starts relying on more of the platform.
 */

// Never runnable over HTTP: this file loads the plugin outside WordPress.
if (PHP_SAPI !== 'cli') {
    exit;
}

define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);

$GLOBALS['give_payu_test'] = [
    'options' => [],
    'home' => 'https://example.test',
    'failures' => 0,
    'checks' => 0,
];

function register_activation_hook()
{
}

function add_filter()
{
}

function add_action()
{
}

function load_plugin_textdomain()
{
}

function plugin_basename(): string
{
    return 'give-payu-gateway/give-payu-gateway.php';
}

function plugin_dir_path(string $file): string
{
    return rtrim(str_replace('\\', '/', dirname($file)), '/') . '/';
}

function plugin_dir_url(): string
{
    return 'https://example.test/wp-content/plugins/give-payu-gateway/';
}

function home_url(): string
{
    return $GLOBALS['give_payu_test']['home'];
}

function wp_parse_url(string $url, int $component = -1)
{
    return parse_url($url, $component);
}

function __(string $text): string
{
    return $text;
}

function esc_html__(string $text): string
{
    return $text;
}

function get_option(string $name, $default = false)
{
    return $GLOBALS['give_payu_test']['options'][$name] ?? $default;
}

function update_option(string $name, $value): bool
{
    $GLOBALS['give_payu_test']['options'][$name] = $value;

    return true;
}

/**
 * Port of WordPress sanitize_text_field(). The percent-encoding removal is the
 * part that matters here: it is why a URL cannot be passed through a Give
 * gateway route parameter.
 */
function sanitize_text_field($str): string
{
    $filtered = (string) $str;

    if (strpos($filtered, '<') !== false) {
        $filtered = strip_tags($filtered);
    }

    $filtered = trim(preg_replace('/[\r\n\t ]+/', ' ', $filtered));

    while (preg_match('/%[a-f0-9]{2}/i', $filtered, $match)) {
        $filtered = str_replace($match[0], '', $filtered);
    }

    return $filtered;
}

/**
 * Port of GiveWP give_clean(), applied to $_GET before a gateway route method runs.
 */
function give_clean($value)
{
    return is_array($value) ? array_map('give_clean', $value) : sanitize_text_field($value);
}

/**
 * Port of WordPress wp_sanitize_redirect().
 */
function wp_sanitize_redirect(string $location): string
{
    return preg_replace('|[^a-z0-9-~+_.?#=&;,/:%!*\[\]()@]|i', '', $location);
}

/**
 * Port of WordPress wp_validate_redirect(). Note that core allows relative
 * URLs through, which is why the plugin also requires an absolute URL.
 */
function wp_validate_redirect(string $location, string $fallback_url = '')
{
    $location = trim(wp_sanitize_redirect($location));

    if (substr($location, 0, 2) === '//') {
        $location = 'http:' . $location;
    }

    $parts = parse_url($location);
    if ($parts === false) {
        return $fallback_url;
    }

    if (isset($parts['scheme']) && !isset($parts['host'])) {
        return $fallback_url;
    }

    if (!isset($parts['host'])) {
        return $location;
    }

    $allowed = [strtolower((string) parse_url(home_url(), PHP_URL_HOST))];

    return in_array(strtolower($parts['host']), $allowed, true) ? $location : $fallback_url;
}

/**
 * Stand-in for WP_REST_Request covering the accessors the webhook uses.
 */
class WP_REST_Request
{
    private $headers = [];
    private $body = '';

    public function __construct(string $body = '', array $headers = [])
    {
        $this->body = $body;

        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
    }

    public function get_header(string $name)
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function get_body(): string
    {
        return $this->body;
    }
}

function give_payu_test_set_options(array $options): void
{
    $GLOBALS['give_payu_test']['options']['give_payu_gateway_options'] = $options;
}

function give_payu_test_section(string $title): void
{
    echo "\n-- {$title}\n";
}

function give_payu_test_check(string $label, $actual, $expected): void
{
    $GLOBALS['give_payu_test']['checks']++;
    $passed = $actual === $expected;

    if (!$passed) {
        $GLOBALS['give_payu_test']['failures']++;
    }

    printf("%s %s\n", $passed ? '[PASS]' : '[FAIL]', $label);

    if (!$passed) {
        printf(
            "       expected: %s\n       actual:   %s\n",
            var_export($expected, true),
            var_export($actual, true)
        );
    }
}

function give_payu_test_failures(): int
{
    return $GLOBALS['give_payu_test']['failures'];
}

function give_payu_test_summary(): void
{
    $failures = $GLOBALS['give_payu_test']['failures'];
    $checks = $GLOBALS['give_payu_test']['checks'];

    echo "\n";
    echo $failures === 0
        ? "ALL {$checks} CHECKS PASSED\n"
        : "{$failures} OF {$checks} CHECKS FAILED\n";
}

require dirname(__DIR__) . '/give-payu-gateway.php';
