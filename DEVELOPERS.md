# CasaPay e-mail alias for other plugins

When a customer buys a rental with CasaPay, CasaPay creates an agreement (`PaymentAgreement`) with an **e-mail alias**, for example `johndoe-abc123@casapay.me`. An invoice PDF sent to that address becomes a payment link for the tenant. CRMs, billing tools, booking plugins and e-mails often need that alias for an order.

This plugin stores the alias on the order and offers PHP functions, hooks and a REST endpoint to read it. Other plugins **do not** call the CasaPay API themselves and do not need the API key.

## When the alias exists

1. The customer pays on CasaPay's page.
2. Webhook `gateway.session.completed` → the plugin confirms with `GET /sessions/{id}` → the order goes to `processing` and `payment_agreement_id` is stored.
3. A background job calls `GET /agreements/{payment_agreement_id}` and stores `email_alias`.
4. If CasaPay has not created the alias yet, the plugin tries again every 10 minutes, up to 12 times (2 hours).

So the alias can appear **from a few seconds to a few minutes after payment**. Do not expect it in `woocommerce_payment_complete` or `woocommerce_order_status_processing`. Use the `casapay_wc_agreement_ready` hook.

## Check that the plugin is active

```php
if ( ! function_exists( 'casapay_wc_get_order_email_alias' ) ) {
    return; // CasaPay for WooCommerce is not active.
}
```

## PHP functions

### `casapay_wc_get_order_email_alias( $order, $fetch_if_missing = false ): ?string`

Returns the alias or `null`. `$order` is an order ID or a `WC_Order`.

```php
$alias = casapay_wc_get_order_email_alias( $order_id );
```

`$fetch_if_missing = true` asks CasaPay right away (one HTTP request) if the alias is not stored yet. Use it only when someone is waiting for the answer, such as an admin button. Do not use it in loops or on every page load.

### `casapay_wc_get_order_agreement( $order ): ?array`

All agreement data. Returns `null` when the order was not paid with CasaPay or the agreement is not known yet.

```php
[
    'payment_agreement_id' => 123,                          // CasaPay internal ID (for API paths)
    'agreement_id'         => 'PA-20260417-ABC123',         // human-readable reference
    'agreement_type'       => 'ontime',                     // payment_link | ontime | cover
    'email_alias'          => 'johndoe-abc123@casapay.me',  // or null
    'deposit_mode'         => 'deposit_guaranteed',         // deposit_upfront | deposit_guaranteed | null
    'guarantee_id'         => 'GRN-20260311-ABC123',        // or null
    'session_id'           => 'gwy_abc123…',
]
```

### `casapay_wc_get_customer_email_aliases( $user_id ): array`

All aliases of one customer as `[ order_id => alias ]`, newest first. Useful in "My account" views when a customer has several rentals.

## Hooks

### `casapay_wc_agreement_ready` (action), recommended

Runs once, when the alias has been stored.

```php
add_action( 'casapay_wc_agreement_ready', function ( WC_Order $order, string $alias, array $agreement ) {
    // $agreement is the GET /agreements/{id} response: agreement_id, agreement_type, email_alias, coverage_amount, tenant, …
    my_crm_update_lease( $order->get_id(), [
        'casapay_alias'     => $alias,
        'casapay_agreement' => $agreement['agreement_id'] ?? null,
    ] );
}, 10, 3 );
```

### `casapay_wc_session_completed` (action)

Runs when the CasaPay API has confirmed the payment. The alias may not exist yet.

```php
add_action( 'casapay_wc_session_completed', function ( WC_Order $order, array $session ) {
    // $session is the GET /sessions/{id} response.
}, 10, 2 );
```

### `casapay_wc_session_payload` (filter)

Change the `POST /sessions` body before it is sent. For example, add a `document_url` (the rental contract) or your own `metadata` keys (20 at most in total).

```php
add_filter( 'casapay_wc_session_payload', function ( array $payload, WC_Order $order ) {
    $payload['document_url']            = my_contract_pdf_url( $order );
    $payload['metadata']['property_id'] = (string) $order->get_meta( '_property_id' );
    return $payload;
}, 10, 2 );
```

Do not change `metadata.order_id` or `metadata.order_key`. The plugin uses them to match webhooks to orders.

### Other filters

| Filter | Default | What it does |
|---|---|---|
| `casapay_wc_default_deposit_mode` | `deposit_upfront` | Option selected at checkout before the customer chooses |
| `casapay_wc_collect_personal_code` | `true` | Whether checkout asks for the personal ID code |

## REST (external systems)

```
GET /wp-json/casapay/v1/orders/{order_id}/agreement
```

Requires the `manage_woocommerce` capability. From an external system, use a WordPress application password with Basic auth:

```bash
curl -u 'api-user:xxxx xxxx xxxx xxxx' \
  https://shop.example/wp-json/casapay/v1/orders/1234/agreement
```

The response is the same as `casapay_wc_get_order_agreement()`, plus `order_id`. If the alias is missing, the endpoint asks CasaPay once before answering. An order without a CasaPay agreement returns `404 no_casapay_agreement`.

## Example: alias in the customer's order e-mail

```php
add_action( 'woocommerce_email_order_meta', function ( $order, $sent_to_admin, $plain_text ) {
    $alias = casapay_wc_get_order_email_alias( $order );
    if ( ! $alias ) {
        return; // The processing e-mail usually goes out before the alias exists.
    }
    echo $plain_text
        ? "\nCasaPay invoice address: {$alias}\n"
        : '<p>CasaPay invoice address: <strong>' . esc_html( $alias ) . '</strong></p>';
}, 20, 3 );
```

If the alias must be in an e-mail, send a separate e-mail from the `casapay_wc_agreement_ready` hook.

## Order meta keys

Prefer the functions above. The keys are listed for debugging and reporting. Do not write to them.

| Key | Content |
|---|---|
| `_casapay_session_id` | Latest gateway session (`gwy_…`) |
| `_casapay_payment_agreement_id` | CasaPay numeric agreement ID |
| `_casapay_agreement_ref` | Agreement reference (`PA-…`) |
| `_casapay_email_alias` | E-mail alias |
| `_casapay_agreement_type` | `payment_link` / `ontime` / `cover` |
| `_casapay_deposit_mode` | Deposit option chosen at checkout |
| `_casapay_resolved_mode` | Deposit option confirmed by CasaPay |
| `_casapay_guarantee_id` | Guarantee ID (`GRN-…`) |

## Don't

- Don't call the CasaPay API from another plugin with your own key. Use these functions, so the key stays in one place.
- Don't treat `success_url` or the thank-you page as proof of payment. Use the `casapay_wc_session_completed` hook or the order status.
- Don't store CasaPay's signed invoice PDF URLs (`/invoices/{id}/document`). They expire, so request a fresh one each time.
