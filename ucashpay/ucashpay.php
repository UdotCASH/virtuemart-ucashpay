<?php
/**
 * U.CASH Pay payment plugin for VirtueMart (Joomla).
 *
 * Flow:
 *   1. plgVmConfirmedOrder()                creates a U.CASH Pay checkout via the SDK
 *                                           and redirects the buyer.
 *   2. plgVmOnPaymentResponseReceived()     the buyer returns from U.CASH Pay.
 *   3. plgVmOnPaymentNotification()         the signed settlement webhook. Verify it and
 *                                           mark the VirtueMart order 'C' (Confirmed/Paid).
 *
 * @package VirtueMart
 * @subpackage payment
 * @copyright (c) 2015-2026 U.CASH. All rights reserved.
 */

defined('_JEXEC') or die('Restricted access');

if (!class_exists('vmPSPlugin')) {
    require VMPATH_PLUGINLIBS . DS . 'vmpsplugin.php';
}
if (!class_exists('VirtueMartCart')) {
    require VMPATH_SITE . DS . 'helpers' . DS . 'cart.php';
}

require_once __DIR__ . '/PayUCashIntegration.php';

class plgVmPaymentucashpay extends vmPSPlugin
{
    /** Plugin identifier (matches the manifest element + the language file prefix). */
    public const ELEMENT = 'ucashpay';

    /** VirtueMart order status "Confirmed" (used for a paid order). */
    public const PAID_STATUS = 'C';

    /** Constructor: load the plugin's translatable strings + the JTable. */
    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        $this->_loggable = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_xmlpath = __DIR__ . DS . 'ucashpay.xml';
        $this->_tablename = '#__virtuemart_payment_plg_ucashpay';

        // Load plugin params on construction so method classes inherit them.
        $this->_toReinit = true;
        $varsToPush = $this->getVarsToPush();
        $this->setConfigParameterable($this->_name, $varsToPush);
    }

    /** Declare the extra DB columns the plugin stores per order. */
    public function getTableSQLFields()
    {
        return array(
            'id'                          => 'int(1) unsigned NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id'         => 'int(1) unsigned',
            'order_number'                => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) unsigned',
            'payment_name'                => 'varchar(255)',
            'ucashpay_txn_id'             => 'int(1)',
            'payment_order_total'         => 'decimal(15,5) NOT NULL',
            'payment_currency'            => 'char(3)',
        );
    }

    /**
     * plgVmConfirmedOrder(): the order has been placed in VirtueMart.
     * Create a U.CASH Pay checkout and redirect the buyer to the hosted page.
     */
    public function plgVmConfirmedOrder($cart, $order)
    {
        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null;
        }
        if (!$this->selectedThisByMethodId($order['details']['BT']->virtuemart_paymentmethod_id)) {
            return null;
        }

        $order_number = isset($order['details']['BT']->order_number) ? $order['details']['BT']->order_number : '';
        $order_id     = isset($order['details']['BT']->virtuemart_order_id) ? (int) $order['details']['BT']->virtuemart_order_id : 0;

        $amount   = isset($order['details']['BT']->order_total) ? $order['details']['BT']->order_total : 0;
        $currency = $this->getCurrencyCode(isset($order['details']['BT']->order_currency) ? $order['details']['BT']->order_currency : 0, $order);

        $sdk = $this->sdk($method);
        $external_reference = $order_id . ':' . $order_number;
        $return_url = JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm='
                     . (int) $order['details']['BT']->virtuemart_paymentmethod_id
                     . '&on=' . urlencode($order_number);

        $r = $sdk->createCheckout(
            (string) $amount,
            (string) $currency,
            $external_reference,
            'Order ' . $order_number,
            $return_url
        );

        $this->log('createCheckout: ' . json_encode(['order' => $order_id, 'result' => $r]), 'info', self::ELEMENT);
        if (!$r['ok']) {
            vmError('U.CASH Pay: could not create the checkout. ' . $r['error']);
            return null;
        }

        // Persist the per-order row so the webhook can find the order + reconcile.
        $dbValues = array();
        $dbValues['virtuemart_order_id']         = $order_id;
        $dbValues['order_number']                = $order_number;
        $dbValues['virtuemart_paymentmethod_id'] = (int) $method->virtuemart_paymentmethod_id;
        $dbValues['payment_name']                = $method->payment_name;
        $dbValues['ucashpay_txn_id']             = isset($r['transaction_id']) ? (int) $r['transaction_id'] : 0;
        $dbValues['payment_order_total']         = (float) $amount;
        $dbValues['payment_currency']            = $currency;
        $this->storePSPluginInternalData($dbValues);

        // Empty the cart (order is recorded) then redirect.
        $cart->emptyCart();
        JFactory::getApplication()->redirect($r['payment_url']);
    }

    /** Buyer returns from U.CASH Pay. Nothing to verify here (the webhook does that). */
    public function plgVmOnPaymentResponseReceived(&$html)
    {
        $html = '<p>' . vmText::_('PLG_VMPAYMENT_UCASHPAY_THANKYOU') . '</p>';
        JFactory::getApplication()->enqueueMessage(vmText::_('PLG_VMPAYMENT_UCASHPAY_THANKYOU'));
        return true;
    }

    /**
     * plgVmOnPaymentNotification(): the signed settlement webhook.
     * Verify the signature, reconcile amount + currency, then mark the order Confirmed (C).
     */
    public function plgVmOnPaymentNotification()
    {
        $pm_id = (int) vRequest::getInt('pm', 0);
        if (!($method = $this->getVmPluginMethod($pm_id)) || !$this->selectedThisByMethodId($pm_id)) {
            return 'Not configured';
        }

        $raw        = file_get_contents('php://input');
        $sig_header = isset($_SERVER['HTTP_X_WEBHOOK_SIGNATURE']) ? $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] : '';

        $sdk = $this->sdk($method);
        $r   = $sdk->verifyWebhook($raw, $sig_header, $this->secret($method));
        if (!$r['verified']) {
            $this->log('Webhook rejected: ' . $r['error'], 'error', self::ELEMENT);
            return 'Invalid signature';
        }

        $txn      = isset($r['transaction']) ? $r['transaction'] : [];
        $external = isset($txn['external_reference']) ? (string) $txn['external_reference'] : '';
        $parts    = explode(':', $external, 2);
        $order_id = isset($parts[0]) ? (int) $parts[0] : 0;
        if ($order_id <= 0) {
            $this->log('Webhook bad external_reference: ' . $external, 'error', self::ELEMENT);
            return 'Missing order reference';
        }

        $orderModel = VmModel::getModel('orders');
        $order      = $orderModel->getOrder($order_id);
        if (empty($order['details']['BT'])) {
            $this->log('Webhook order missing: ' . $order_id, 'error', self::ELEMENT);
            return 'Order not found';
        }

        // Reconciliation: currency + amount must match.
        $amount      = isset($txn['amount_fiat']) ? (float) $txn['amount_fiat'] : 0;
        $currency    = isset($txn['currency']) ? strtoupper((string) $txn['currency']) : '';
        $order_total = isset($order['details']['BT']->order_total) ? (float) $order['details']['BT']->order_total : 0;
        $order_curr  = strtoupper($this->getCurrencyCode(isset($order['details']['BT']->order_currency) ? $order['details']['BT']->order_currency : 0, $order));
        if ($currency !== '' && $order_curr !== '' && $currency !== $order_curr) {
            $this->log('Webhook currency mismatch: ' . $order_curr . ' vs ' . $currency, 'error', self::ELEMENT);
            return 'Currency mismatch';
        }
        if ($order_total > $amount + 0.01) {
            $this->log('Webhook amount low: ' . $order_total . ' vs ' . $amount, 'error', self::ELEMENT);
            return 'Amount below order total';
        }

        // Idempotency: skip if already Confirmed.
        if (isset($order['details']['BT']->order_status) && $order['details']['BT']->order_status === self::PAID_STATUS) {
            return 'OK';
        }

        $orderModel->updateStatusForOneOrder($order_id, self::PAID_STATUS, true);
        $this->log('Webhook marked order Confirmed: ' . $order_id, 'info', self::ELEMENT);

        echo 'OK';
        return true;
    }

    /**
     * plgVmDeclarePluginParamsPayment(): register the plugin's config fields so the
     * merchant can paste Cloud token / Webhook secret / base URL in the VirtueMart admin.
     */
    public function plgVmDeclarePluginParamsPayment(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    /** plgVmDeclarePluginParamsPaymentVM3(): same, the v3 entry point. */
    public function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    /** Build an SDK instance from a method's params. */
    private function sdk($method)
    {
        $cloud = isset($method->ucashpay_cloud_token) ? trim($method->ucashpay_cloud_token) : (isset($method->cloud_token) ? trim($method->cloud_token) : '');
        $base  = isset($method->ucashpay_base_url)    ? trim($method->ucashpay_base_url)    : 'https://pay.u.cash';
        return new PayUCashIntegration($cloud, self::normalizeUrl($base));
    }

    /** Read the webhook secret from the method params. */
    private function secret($method)
    {
        $s = isset($method->ucashpay_webhook_secret) ? trim($method->ucashpay_webhook_secret) : (isset($method->webhook_secret) ? trim($method->webhook_secret) : '');
        return html_entity_decode($s);
    }

    /** Resolve the currency code for the order's currency id. */
    private function getCurrencyCode($currency_id, $order)
    {
        if (isset($order['details']['BT']->user_currency_code) && $order['details']['BT']->user_currency_code) {
            return $order['details']['BT']->user_currency_code;
        }
        $db = JFactory::getDbo();
        $q  = $db->getQuery(true);
        $q->select('currency_code_3')->from('#__virtuemart_currencies')->where('virtuemart_currency_id = ' . (int) $currency_id);
        $code = $db->setQuery($q)->loadResult();
        return $code ? $code : 'USD';
    }

    /** Strip /admin.php from a U.CASH Pay URL. */
    public static function normalizeUrl($raw)
    {
        $url = rtrim(trim((string) $raw), '/');
        $url = preg_replace('#/(admin|ajax|api)\.php$#i', '', $url);
        return $url !== '' ? $url : 'https://pay.u.cash';
    }
}
