<?php
if (!defined('ABSPATH')) { exit; }

class Binance_Client {
    private $api_key;
    private $api_secret;
    private $base_url;

    public function __construct($api_key, $api_secret, $base_url = 'https://api.binance.com') {
        $this->api_key    = $api_key;
        $this->api_secret = $api_secret;
        $this->base_url   = rtrim($base_url ?: 'https://api.binance.com', '/');
    }

    /**
     * Binance Pay transactions history (in-app transfers/QR with memo).
     *
     * @param int|null    $startTime  Milliseconds since epoch (inclusive).
     * @param int|null    $endTime    Milliseconds since epoch (inclusive).
     * @param int         $limit      Max number of records to return (default 100).
     * @param string|null $cursor     Optional cursor for pagination (if supported).
     * @return array                  ['success' => bool, 'data' => array, 'raw' => mixed|optional, 'error' => string|optional]
     */
    public function getPayTransactions($startTime = null, $endTime = null, $limit = 100, $cursor = null) {
        $params = array(
            'timestamp' => $this->ms(),
            'limit'     => (int) $limit,
        );
        if ($startTime) $params['startTime'] = (int) $startTime; // ms
        if ($endTime)   $params['endTime']   = (int) $endTime;   // ms
        if ($cursor)    $params['cursor']    = (string) $cursor;

        $url  = $this->signedUrl('/sapi/v1/pay/transactions', $params);
        $resp = wp_remote_get($url, array(
            'headers' => array('X-MBX-APIKEY' => $this->api_key),
            'timeout' => 30,
        ));
        if (is_wp_error($resp)) {
            return array('success' => false, 'data' => array(), 'error' => $resp->get_error_message());
        }
        $body = wp_remote_retrieve_body($resp);
        $data = json_decode($body, true);

        // Normalize different response shapes
        if (is_array($data)) {
            if (isset($data['data']['list']) && is_array($data['data']['list'])) {
                return array('success' => true, 'data' => $data['data']['list'], 'raw' => $data);
            }
            if (isset($data['list']) && is_array($data['list'])) {
                return array('success' => true, 'data' => $data['list'], 'raw' => $data);
            }
            if (isset($data['data']) && is_array($data['data'])) {
                return array('success' => true, 'data' => $data['data'], 'raw' => $data);
            }
            if (isset($data['success']) && !$data['success']) {
                return array('success' => false, 'data' => array(), 'raw' => $data);
            }
        }
        return array('success' => false, 'data' => array(), 'raw' => $data);
    }

    /** Helpers */
    private function ms() {
        return (int) round(microtime(true) * 1000);
    }
    private function signedUrl($path, array $params) {
        ksort($params);
        $query = http_build_query($params, '', '&');
        $signature = hash_hmac('sha256', $query, $this->api_secret);
        $sep = (strpos($path, '?') === false) ? '?' : '&';
        return $this->base_url . $path . $sep . $query . '&signature=' . $signature;
    }
}
