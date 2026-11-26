=== CasaPay for WooCommerce ===
Contributors: casapay, illimar
Tags: woocommerce, payment gateway, rent, security deposit, deposit guarantee
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept rental payments through WooCommerce, with security deposit and CasaPay deposit guarantee options at checkout.

== Description ==

Connect your rental website to CasaPay and let tenants make their move-in payment directly from your WooCommerce checkout.

Set the deposit amount for each rental and offer tenants the choice of paying the security deposit upfront or using a CasaPay deposit guarantee, where available. The checkout total updates to reflect their selection before they place the order.

Tenants then complete identity verification and payment on CasaPay's hosted checkout. Once payment is verified, the plugin automatically marks the WooCommerce order as paid.

= Key features =

* **Flexible deposit options:** offer an upfront security deposit, a CasaPay deposit guarantee, or let the tenant choose.
* **Product-level settings:** configure the CasaPay agreement type, deposit amount and available deposit options for each rental.
* **Hosted payment flow:** identity verification and payment take place on CasaPay's hosted page. Card and bank details do not pass through the plugin.
* **Automatic payment confirmation:** signed webhooks and an API status check confirm payment before the order is marked as paid.
* **Follow-up invoicing:** access the CasaPay payment agreement and its dedicated email alias from the order to send future invoices through CasaPay.
* **WooCommerce compatibility:** supports classic checkout, Cart and Checkout blocks, and High-Performance Order Storage (HPOS).
* **Test mode:** test your integration before accepting live payments.
* **For developers:** a PHP API, action hooks and a REST endpoint let other plugins read the CasaPay agreement and email alias of an order.

= Requirements and scope =

A CasaPay account with Gateway API access is required. Supports EUR and GBP stores, WooCommerce 8.9+ and PHP 7.4+.

Each cart can contain one rental product, with a quantity of one. Ongoing monthly rent invoices are handled through CasaPay, not WooCommerce. Refunds are not supported within the plugin.

== External services ==

This plugin connects to the CasaPay Gateway API, a payment service run by CasaPay. It is needed to take payments and to create rental agreements and deposit guarantees.

* Live API: `https://manage.casapay.com/api/v1/gateway`
* Sandbox API (test mode): `https://manage.test.casapay.com/api/v1/gateway`
* CasaPay's hosted payment page, where the tenant is redirected: `https://gateway.casapay.com`

What is sent, and when:

* **On the cart and checkout pages**, when the cart holds a CasaPay rental: the deposit amount, the cart total and the currency, to get the price of the CasaPay guarantee. No personal data is sent. The answer is cached for six hours.
* **When the customer places an order with CasaPay**: the customer's first and last name, e-mail address, phone number, personal ID code (only if the customer enters it), the order number, the names of the ordered items, the amounts, the currency, the order ID and order key, and the return addresses of your shop.
* **After the order is placed**: the CasaPay session and agreement IDs, to check the payment status, to read the agreement and its e-mail alias, and to cancel the payment session if the order is cancelled.
* **On CasaPay's hosted page** the customer gives identity verification and payment details directly to CasaPay. This plugin never sees card or bank details.

CasaPay's terms of service: https://casapay.com/operator/terms (operators) and https://casapay.com/tenant/terms (tenants)
CasaPay's privacy policy: https://casapay.com/privacy

== Installation ==

1. Install and activate WooCommerce (8.9 or newer).
2. Install and activate CasaPay for WooCommerce.
3. Go to **WooCommerce → Settings → Payments → CasaPay**.
4. Enter your test and live API keys and webhook secrets from CasaPay.
5. Copy the **Webhook URL** shown on the settings page (`/wp-json/casapay/v1/webhook`) and give it to CasaPay.
6. On each rental product, open **Product data → General** and set **CasaPay agreement**, **Deposit** and **Deposit options**.
7. Test a few orders in test mode, then turn test mode off.

Your store currency must be EUR or GBP.

== Frequently Asked Questions ==

= Why does the tenant choose the deposit option in WooCommerce and not on CasaPay's page? =

So the order total is right before the order is created. Paying the deposit now and using a guarantee cost different amounts today. If the choice were made on CasaPay's page, the WooCommerce order, its confirmation e-mail and its invoice would show the wrong total.

= Can a cart hold more than one rental? =

No. One CasaPay payment creates one rental agreement with one deposit, so a cart can hold one rental product, with quantity 1. Other products can be in the same cart.

= Can I take normal payments with CasaPay too? =

Yes. Orders without a rental product use the agreement type set in the plugin settings, with no deposit.

= Does the plugin handle refunds? =

No. The CasaPay Gateway API has no refund endpoint, so refunds are made through CasaPay.

= What about the monthly rent? =

Monthly invoices do not go through WooCommerce. They are sent to the agreement's e-mail alias or created through the CasaPay API.

= How do other plugins get the CasaPay e-mail alias of an order? =

Use `casapay_wc_get_order_email_alias( $order )` or the `casapay_wc_agreement_ready` action. See DEVELOPERS.md in the plugin folder.

= Where are the logs? =

WooCommerce → Status → Logs, source `casapay`.

== Changelog ==

= 0.1.0 =
* First release.
