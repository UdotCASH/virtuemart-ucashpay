# U.CASH Pay for VirtueMart (Joomla)

Accept crypto and card payments in your VirtueMart store through [U.CASH Pay](https://pay.u.cash).
Buyers pay at the hosted U.CASH Pay checkout and the VirtueMart order is confirmed automatically.

## Set up your pay.u.cash account

You need a free U.CASH Pay account and a store before this plugin can accept payments. Settlement is non-custodial: crypto goes straight to addresses you control.

1. **Sign up** at [pay.u.cash](https://pay.u.cash) with your email and password, then click the verification link in the email U.CASH Pay sends you.
2. **Set your receive addresses.** Go to **Settings → Addresses** and enter a wallet address for each coin you want to accept. You can also use ENS, Unstoppable Domains, or FIO names instead of raw addresses. This is where crypto payments settle.
3. **Create a store.** Go to **Account → Stores**, click **+ Add Store**, name it (for example, after this platform), and create it.
4. **Copy the store credentials.** In that store's row, copy the **Store Cloud Token** and the **Store Webhook Secret**. Use the store-level token, not the account-wide one.
5. **Set the webhook URL.** Paste this plugin's webhook callback URL (shown in the plugin settings below) into the store's **Store Webhook URL** field, save, then click **Test Webhook** to confirm U.CASH Pay can reach your store.

Then paste the **Store Cloud Token** and **Store Webhook Secret** into the plugin configuration fields described below.

> To also accept fiat cards, connect your own Stripe account under **Settings → Payment processors**. Cards run non-custodially through Stripe.

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
