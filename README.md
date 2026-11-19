# Coino Live – WooCommerce Crypto Payment Gateway (USDT, BTC)

Accept USDT, Bitcoin, Ethereum, TRON, TON, Solana and other cryptocurrencies in your WooCommerce store. This free plugin connects your shop to the Coino Live crypto payment gateway (117 assets on 11 networks), and orders are marked paid automatically.

**Version 1.1.3** · requires WordPress 6.2+, WooCommerce 7.6+, PHP 7.4+ · Free, GPL-2.0-or-later

- **Install from WordPress.org:** [wordpress.org/plugins/coinolive](https://wordpress.org/plugins/coinolive/) (search for "Coino Live" in Plugins > Add New) · **Download:** [coinolive-woocommerce-1.1.3.zip](https://coino.live/downloads/coinolive-woocommerce-1.1.3.zip) · [WooCommerce plugin page](https://coino.live/woocommerce)
- **API reference:** [coino.live/api-guide](https://coino.live/api-guide) · **Support:** [support@coino.live](mailto:support@coino.live)

> **Updating from the old version?** Earlier code in this repository used the Coino Live API v1, which has been retired: its endpoints (`/api/v1/...`) no longer answer. Version 1.1.3 uses the current [API v2](https://coino.live/api-guide). Replace the old files with these.

## Fees, sign-up and withdrawals

- **Free plugin**, open source under GPL-2.0-or-later.
- **0.25% platform fee per payment.** Blockchain network fees are separate.
- **No KYC.** Sign up at [coino.live](https://coino.live) with an email and password.
- **Withdraw to your own wallet.** Payments are credited to your Coino Live balance. You withdraw them to your own wallet from the Coino Live dashboard.
- **117 assets on 11 networks**, including Bitcoin, USDT, USDC, Ethereum, TRON, TON and Solana.

## How crypto checkout works in WooCommerce

Customers choose "Pay with crypto" at checkout, pick a coin and network on the Coino Live payment page and send the amount shown. The WooCommerce order updates by itself:

- **On hold** as soon as the payment is seen on the network.
- **Processing** (or **Completed** for virtual products) once it is confirmed.
- **Failed** if the payment window closes unpaid. The customer can pay again from their account.

## Features

- **Shop-currency pricing:** Prices in your shop currency (USD, EUR, GBP, TRY, BRL, RUB and more) are converted at the current rate.
- **Checkout compatibility:** Works with the block checkout, the classic checkout and High-Performance Order Storage (HPOS).
- **Underpayment handling:** The customer is told exactly how much is still missing and can send the rest.
- **API-verified order status:** Order status always comes from the Coino Live API, never from the content of a callback.
- **Payment overview in WordPress:** WooCommerce > Coino Live shows your balance and every payment with its transactions, without leaving WordPress.

## Installation and setup

1. [Sign up for Coino Live](https://coino.live) with your email and password.
2. Upload the plugin zip (Plugins > Add New > Upload Plugin) and activate it.
3. In your Coino Live dashboard, open Developers and create an API key for your gateway.
4. In WooCommerce > Settings > Payments > Coino Live, paste the key, tick "Enable" and save. The page confirms the connection.

Optional: paste the gateway's webhook signing secret (`whsec_…`) so callbacks with a wrong signature are refused.

There is nothing else to configure. The callback URL (`/wp-json/coinolive/v1/webhook`) is sent with every payment. If callbacks cannot reach your site, open orders are still checked every five minutes and when the customer returns to your shop.

## Frequently Asked Questions

### Which cryptocurrencies can customers pay with?

Customers can pay with every coin and network enabled on Coino Live, which supports 117 assets on 11 networks. That includes USDT and USDC on TRON, Ethereum, BSC, Polygon, Base, Arbitrum, Solana and TON, plus BTC, LTC, ETH, BNB, TRX, TON, SOL and more.

### How much does it cost?

The plugin is free (GPL-2.0-or-later). Coino Live charges a 0.25% platform fee per payment. Blockchain network fees are separate.

### Do I need to pass KYC?

No. You sign up with an email and password.

### Who pays the network fee?

You choose in your Coino Live gateway settings. Either the customer pays it (added to the amount) or you do (deducted from the payment).

### Where does the money go?

Payments are credited to your Coino Live balance. You withdraw them to your own wallet from the Coino Live dashboard.

### Can I refund from WooCommerce?

Not automatically. Send refunds from your Coino Live balance.

## External services

This plugin connects to the Coino Live API (https://coino.live/api/v2), the crypto payment service it is built for. It cannot work without it.

- **Creating a payment:** When a customer chooses Coino Live at checkout, the plugin sends:
  - the order total and shop currency
  - the order number and a short description ("Order #… at your shop name")
  - the customer's billing email
  - the return and checkout URLs
  - the payment window
  - the WooCommerce order ID
  - your site address and the callback URL
- **Updating orders:** It reads the payment by its ID. This happens after a callback, when the customer returns, and every five minutes for open orders.
- **Checking currency support:** It reads the list of supported currencies (at most every 12 hours) to check that your shop currency can be used.
- **Payment overview:** The WooCommerce > Coino Live page reads your balances and this shop's payments.

Every request is authenticated with the API key you enter. The service is provided by Coino Live: [Terms of Service](https://coino.live/terms-of-service), [Privacy Policy](https://coino.live/privacy-policy).

## Changelog

### 1.1.3
- A website address entered as the API URL now gives a clear error instead of an empty one.

### 1.1.2
- Plugin URI points to the plugin's page on coino.live.
- Translations load on init; the WordPress.org package gets them from translate.wordpress.org.

### 1.1.1
- Tested with WordPress 7.1.
- Error messages are escaped where they are created.
- The readme lists what the plugin sends to the Coino Live API (External services).

### 1.1.0
- New page WooCommerce > Coino Live: your balance and this shop's payments with their transactions.
- Clear error when the API URL points at a website instead of the API.

### 1.0.0
- First release for Coino Live API v2.

## License

GPL-2.0-or-later. See the [GNU General Public License](https://www.gnu.org/licenses/gpl-2.0.html).
