<p align="center"><img src="https://cdn.justpush.io/core/app%20icon_nobackground.svg" width="120" height="auto"></p>

# JustPush for PrestaShop

Get a push notification on your phone when something happens in your PrestaShop shop: a new
order, a payment, a refund, a new customer, a customer message, low stock or an admin login.

This module sends a small JSON message to [JustPush Studio](https://studio.justpush.io) for
each event. A PrestaShop recipe in Studio turns that message into a push notification.

- Works on **PrestaShop 8 and 9**, with PHP 7.2.5 or newer (whatever your PrestaShop version
  supports).
- Sends after the visitor has their page, so a slow or unreachable JustPush never slows down
  or breaks checkout. No cron job needed.
- Sends only the fields listed under [Payloads](#payloads). No addresses, phone numbers or
  order lines.

## Install

1. Download `justpush.zip` from the
   [latest release](https://github.com/JustPush-io/justpush-prestashop/releases/latest).
2. In the back office, open **Modules → Module Manager → Upload a module** and drop the zip in.
3. Click **Configure**.

Don't use GitHub's "Download ZIP" button: that archive lacks the `vendor/` autoloader and has
the wrong folder name. To install from source, clone the repository into `modules/justpush/`
and run `composer dump-autoload --optimize --no-dev` there.

## Set up

1. In JustPush Studio, install a PrestaShop recipe (for example **PrestaShop: order placed**)
   and copy its webhook URL.
2. In the back office, open **Modules → Module Manager**, find **JustPush notifications** and
   click **Configure**.
3. Make sure **Enabled** is on, paste the URL into the matching field, e.g. **Order placed**,
   and click **Save**.
4. Click **Send test**. Each recipe answers with a quiet "🔔 PrestaShop connected".

Each event has its own URL field; leave a field empty to turn that event off. With multistore
on, all settings can be changed per shop (pick the shop at the top of the page), except
**Admin login**, which applies to all shops because back-office logins don't belong to one.

### Low stock

`stock.low` fires once when a product, or a combination of a product with combinations, drops
to its **Low stock level** (set on the product's or combination's stock tab) or below. Products
without their own level use **Default low-stock threshold** from the module settings; the
default `0` means "when it sells out". It doesn't fire again while the stock stays low, only
after it has gone back above the level and drops again.

### How sending works

Events are queued during the request and sent when PHP shuts down. On PHP-FPM and LiteSpeed,
which is almost every host, the visitor gets their page first and the module then sends with a
5-second limit per message. On Apache with mod_php the visitor would wait, so the limit there
is 2 seconds.

A message that can't be delivered is logged and dropped, not retried.

### Logging

Failures are written to **Advanced Parameters → Logs**, with the object type `JustPush`. The
module never throws errors into PrestaShop.

## Events

| Event | Sent when | PrestaShop hook |
| --- | --- | --- |
| `order.placed` | An order is placed from the storefront, the back office or the webservice. A cart split over several carriers sends one message per order. | `actionValidateOrder` |
| `order.paid` | An order gets a paid status for the first time: **Payment accepted**, **Remote payment accepted**, or any status with "Consider the associated order as paid". Useful for bank wire and other offline payments. | `actionOrderStatusPostUpdate` |
| `order.refunded` | A credit slip is created: a partial refund, a standard refund or a product return. | `actionOrderSlipAdd` |
| `customer.registered` | A new customer account is created from the storefront, the back office or the webservice. Guest checkouts are skipped. | `actionObjectCustomerAddAfter` |
| `message.received` | A customer writes through the contact form, their order history or a checkout comment. Employee replies and private notes are skipped. | `actionObjectCustomerMessageAddAfter` |
| `stock.low` | A product or combination drops to its low-stock level, see [Low stock](#low-stock). | `actionUpdateQuantity` |
| `admin.login` | Someone logs in to the back office. | PrestaShop 8: `actionAdminLoginControllerLoginAfter`; PrestaShop 9: Symfony `LoginSuccessEvent` |
| `admin.login_failed` | A back-office login fails. At most one message per 15 minutes per email address; `attempts` counts the failures since the previous message. | PrestaShop 8: `actionAdminLoginControllerLoginBefore`; PrestaShop 9: Symfony `LoginFailureEvent` |
| `test.ping` | You click **Send test**. | none |

## Payloads

Every message is a JSON `POST` with `Content-Type: application/json` and the user agent
`JustPush-PrestaShop/1.0.0`. It always contains `event`, `version` (currently `1`), `sent_at`
(ISO 8601, UTC) and `shop`. Money is a decimal string such as `"54.74"` in the order's
currency, with `currency` as an ISO code. A field that isn't known is `null`.

Real examples of every event are in [`docs/payloads/`](docs/payloads/). They were captured
from a PrestaShop 9.2 test shop running at `http://localhost:8082/`.

```json
{
    "event": "order.placed",
    "version": 1,
    "sent_at": "2026-10-03T18:10:02Z",
    "shop": {
        "name": "PrestaShop",
        "url": "http://localhost:8082/"
    },
    "order": {
        "id": 6,
        "reference": "FGIRRLHSH",
        "total_paid": "54.74",
        "currency": "EUR",
        "customer_name": "Jane Doe",
        "customer_email": "jane@example.com",
        "is_guest": true,
        "item_count": 2,
        "payment_method": "Bank transfer",
        "carrier": "My carrier",
        "status": "Awaiting bank wire payment",
        "paid": false,
        "source": "frontend",
        "admin_url": "http://localhost:8082/admin-dev/index.php/sell/orders/6/view"
    }
}
```

- `source` is `frontend`, `admin`, `api` (webservice), or `null` when PrestaShop can't tell,
  e.g. for an order created by a cron job.
- `total_paid` includes tax and shipping. `carrier` is `null` for orders with only virtual
  products. `status` and other translated names are in the shop's default language.
- `order.paid` has the same `order` block as `order.placed`.
- `order.refunded` has `refund: { id, order_id, order_reference, amount, order_total, currency, customer_name, admin_url }`.
  `amount` is the credit slip's products plus shipping, including tax.
- `customer.registered` has `customer: { id, name, email, group, newsletter, source, admin_url }`.
- `message.received` has `customer_message: { id, thread_id, customer_name, email, contact, order_reference, product, excerpt, has_attachment, admin_url }`.
  `excerpt` is the first 300 characters of the message as plain text. `customer_name` is
  `null` when the sender isn't logged in.
- `stock.low` has `product: { id, combination_id, name, reference, quantity, threshold, admin_url }`.
  For a combination, `name` includes its attributes, e.g. "Hummingbird printed t-shirt : Color - White, Size - S".
- `admin.login` has `admin: { email, name, ip, admin_url }`; `admin.login_failed` has
  `admin: { email, ip, attempts, admin_url }`. `email` is whatever was typed in the login form.
- `test.ping` has `shop` and `message: "Test from PrestaShop"`.

### Admin links

`admin_url` points at the order, customer, message thread, product or employee. The back-office
folder name (e.g. `admin-dev` or `admin123abc`) is only known inside the back office, so the
module remembers it when it's installed, configured or someone logs in; until then `admin_url`
is `null`. The links have no security token, so PrestaShop first shows an "Invalid token" page
with a link to continue. That's a PrestaShop security feature, so the module doesn't work
around it.

### IP addresses

`ip` in the admin events is the address PrestaShop sees. If your shop sits behind a proxy, load
balancer or CDN such as Cloudflare, that may be the proxy's address, not the visitor's.

## Privacy (GDPR)

The order, refund, customer and message payloads contain the customer's name and email address,
and messages contain the start of what the customer wrote. Admin payloads contain the
employee's email, name and IP address. As the shop owner you're the data controller for sending
these to JustPush. Leave an event's URL empty if you don't want to send it. The module sends
nothing else: no addresses, phone numbers, order lines or payment details.

## Development

The test shop is a Docker Compose setup with this repository mounted at `modules/justpush/`.
CI lints every PHP file on PHP 7.2 to 8.4:

```bash
composer validate --strict
composer dump-autoload --optimize --no-dev
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
```

To release, bump `$this->version` in `justpush.php` and `Sender::VERSION`, then push a tag such
as `v1.0.1`. The release workflow builds `justpush.zip` and attaches it to a GitHub release.

## Uninstall

In **Modules → Module Manager**, open the module's menu and choose **Uninstall**. This removes
all its settings.

<!-- justpush-packages:start -->
## JustPush products

**Apps**
- [JustPush for iOS](https://apps.apple.com/us/app/justpush-io/id6738397112)
- [JustPush for Android](https://play.google.com/store/apps/details?id=com.justpush)
- [JustPush Studio](https://studio.justpush.io): build integrations that turn webhooks into notifications
- [Recipes](https://justpush.io/recipes): ready-made integrations for Shopify, Magento and more
- [Playground](https://playground.justpush.io): try the API in your browser

**SDKs**
- [PHP](https://github.com/JustPush-io/justpush-sdk-php) · [Packagist](https://packagist.org/packages/justpush/justpush-php-sdk)
- [JavaScript](https://github.com/JustPush-io/justpush-sdk-js) · [npm](https://www.npmjs.com/package/@justpush.io/justpush-js-sdk)
- [TypeScript](https://github.com/JustPush-io/justpush-sdk-ts) · [npm](https://www.npmjs.com/package/@justpush.io/justpush-ts-sdk)
- [Python](https://github.com/JustPush-io/justpush-sdk-python) · [PyPI](https://pypi.org/project/justpush/)
- [Go](https://github.com/JustPush-io/justpush-go-sdk) · [pkg.go.dev](https://pkg.go.dev/github.com/JustPush-io/justpush-go-sdk)

**Packages**
- [Laravel notification channel](https://github.com/JustPush-io/justpush-laravel-notifications) · [Packagist](https://packagist.org/packages/justpush/laravel-notification-channel)
- [Magento 2](https://github.com/JustPush-io/justpush-magento) · [Packagist](https://packagist.org/packages/justpush/magento-module-notify)
- [PrestaShop](https://github.com/JustPush-io/justpush-prestashop) · [Download](https://github.com/JustPush-io/justpush-prestashop/releases/latest) (this one)

**Developer tools**
- [API reference](https://docs.justpush.io/introduction/dev-tools/api-reference) · [OpenAPI](https://docs.justpush.io/introduction/dev-tools/openapi)
- [Postman collection](https://github.com/JustPush-io/postman) · [Paw collection](https://github.com/JustPush-io/paw)
- [MCP server for Claude](https://docs.justpush.io/introduction/dev-tools/mcp-server)
<!-- justpush-packages:end -->

## License

MIT, see [LICENSE](LICENSE).
