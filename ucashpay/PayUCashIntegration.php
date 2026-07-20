<?php
/*
 * PayUCashIntegration  -  the shared core SDK for all pay.u.cash platform plugins.
 *
 * Zero-dependency (PHP 7.2+, curl). Encapsulates the three operations every plugin needs:
 *   1. createCheckout()  -  create a pay.u.cash transaction + extract the hosted payment link.
 *   2. verifyWebhook()   -  verify the HMAC-signed settlement webhook + return the transaction.
 *   3. testConnection()  -  verify the merchant's cloud token + webhook URL end-to-end.
 *
 * Each platform plugin (WHMCS, WooCommerce, Shopify, etc.) wraps this class. The payment model
 * is redirect-to-hosted-checkout (the buyer pays at pay.u.cash; the plugin gets an HMAC webhook
 * on settlement). Non-custodial: the plugin never sees funds or keys.
 *
 * Usage:
 *   $sdk = new PayUCashIntegration($cloud_token, 'https://pay.u.cash');
 *   $r = $sdk->createCheckout('100.00', 'USD', 'ORDER-123', 'Order #123', 'https://shop.com/success');
 *   if ($r['ok']) { header('Location: ' . $r['payment_url']); exit; }
 *
 *   // Webhook receiver:
 *   $r = $sdk->verifyWebhook(file_get_contents('php://input'), $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '', $webhook_secret);
 *   if ($r['verified']) { markOrderPaid($r['transaction']['external_reference']); }
 *
 * License: MIT
 */

class PayUCashIntegration {

    /** @var string The store's cloud token (publishable; routes payments to the merchant's store). */
    private $cloud_token;

    /** @var string The pay.u.cash base URL (scheme + host, no trailing slash). */
    private $base_url;

    /** @var int Curl timeout in seconds. */
    private $timeout = 25;

    /**
     * @param string $cloud_token The store's cloud token (from pay.u.cash admin -> Account -> Stores).
     * @param string $base_url    The pay.u.cash URL (default: the cloud host). Accepts admin.php suffix.
     */
    public function __construct($cloud_token, $base_url = 'https://pay.u.cash') {
        $this->cloud_token = trim((string) $cloud_token);
        $url = rtrim(trim((string) $base_url), '/');
        $url = preg_replace('#/(admin|ajax|api)\.php$#i', '', $url);
        $this->base_url = $url !== '' ? $url : 'https://pay.u.cash';
    }

    /**
     * Create a pay.u.cash transaction + extract the hosted checkout payment link.
     *
     * @param string $amount            Fiat amount (e.g. "100.00").
     * @param string $currency          Fiat currency code (e.g. "USD").
     * @param string $external_reference The plugin's local order/invoice ID (returned in the webhook).
     * @param string $title             Optional title shown on the checkout page.
     * @param string $redirect_url      Optional URL the buyer returns to after paying.
     * @return array ['ok' => bool, 'payment_url' => string, 'transaction_id' => int, 'error' => string]
     */
    public function createCheckout($amount, $currency, $external_reference, $title = '', $redirect_url = '') {
        $post = http_build_query([
            'function'           => 'create-transaction',
            'amount'             => $amount,
            'currency_code'      => $currency,
            'cryptocurrency_code' => '',
            'external_reference' => $external_reference,
            'title'              => $title,
            'redirect'           => $redirect_url,
            'cloud'              => $this->cloud_token,
            'idempotent'         => 1,
        ]);
        $resp = $this->post('/payment/ajax.php', $post);
        if (!is_array($resp) || empty($resp['success'])) {
            $err = is_array($resp) && isset($resp['response']) && is_string($resp['response']) ? $resp['response'] : (isset($resp['message']) ? $resp['message'] : 'unknown API error');
            return ['ok' => false, 'error' => $err];
        }
        $response = isset($resp['response']) ? $resp['response'] : [];
        // Extract the payment link robustly: the API returns a positional array; the link is a URL
        // containing /payment/id/ or /checkout/ or /id/. Search instead of assuming a fixed index.
        $link = '';
        $txn_id = 0;
        if (is_array($response)) {
            foreach ($response as $v) {
                if (is_string($v) && (strpos($v, 'https://') === 0 || strpos($v, 'http://') === 0)) {
                    $link = $v; // the payment link is the only URL in the response array
                }
                if ($txn_id === 0 && (is_int($v) || (is_string($v) && ctype_digit($v))) && (int) $v > 0) {
                    $txn_id = (int) $v;
                }
            }
        }
        if ($link === '') {
            return ['ok' => false, 'error' => 'payment link not found in the API response'];
        }
        return ['ok' => true, 'payment_url' => $link, 'transaction_id' => $txn_id];
    }

    /**
     * Verify the HMAC-signed settlement webhook from pay.u.cash.
     *
     * @param string $raw_body         The raw POST body (php://input).
     * @param string $signature_header The X-Webhook-Signature header value (t=...,v1=...).
     * @param string $webhook_secret   The store's webhook secret (from pay.u.cash admin).
     * @return array ['verified' => bool, 'transaction' => array, 'event_id' => string, 'error' => string]
     */
    public function verifyWebhook($raw_body, $signature_header, $webhook_secret) {
        if (empty($signature_header) || empty($webhook_secret)) {
            return ['verified' => false, 'error' => 'missing signature header or webhook secret'];
        }
        $parts = [];
        foreach (explode(',', $signature_header) as $chunk) {
            $kv = explode('=', $chunk, 2);
            if (count($kv) === 2) {
                $parts[$kv[0]] = $kv[1];
            }
        }
        if (!isset($parts['t'], $parts['v1']) || abs(time() - (int) $parts['t']) > 300) {
            return ['verified' => false, 'error' => 'signature timestamp missing or expired ( > 300s)'];
        }
        $expected = hash_hmac('sha256', $parts['t'] . '.' . $raw_body, $webhook_secret);
        if (!hash_equals($expected, $parts['v1'])) {
            return ['verified' => false, 'error' => 'invalid HMAC signature'];
        }
        $data = json_decode($raw_body, true);
        return [
            'verified'   => true,
            'transaction' => isset($data['transaction']) ? $data['transaction'] : [],
            'event_id'   => isset($data['event_id']) ? $data['event_id'] : '',
        ];
    }

    /**
     * Test the connection: pay.u.cash sends a signed test webhook to the merchant's webhook URL
     * and reports whether the receiver accepted it. Use this in the plugin's admin "Test" button.
     *
     * @param string $webhook_url The plugin's webhook callback URL.
     * @return array The raw API response (success/test details).
     */
    public function testConnection($webhook_url = '') {
        $post = http_build_query([
            'function'    => 'test-connection',
            'cloud'       => $this->cloud_token,
            'webhook-url' => $webhook_url,
        ]);
        return $this->post('/api.php', $post);
    }

    /**
     * Internal: POST to the pay.u.cash API + return the decoded JSON (or an error array).
     */
    private function post($path, $post_data) {
        $ch = curl_init($this->base_url . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post_data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err) {
            return ['success' => false, 'error_code' => 'connection_error', 'message' => $err];
        }
        $decoded = json_decode($resp, true);
        return is_array($decoded) ? $decoded : ['success' => false, 'error_code' => 'invalid_response', 'message' => substr((string) $resp, 0, 200)];
    }
}
