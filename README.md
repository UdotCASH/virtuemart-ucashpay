# U.CASH Pay for VirtueMart (Joomla)

Accept crypto and card payments in your VirtueMart store through [U.CASH Pay](https://pay.u.cash).
Buyers pay at the hosted U.CASH Pay checkout and the VirtueMart order is confirmed automatically.

## How it works

1. `plgVmConfirmedOrder()` creates a U.CASH Pay checkout via the SDK and redirects the buyer.
2. After paying, the buyer lands on `plgVmOnPaymentResponseReceived()` (a thank-you page).
3. U.CASH Pay sends an HMAC-signed settlement webhook to `plgVmOnPaymentNotification()`. The
   plugin verifies the signature, reconciles amount + currency, and sets the order status to
   `C` (Confirmed).

## Requirements

- Joomla 3.x / 4.x with VirtueMart 3.x.
- PHP 7.2+ with the `curl` extension.
- HTTPS on the public webhook URL.
- A free [U.CASH Pay](https://pay.u.cash) account.

## Install

1. Zip the `ucashpay/` directory (the manifest `ucashpay.xml` must be at its root).
2. In Joomla admin go to **Extensions -> Manage -> Install** and upload the zip.
3. The install script creates the `#__virtuemart_payment_plg_ucashpay` table automatically.
4. In VirtueMart -> **Products -> Payment Methods**, add a new payment method, set its type to
   **U.CASH Pay**, and enable it.

## Configure

1. In U.CASH Pay -> **Account -> Stores**, create a store for this VirtueMart install and copy
   its **Cloud token** and **Webhook secret**.
2. In VirtueMart -> **Payment Methods -> U.CASH Pay**, paste them into **Cloud token** and
   **Webhook secret**, and set the **U.CASH Pay URL** (default `https://pay.u.cash`).
3. Set the store's **Webhook URL** in U.CASH Pay -> **Account -> Stores -> [your store] -> Edit**
   to:

   ```
   https://YOUR-SITE/index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&pm=PAYMENT_METHOD_ID
   ```

   Replace `PAYMENT_METHOD_ID` with the VirtueMart payment method id from the URL of its edit
   page.
4. Save, then place a test order.

## Files

- `ucashpay/ucashpay.php` the main plugin class (extends vmPSPlugin).
- `ucashpay/ucashpay.xml` the Joomla plugin installer manifest.
- `ucashpay/script.php` install / uninstall script (creates the per-order table).
- `ucashpay/language/en-GB/en-GB.plg_vmpayment_ucashpay.sys.ini` English language strings.
- `ucashpay/PayUCashIntegration.php` the shared SDK.

## Multi-store

One U.CASH Pay store per VirtueMart install. Running several integrations? Create one store
each in U.CASH Pay -> **Account -> Stores**; each gets its own Cloud token, Webhook secret, and
Webhook URL.

## Support

[https://u.cash](https://u.cash)

(c) 2015-2026 U.CASH. All rights reserved.
