=== Coino Live Crypto Payments ===
Contributors: coinolive
Tags: crypto, usdt, bitcoin, payments, woocommerce
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 7.6
WC tested up to: 9.9
Stable tag: 1.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept USDT, Bitcoin, Ethereum, TRON, TON, Solana and more in WooCommerce. Orders are marked paid automatically.

== Description ==

Customers choose "Pay with crypto" at checkout, pick a coin and network on the Coino Live payment page and send the amount shown. The order updates by itself:

* **On hold** as soon as the payment is seen on the network,
* **Processing** (or Completed for virtual products) once it is confirmed,
* **Failed** if the payment window closes unpaid. The customer can pay again from their account.

Payments are credited to your Coino Live balance; withdraw to your own wallet from the Coino Live dashboard.

* Prices in your shop currency (USD, EUR, GBP, TRY, BRL, RUB and more) are converted at the current rate.
* Works with the block checkout and the classic checkout, and with High-Performance Order Storage.
* Underpayments: the customer is told exactly how much is still missing and can send the rest.
* Secure by design: order status always comes from the Coino Live API, never from the content of a callback.
* WooCommerce > Coino Live shows your balance and every payment with its transactions, without leaving WordPress.

== Installation ==

1. Upload the plugin (Plugins > Add New > Upload Plugin) and activate it.
2. In your Coino Live dashboard, open Developers and create an API key for your gateway.
3. In WooCommerce > Settings > Payments > Coino Live, paste the key, tick "Enable" and save. The page confirms the connection.

Optional: paste the gateway's webhook signing secret (whsec_…) so callbacks with a wrong signature are refused.

Nothing else to configure: the callback URL (`/wp-json/coinolive/v1/webhook`) is sent with every payment. If callbacks cannot reach your site, open orders are still checked every five minutes and when the customer returns to your shop.

== Frequently Asked Questions ==

= Which coins can customers use? =

Every coin and network enabled on Coino Live: USDT and USDC on TRON, Ethereum, BSC, Polygon, Base, Arbitrum, Solana and TON, plus BTC, LTC, DOGE, ETH, BNB, TRX, TON, SOL and more.

= Who pays the network fee? =

You choose in your Coino Live gateway settings: the customer (added to the amount) or you (deducted from the payment).

= Can I refund from WooCommerce? =

Not automatically. Send refunds from your Coino Live balance.

== External services ==

This plugin connects to the Coino Live API (https://coino.live/api/v2), the crypto payment service it is built for. It cannot work without it.

* When a customer chooses Coino Live at checkout, it sends the order total and shop currency, the order number and a short description ("Order #… at your shop name"), the customer's billing email, the return and checkout URLs, the payment window, the WooCommerce order ID, your site address and the callback URL.
* To update orders (after a callback, when the customer returns, and every five minutes for open orders), it reads the payment by its ID.
* It reads the list of supported currencies (at most every 12 hours) to check that your shop currency can be used. The WooCommerce > Coino Live page reads your balances and this shop's payments.

Every request is authenticated with the API key you enter. The service is provided by Coino Live: [Terms of Service](https://coino.live/terms-of-service), [Privacy Policy](https://coino.live/privacy-policy).

== Changelog ==

= 1.1.2 =
* Plugin URI points to the plugin's page on coino.live.
* Translations load on init; the WordPress.org package gets them from translate.wordpress.org.

= 1.1.1 =
* Tested with WordPress 7.1.
* Error messages are escaped where they are created.
* The readme lists what the plugin sends to the Coino Live API (External services).

= 1.1.0 =
* New page WooCommerce > Coino Live: your balance and this shop's payments with their transactions.
* Clear error when the API URL points at a website instead of the API.

= 1.0.0 =
* First release for Coino Live API v2.
