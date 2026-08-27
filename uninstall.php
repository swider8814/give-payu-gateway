<?php
/**
 * Uninstall cleanup for Give PayU Gateway.
 *
 * PayU order identifiers stored on donations (_give_payu_gateway_ext_order_id,
 * _give_payu_gateway_order_id, _give_payu_gateway_payment_id) and processed
 * refund markers (_give_payu_gateway_refund_*) are kept on purpose: they are
 * part of the donation payment records.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('give_payu_gateway_options');
delete_transient('give_payu_gateway_oauth_token');
delete_post_meta_by_key('_give_payu_gateway_webhook_lock');
