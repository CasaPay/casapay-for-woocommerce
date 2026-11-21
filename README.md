# CasaPay for WooCommerce

Sell rentals in WooCommerce through the CasaPay Gateway. At checkout the tenant chooses between paying the security deposit now or using a CasaPay guarantee. They then pay on CasaPay's hosted page, and the order is confirmed from a signed webhook plus an API check.

- WordPress.org listing text: [readme.txt](readme.txt)
- Reading the CasaPay agreement and e-mail alias from other plugins: [DEVELOPERS.md](DEVELOPERS.md)

## Setup

1. Activate the plugin (WooCommerce 8.9+, PHP 7.4+).
2. **WooCommerce → Settings → Payments → CasaPay**:
   - enter the test and live API keys and webhook secrets (from CasaPay);
   - copy the **Webhook URL** (`/wp-json/casapay/v1/webhook`) and give it to CasaPay.
3. **Product data → General** on each rental:
   - **CasaPay agreement**: `ontime`, `cover` or `payment_link`;
   - **Deposit**: amount (OnTime and Cover only, up to 15,000);
   - **Deposit options**: customer chooses / deposit now only / guarantee only.

The store currency must be EUR or GBP.

## Flow

```
Checkout ──► customer picks a deposit option ──► WooCommerce adds one fee line:
               "Deposit"                         = deposit amount
               "CasaPay guarantee, first month"  = pricing-preview due_today − first payment
        │
        ▼
process_payment ──► POST /sessions (fixed deposit_mode, never "choice")
        │            first_payment_amount = order total − CasaPay fee lines
        ▼
CasaPay page: identity check + payment ──► back to the thank-you page ("Waiting for confirmation…")
        │
        ▼
webhook ──► HMAC + timestamp ──► GET /sessions/{id} ──► payment_complete()
        │                                                 (or on-hold if the amount does not match)
        ▼
background job ──► GET /agreements/{id} ──► email_alias on the order ──► casapay_wc_agreement_ready
```

Fallbacks if a webhook is lost:
- Action Scheduler checks a pending order every 10 minutes for up to 26 hours;
- the thank-you page asks the CasaPay API itself.

## Rules and limits

- **One rental per cart**, quantity 1. One session creates one agreement. When the cart holds a rental, CasaPay is the only payment method.
- **Other products** can be paid with CasaPay too. They use the agreement type from the settings, with no deposit.
- **No refunds** from the plugin, because the Gateway API has no refund endpoint.
- **Monthly invoices** do not go through WooCommerce. They go to the agreement's alias or `POST /agreements/{id}/invoice`.
- **Cancelling** an unpaid order (by the customer or an admin) cancels the CasaPay session.
- **Amount check**: if the amount CasaPay confirms does not match the order total, the order goes to `on-hold` with a note.

## Translations

Source strings are English. An Estonian translation is bundled in `languages/` (`.po` + `.mo`), and `languages/casapay-for-woocommerce.pot` is the template. Once the plugin is on WordPress.org, translations are managed on translate.wordpress.org, and its language packs take precedence over the bundled files.

## Check in the sandbox before going live

- [ ] Guarantee option: does `total_cash_amount` include the first month's guarantee fee? The plugin accepts both, but confirm which one CasaPay sends.
- [ ] `invoice_no` format `WC-{order number}`: does CasaPay accept it (uniqueness, `-2` suffix on retry)?
- [ ] How long after the session completes does `email_alias` appear?
- [ ] Simulator `payment_bank_declined` → order note, order stays pending.
- [ ] Webhook with a wrong signature → `401`, `critical` in the log.

Logs: **WooCommerce → Status → Logs → casapay**.
