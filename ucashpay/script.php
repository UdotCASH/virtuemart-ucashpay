<?php
/**
 * Install / uninstall script for the U.CASH Pay VirtueMart plugin.
 *
 * Installs the per-order plugin table declared by getTableSQLFields(), prints
 * a friendly message, and on uninstall drops the table + logs the event.
 *
 * @copyright (c) 2015-2026 U.CASH. All rights reserved.
 */

defined('_JEXEC') or die('Restricted access');

class PlgVmPaymentUcashpayInstallerScript
{
    /** Post-install + post-update: create the table + print a friendly note. */
    public function postflight($type, $parent)
    {
        $this->installTable();

        $app = JFactory::getApplication();
        if (method_exists($app, 'enqueueMessage')) {
            $app->enqueueMessage(
                'U.CASH Pay for VirtueMart installed. Create a store in U.CASH Pay (Account -> Stores), ' .
                'paste its Cloud token and Webhook secret into the plugin, set the Webhook URL to your ' .
                'site notification URL, and enable the payment method.',
                'message'
            );
        }

        return true;
    }

    /** On uninstall: drop the plugin table. */
    public function uninstall($parent)
    {
        $db = JFactory::getDbo();
        $db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName('#__virtuemart_payment_plg_ucashpay'))->execute();

        $app = JFactory::getApplication();
        if (method_exists($app, 'enqueueMessage')) {
            $app->enqueueMessage('U.CASH Pay for VirtueMart uninstalled.', 'message');
        }
        return true;
    }

    /** Create the per-order table if it does not exist. */
    private function installTable()
    {
        $db   = JFactory::getDbo();
        $name = $db->quoteName('#__virtuemart_payment_plg_ucashpay');

        $columns = array(
            'id'                          => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id'         => 'int(1) UNSIGNED',
            'order_number'                => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name'                => 'varchar(255)',
            'ucashpay_txn_id'             => 'int(1)',
            'payment_order_total'         => 'decimal(15,5) NOT NULL',
            'payment_currency'            => 'char(3)',
        );
        $defs = array();
        foreach ($columns as $col => $def) {
            $defs[] = $db->quoteName($col) . ' ' . $def;
        }
        $defs[] = 'PRIMARY KEY (' . $db->quoteName('id') . ')';

        $sql = 'CREATE TABLE IF NOT EXISTS ' . $name . ' (' . implode(', ', $defs) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        $db->setQuery($sql)->execute();
    }
}
