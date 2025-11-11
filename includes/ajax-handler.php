<?php
if (!defined('ABSPATH')) { exit; }

/** Adjustable lookback window (days) */
if (!defined('WC_BINANCE_PAY_LOOKBACK_DAYS'))  define('WC_BINANCE_PAY_LOOKBACK_DAYS', 1);

/** Normalize a Binance Pay row into a stable shape */
if (!function_exists('wc_bp_normalize_pay_row')) :
function wc_bp_normalize_pay_row(array $it) {
    $status = isset($it['status']) ? (string)$it['status'] : '';
    $type   = isset($it['type']) ? (string)$it['type'] : (isset($it['transactType']) ? (string)$it['transactType'] : '');
    $cur    = isset($it['currency']) ? (string)$it['currency'] : (isset($it['asset']) ? (string)$it['asset'] : '');
    $note   = isset($it['note']) ? (string)$it['note'] : (isset($it['remark']) ? (string)$it['remark'] : (isset($it['comment']) ? (string)$it['comment'] : ''));
    $amt    = isset($it['totalAmount']) ? (float)$it['totalAmount'] : (isset($it['amount']) ? (float)$it['amount'] : 0.0);

    // Prefer transactionTime (ms); fall back to transactedAt/time
    $ts     = isset($it['transactionTime']) ? (int)$it['transactionTime']
            : (isset($it['transactedAt']) ? (int)$it['transactedAt']
            : (isset($it['time']) ? (int)$it['time'] : 0));

    $txid   = isset($it['transactionId']) ? (string)$it['transactionId'] : (isset($it['bizId']) ? (string)$it['bizId'] : '');
    $ot     = isset($it['orderType']) ? (string)$it['orderType'] : '';

    return array(
        'orderType' => $ot,
        'type'      => $type,
        'status'    => $status,
        'currency'  => strtoupper(trim($cur)),
        'note'      => (string)$note,
        'amount'    => (float)$amt,
        'time'      => (int)$ts,
        'txid'      => (string)$txid,
        'raw'       => $it,
    );
}
endif;

/** Allowed directions / statuses (permissive) */
if (!function_exists('wc_bp_is_allowed_dir')) :
function wc_bp_is_allowed_dir($type_u) {
    static $ok = array('RECEIVE','IN','INCOMING','PAYMENT_RECEIVED','COLLECT');
    return in_array(strtoupper($type_u), $ok, true);
}
endif;
if (!function_exists('wc_bp_is_allowed_status')) :
function wc_bp_is_allowed_status($status_u) {
    static $ok = array('SUCCESS','COMPLETED','PAID','PAID_SUCCESS','SUCCESSFUL');
    return in_array(strtoupper($status_u), $ok, true);
}
endif;

/** ========== Frontend: order self-check (Binance Pay only; C2C-compatible) ========== */
if (!function_exists('wc_binance_handle_payment_check')) :
function wc_binance_handle_payment_check() {
    nocache_headers();
    header('X-WC-Binance-Ajax: 1');

    check_ajax_referer(
        defined('WC_BINANCE_NONCE_ACTION') ? WC_BINANCE_NONCE_ACTION : 'wc_binance_pay_nonce_action',
        defined('WC_BINANCE_NONCE_NAME')   ? WC_BINANCE_NONCE_NAME   : 'wc_binance_pay_nonce'
    );

    $order_id  = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
    $order_key = isset($_POST['order_key']) ? sanitize_text_field(wp_unslash($_POST['order_key'])) : '';
    if (!$order_id) wp_send_json_error(['message' => __('Missing order ID.', 'wc-binance-pay')], 200);
    $order = wc_get_order($order_id);
    if (!$order) wp_send_json_error(['message' => __('Order not found.', 'wc-binance-pay')], 200);

    // Authorization: order owner or valid order_key
    $authorized = false;
    if (get_current_user_id() && (int)$order->get_user_id() === get_current_user_id()) $authorized = true;
    elseif (!empty($order_key) && hash_equals($order->get_order_key(), $order_key)) $authorized = true;
    if (!$authorized) wp_send_json_error(['message' => __('You are not allowed to access this order.', 'wc-binance-pay')], 200);

    // Only handle this gateway
    if ($order->get_payment_method() !== 'binance_static') {
        wp_send_json_error(['message' => __('This order did not use the Binance Static QR gateway.', 'wc-binance-pay')], 200);
    }

    // Lock semantics:
    // - If order is already processing/completed, ensure lock=yes and short-circuit.
    // - If order is on-hold, we still allow processing even if a stale lock exists (manual rollback case).
    $locked = ($order->get_meta('_binance_locked') === 'yes');
    if ($order->has_status(['processing', 'completed'])) {
        if (!$locked) {
            $order->update_meta_data('_binance_locked', 'yes');
            $order->save();
        }
        wp_send_json_success([
            'done'   => true,
            'status' => $order->get_status(),
            'message'=> __('Order already processed.', 'wc-binance-pay')
        ],200);
    }

    if (!$order->has_status('on-hold')) {
        wp_send_json_success([
            'done'   => false,
            'status' => $order->get_status(),
            'message'=> __('Order is not On-Hold. Please contact the merchant.', 'wc-binance-pay')
        ],200);
    }

    // Gateway & client
    $gateway = null;
    if (function_exists('WC') && WC()->payment_gateways()) {
        $gws = WC()->payment_gateways()->get_available_payment_gateways();
        if (isset($gws['binance_static'])) $gateway = $gws['binance_static'];
    }
    if (!$gateway || !($gateway instanceof WC_Gateway_Binance_Static)) {
        if (class_exists('WC_Gateway_Binance_Static')) $gateway = new WC_Gateway_Binance_Static();
    }
    if (!$gateway || empty($gateway->binance_client)) {
        wp_send_json_error(['message' => __('API keys are not configured or client unavailable.', 'wc-binance-pay')], 200);
    }

    // Expected match info
    $memo       = (string) $order->get_meta('_payment_memo');
    $asset      = strtoupper($order->get_meta('_asset_symbol') ?: 'USDT');
    $expected   = (float)  ($order->get_meta('_asset_amount') ?: $order->get_meta('_usdt_amount'));
    if ($memo === '' || $expected === 0.0) {
        wp_send_json_error(['message' => __('Order is missing payment match info (memo or amount).', 'wc-binance-pay')], 200);
    }
    $memo_norm  = trim($memo);
    $threshold  = 0.5;
    $now        = (int) round(microtime(true) * 1000);
    $start_ms   = $now - WC_BINANCE_PAY_LOOKBACK_DAYS * DAY_IN_SECONDS * 1000;

    try {
        /** First try: with time window (efficient) */
        $payRows = array();
        $payResp1 = $gateway->binance_client->getPayTransactions($start_ms, $now, 200);
        if (is_array($payResp1) && !empty($payResp1['success']) && is_array($payResp1['data'])) {
            $payRows = $payResp1['data'];
        }

        /** Fallback: no time window if empty (covers C2C with transactionTime/time=0) */
        if (empty($payRows)) {
            $payResp2 = $gateway->binance_client->getPayTransactions(null, null, 100);
            if (is_array($payResp2) && !empty($payResp2['success']) && is_array($payResp2['data'])) {
                $payRows = $payResp2['data'];
            }
        }

        if ($payRows) {
            foreach ($payRows as $row) {
                $n = wc_bp_normalize_pay_row($row);
                $is_c2c = (strtoupper($n['orderType']) === 'C2C');

                // Direction/status only enforced for non-C2C
                if (!$is_c2c) {
                    if (!wc_bp_is_allowed_dir($n['type']))      continue;
                    if (!wc_bp_is_allowed_status($n['status'])) continue;
                }
                // Asset must match (USDT/USDC)
                if ($n['currency'] !== $asset) continue;

                // Time window check (skip if C2C and time is 0)
                if (!$is_c2c && $n['time']) {
                    if ($n['time'] < $start_ms || $n['time'] > $now) continue;
                }

                // Memo strict equality (case-insensitive, trimmed)
                if ($n['note'] === '' || strcasecmp($memo_norm, trim($n['note'])) !== 0) continue;

                // Already processed with this tx?
                if ($n['txid'] && $order->get_meta('_binance_txid') === $n['txid']) {
                    if (!$order->has_status(['processing','completed'])) {
                        // If manually rolled back to on-hold, still treat as success and re-push
                        $order->set_transaction_id( (string)$n['txid'] );
                        $order->payment_complete( (string)$n['txid'] );
                        if (!$order->has_status(['processing','completed'])) {
                            $order->set_status('processing', __('Force update after Binance Pay verification.', 'wc-binance-pay'));
                        }
                        if (method_exists($order, 'set_date_paid') && ! $order->get_date_paid()) {
                            $order->set_date_paid( time() );
                        }
                        $order->update_meta_data('_binance_locked', 'yes');
                        $order->save();
                    }
                    wp_send_json_success([
                        'done'=>true,
                        'status'=>$order->get_status(),
                        'message'=>__('This transaction was already processed.', 'wc-binance-pay')
                    ],200);
                }

                // Amount decision
                $diff = (float)$n['amount'] - (float)$expected;

                if (abs($diff) < $threshold) {
                    // ====== FORCE PAYMENT SUCCESS & LOCK ======
                    $order->set_transaction_id( $n['txid'] ? (string)$n['txid'] : '' );
                    $order->payment_complete( $n['txid'] ? (string)$n['txid'] : '' );

                    // Fallback: still not processing/completed → force push
                    if (!$order->has_status(array('processing','completed'))) {
                        $order->set_status('processing', __('Force update after Binance Pay verification.', 'wc-binance-pay'));
                    }

                    // Explicit paid time
                    if (method_exists($order, 'set_date_paid') && ! $order->get_date_paid()) {
                        $order->set_date_paid( time() );
                    }

                    $order->add_order_note(sprintf(
                        __('Binance Pay received: %1$s %2$s | Diff %3$s (<0.5) | Tx: %4$s', 'wc-binance-pay'),
                        wc_clean(number_format((float)$n['amount'], 6, '.', '')),
                        esc_html($asset),
                        wc_clean(number_format(abs($diff), 6, '.', '')),
                        $n['txid'] ?: '-'
                    ));
                    $order->update_meta_data('_binance_txid', $n['txid']);
                    $order->update_meta_data('_binance_checked', 'yes');
                    $order->update_meta_data('_binance_locked', 'yes'); // lock after success
                    $order->save();

                    wp_send_json_success([
                        'done'   => true,
                        'status' => $order->get_status(),
                        'lock'   => true,
                        'message'=> __('Payment verified. Page will refresh…', 'wc-binance-pay'),
                    ],200);

                } elseif ($diff < 0) {
                    $short = abs($diff);
                    if (!$order->has_status('on-hold')) $order->update_status('on-hold');
                    $order->add_order_note(sprintf(__('(Binance Pay) Underpaid %1$s %2$s (≥0.5). Order remains On-Hold.', 'wc-binance-pay'), wc_clean(number_format($short, 6, '.', '')), esc_html($asset)));
                    $order->update_meta_data('_binance_checked', 'yes');
                    $order->save();

                    wp_send_json_success([
                        'done'   => false,
                        'status' => 'on-hold',
                        'lock'   => false,
                        'message'=> sprintf(__('Underpaid %1$s %2$s. Order remains On-Hold.', 'wc-binance-pay'), wc_clean(number_format($short, 6, '.', '')), esc_html($asset))
                    ],200);

                } else {
                    $extra = $diff;
                    if (!$order->has_status('on-hold')) $order->update_status('on-hold');
                    $order->update_meta_data('_binance_txid', $n['txid']);
                    $order->update_meta_data('_binance_checked', 'yes');
                    $order->add_order_note(sprintf(__('(Binance Pay) Overpaid %1$s %2$s (≥0.5). Order remains On-Hold.', 'wc-binance-pay'), wc_clean(number_format($extra, 6, '.', '')), esc_html($asset)));
                    $order->save();

                    wp_send_json_success([
                        'done'   => false,
                        'status' => 'on-hold',
                        'lock'   => false,
                        'message'=> sprintf(__('Overpaid %1$s %2$s. Order remains On-Hold.', 'wc-binance-pay'), wc_clean(number_format($extra, 6, '.', '')), esc_html($asset))
                    ],200);
                }
            }
        }

        wp_send_json_success([
            'done'   => false,
            'status' => $order->get_status(),
            'lock'   => false,
            'message'=> sprintf(__('No matching Binance Pay receipt found in the last %d day(s). Please check asset/memo/amount/account.', 'wc-binance-pay'), (int)WC_BINANCE_PAY_LOOKBACK_DAYS)
        ],200);

    } catch (Throwable $e) {
        wp_send_json_error(['message' => __('System error. Please try again later.', 'wc-binance-pay')], 200);
    }
}
endif;

/** ========== Admin: open debug window (returns the latest single record) ========== */
if (!function_exists('wc_binance_handle_api_test')) :
function wc_binance_handle_api_test() {

    if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Insufficient permissions. Admin (manage_woocommerce) required.', 'wc-binance-pay')));
    }
    check_ajax_referer(
        defined('WC_BINANCE_NONCE_ACTION') ? WC_BINANCE_NONCE_ACTION : 'wc_binance_pay_nonce_action',
        defined('WC_BINANCE_NONCE_NAME')   ? WC_BINANCE_NONCE_NAME   : 'wc_binance_pay_nonce'
    );

    if (!class_exists('WC_Gateway_Binance_Static')) {
        wp_send_json_error(array('message' => __('Payment gateway not loaded.', 'wc-binance-pay')));
    }
    $gateway = new WC_Gateway_Binance_Static();
    if (empty($gateway->api_key) || empty($gateway->api_secret)) {
        wp_send_json_error(array('message' => __('Please configure API Key and Secret first.', 'wc-binance-pay')));
    }
    if (!$gateway->binance_client) {
        wp_send_json_error(array('message' => __('Binance client is not ready.', 'wc-binance-pay')));
    }

    try {
        // Fetch a batch, sort by time (desc), return the latest (most recent)
        $resp = $gateway->binance_client->getPayTransactions(null, null, 100);
        $last = null;

        if (!empty($resp['success']) && !empty($resp['data']) && is_array($resp['data'])) {
            $list = array_map('wc_bp_normalize_pay_row', $resp['data']);
            usort($list, function($a,$b){ return ($b['time'] <=> $a['time']); }); // larger 'time' = newer; if both 0, keep original order
            $last = isset($list[0]) ? $list[0] : null;
        }

        $out = array(
            'last' => $last ? array(
                'orderType' => $last['orderType'],
                'type'      => $last['type'],
                'status'    => $last['status'],
                'currency'  => $last['currency'],
                'amount'    => $last['amount'],
                'note'      => $last['note'],
                'time'      => $last['time'],
                'txid'      => $last['txid'],
            ) : null,
            'note' => $last ? __('Latest Binance Pay record returned (fields normalized).', 'wc-binance-pay')
                            : __('No records found. Check API base domain/permissions/account match with the QR code.', 'wc-binance-pay'),
        );

        wp_send_json_success($out);

    } catch (Throwable $e) {
        wp_send_json_error(array('message' => sprintf(__('Debug failed: %s', 'wc-binance-pay'), $e->getMessage())));
    }
}
endif;
