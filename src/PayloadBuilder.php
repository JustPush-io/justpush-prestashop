<?php
/**
 * Copyright © JustPush. MIT License.
 */

namespace JustPush\PrestaShop;

use Carrier;
use Configuration;
use Contact;
use Context;
use Currency;
use Customer;
use CustomerMessage;
use CustomerThread;
use Db;
use Employee;
use Group;
use Order;
use Product;
use Shop;
use Validate;

/**
 * Builds the JSON bodies of the v1 payload contract (see README.md, "Payloads").
 *
 * Reads only what's already loaded or one cheap query away, so it's safe to call from
 * checkout hooks.
 */
class PayloadBuilder
{
    const VERSION = 1;
    const EXCERPT_LENGTH = 300;

    /**
     * Body for `order.placed` and `order.paid`.
     *
     * @param string $event
     * @param Order $order
     * @param \OrderState|null $state The order status, when the caller knows it
     *
     * @return array
     */
    public static function order($event, Order $order, $state = null)
    {
        if (!$state instanceof \OrderState || !Validate::isLoadedObject($state)) {
            $state = $order->current_state ? new \OrderState((int) $order->current_state) : null;
        }

        $customer = new Customer((int) $order->id_customer);
        $carrier = $order->id_carrier ? new Carrier((int) $order->id_carrier) : null;

        return self::envelope($event, (int) $order->id_shop, [
            'order' => [
                'id' => (int) $order->id,
                'reference' => (string) $order->reference,
                'total_paid' => self::money($order->total_paid_tax_incl),
                'currency' => self::currency((int) $order->id_currency),
                'customer_name' => self::name($customer->firstname, $customer->lastname),
                'customer_email' => $customer->email ?: null,
                'is_guest' => (bool) $customer->is_guest,
                'item_count' => self::itemCount((int) $order->id),
                'payment_method' => $order->payment ?: null,
                'carrier' => $carrier && Validate::isLoadedObject($carrier) && !$order->isVirtual() ? self::text($carrier->name) : null,
                'status' => $state ? self::text($state->name) : null,
                'paid' => $state ? (bool) $state->paid : null,
                'source' => self::source(),
                'admin_url' => self::adminUrl('index.php/sell/orders/' . (int) $order->id . '/view'),
            ],
        ]);
    }

    /**
     * Body for `order.refunded`, from the newest credit slip of the order.
     *
     * @param Order $order
     *
     * @return array|null Null when the order has no credit slip
     */
    public static function orderRefunded(Order $order)
    {
        $slip = Db::getInstance()->getRow(
            'SELECT `id_order_slip`, `total_products_tax_incl`, `total_shipping_tax_incl` FROM `' . _DB_PREFIX_ . 'order_slip`
            WHERE `id_order` = ' . (int) $order->id . ' ORDER BY `id_order_slip` DESC'
        );
        if (!$slip) {
            return null;
        }

        $customer = new Customer((int) $order->id_customer);

        return self::envelope('order.refunded', (int) $order->id_shop, [
            'refund' => [
                'id' => (int) $slip['id_order_slip'],
                'order_id' => (int) $order->id,
                'order_reference' => (string) $order->reference,
                'amount' => self::money((float) $slip['total_products_tax_incl'] + (float) $slip['total_shipping_tax_incl']),
                'order_total' => self::money($order->total_paid_tax_incl),
                'currency' => self::currency((int) $order->id_currency),
                'customer_name' => self::name($customer->firstname, $customer->lastname),
                'admin_url' => self::adminUrl('index.php/sell/orders/' . (int) $order->id . '/view'),
            ],
        ]);
    }

    /**
     * Body for `customer.registered`.
     *
     * @param Customer $customer
     *
     * @return array
     */
    public static function customerRegistered(Customer $customer)
    {
        $group = new Group((int) $customer->id_default_group, (int) Configuration::get('PS_LANG_DEFAULT'));

        return self::envelope('customer.registered', (int) $customer->id_shop, [
            'customer' => [
                'id' => (int) $customer->id,
                'name' => self::name($customer->firstname, $customer->lastname),
                'email' => $customer->email ?: null,
                'group' => Validate::isLoadedObject($group) ? self::text($group->name) : null,
                'newsletter' => (bool) $customer->newsletter,
                'source' => self::source(),
                'admin_url' => self::adminUrl('index.php/sell/customers/' . (int) $customer->id . '/view'),
            ],
        ]);
    }

    /**
     * Body for `message.received`: a customer wrote through the contact form or an order page.
     *
     * @param CustomerMessage $message
     *
     * @return array|null Null when the thread can't be loaded
     */
    public static function messageReceived(CustomerMessage $message)
    {
        $thread = new CustomerThread((int) $message->id_customer_thread);
        if (!Validate::isLoadedObject($thread)) {
            return null;
        }

        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $customer = $thread->id_customer ? new Customer((int) $thread->id_customer) : null;
        $contact = $thread->id_contact ? new Contact((int) $thread->id_contact, $idLang) : null;
        $order = $thread->id_order ? new Order((int) $thread->id_order) : null;
        $idProduct = (int) ($message->id_product ?: $thread->id_product);

        return self::envelope('message.received', (int) $thread->id_shop, [
            'customer_message' => [
                'id' => (int) $message->id,
                'thread_id' => (int) $thread->id,
                'customer_name' => $customer && Validate::isLoadedObject($customer) ? self::name($customer->firstname, $customer->lastname) : null,
                'email' => $thread->email ?: null,
                'contact' => $contact && Validate::isLoadedObject($contact) ? self::text($contact->name) : null,
                'order_reference' => $order && Validate::isLoadedObject($order) ? (string) $order->reference : null,
                'product' => $idProduct ? (self::text(Product::getProductName($idProduct, null, $idLang)) ?: null) : null,
                'excerpt' => self::excerpt((string) $message->message),
                'has_attachment' => (string) $message->file_name !== '',
                'admin_url' => self::adminUrl('index.php?controller=AdminCustomerThreads&id_customer_thread=' . (int) $thread->id . '&viewcustomer_thread'),
            ],
        ]);
    }

    /**
     * Body for `stock.low`.
     *
     * @param int $idProduct
     * @param int $idProductAttribute
     * @param int $quantity
     * @param int $threshold
     * @param int $idShop
     *
     * @return array
     */
    public static function stockLow($idProduct, $idProductAttribute, $quantity, $threshold, $idShop)
    {
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $reference = Db::getInstance()->getValue($idProductAttribute
            ? 'SELECT `reference` FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product_attribute` = ' . (int) $idProductAttribute
            : 'SELECT `reference` FROM `' . _DB_PREFIX_ . 'product` WHERE `id_product` = ' . (int) $idProduct);

        $path = version_compare(_PS_VERSION_, '9.0.0', '>=')
            ? 'index.php/sell/catalog/products/' . (int) $idProduct . '/edit'
            : 'index.php?controller=AdminProducts&id_product=' . (int) $idProduct . '&updateproduct';

        return self::envelope('stock.low', (int) $idShop, [
            'product' => [
                'id' => (int) $idProduct,
                'combination_id' => $idProductAttribute ? (int) $idProductAttribute : null,
                'name' => self::text(Product::getProductName((int) $idProduct, $idProductAttribute ?: null, $idLang)) ?: null,
                'reference' => $reference ? (string) $reference : null,
                'quantity' => (int) $quantity,
                'threshold' => (int) $threshold,
                'admin_url' => self::adminUrl($path),
            ],
        ]);
    }

    /**
     * Body for `admin.login`.
     *
     * @param Employee $employee
     *
     * @return array
     */
    public static function adminLogin(Employee $employee)
    {
        return self::envelope('admin.login', null, [
            'admin' => [
                'email' => (string) $employee->email,
                'name' => self::name($employee->firstname, $employee->lastname),
                'ip' => self::remoteIp(),
                'admin_url' => self::adminUrl('index.php/configure/advanced/employees/' . (int) $employee->id . '/edit'),
            ],
        ]);
    }

    /**
     * Body for `admin.login_failed`.
     *
     * @param string $email What was typed in the email field
     * @param int $attempts Failed attempts for this address in the current throttle window
     *
     * @return array
     */
    public static function adminLoginFailed($email, $attempts)
    {
        return self::envelope('admin.login_failed', null, [
            'admin' => [
                'email' => mb_substr((string) $email, 0, 255),
                'ip' => self::remoteIp(),
                'attempts' => (int) $attempts,
                'admin_url' => self::adminUrl('index.php/configure/advanced/employees/'),
            ],
        ]);
    }

    /**
     * Body for `test.ping`.
     *
     * @param int|null $idShop
     *
     * @return array
     */
    public static function testPing($idShop)
    {
        return self::envelope('test.ping', $idShop, [
            'message' => 'Test from PrestaShop',
        ]);
    }

    /**
     * Common fields of every body. `sent_at` is set again by the Sender right before sending.
     *
     * @param string $event
     * @param int|null $idShop Null means the default shop
     * @param array $data
     *
     * @return array
     */
    private static function envelope($event, $idShop, array $data)
    {
        return [
            'event' => $event,
            'version' => self::VERSION,
            'sent_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'shop' => self::shopInfo($idShop),
        ] + $data;
    }

    /**
     * The `shop` block.
     *
     * @param int|null $idShop
     *
     * @return array
     */
    private static function shopInfo($idShop)
    {
        $idShop = $idShop ?: (int) Configuration::get('PS_SHOP_DEFAULT');
        $shop = new Shop((int) $idShop);
        if (!Validate::isLoadedObject($shop)) {
            return ['name' => null, 'url' => null];
        }

        $name = Configuration::get('PS_SHOP_NAME', null, (int) $shop->id_shop_group, (int) $shop->id);

        return [
            'name' => $name ? (string) $name : (string) $shop->name,
            'url' => self::shopUrl($shop) . $shop->getBaseURI(),
        ];
    }

    /**
     * Protocol and domain of a shop, without trailing slash.
     *
     * @param Shop $shop
     *
     * @return string
     */
    private static function shopUrl(Shop $shop)
    {
        return Configuration::get('PS_SSL_ENABLED') && $shop->domain_ssl
            ? 'https://' . $shop->domain_ssl
            : 'http://' . $shop->domain;
    }

    /**
     * A back-office link on the default shop's domain, or null when the admin folder is unknown.
     *
     * The admin folder is only known inside the back office, so the module remembers it when
     * it's installed or configured. The link has no security token, so PrestaShop first shows
     * an "Invalid token" page with a link to continue.
     *
     * @param string $path Relative to the admin folder
     *
     * @return string|null
     */
    private static function adminUrl($path)
    {
        $dir = trim((string) Config::get(Config::ADMIN_DIR), '/');
        if ($dir === '') {
            return null;
        }

        $shop = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
        if (!Validate::isLoadedObject($shop)) {
            return null;
        }

        return self::shopUrl($shop) . $shop->physical_uri . $dir . '/' . $path;
    }

    /**
     * Where the event came from: frontend, admin, api, or null (cron, CLI, unknown).
     *
     * @return string|null
     */
    private static function source()
    {
        if (defined('_PS_API_IN_USE_') && _PS_API_IN_USE_) {
            return 'api';
        }
        if (defined('_PS_APP_ID_') && strpos((string) _PS_APP_ID_, 'api') !== false) {
            return 'api';
        }
        if (defined('_PS_ADMIN_DIR_')) {
            return 'admin';
        }

        $controller = Context::getContext()->controller;
        if ($controller instanceof \FrontController || $controller instanceof \ModuleFrontController) {
            return 'frontend';
        }

        return null;
    }

    /**
     * Total quantity of all order lines.
     *
     * @param int $idOrder
     *
     * @return int
     */
    private static function itemCount($idOrder)
    {
        return (int) Db::getInstance()->getValue(
            'SELECT SUM(`product_quantity`) FROM `' . _DB_PREFIX_ . 'order_detail` WHERE `id_order` = ' . (int) $idOrder
        );
    }


    /**
     * @param int $idCurrency
     *
     * @return string|null
     */
    private static function currency($idCurrency)
    {
        $currency = Currency::getCurrencyInstance($idCurrency);

        return Validate::isLoadedObject($currency) ? (string) $currency->iso_code : null;
    }

    /**
     * Money as a decimal string, e.g. "49.00".
     *
     * @param float|string|null $amount
     *
     * @return string|null
     */
    private static function money($amount)
    {
        return $amount === null ? null : number_format((float) $amount, 2, '.', '');
    }

    /**
     * @param string|null $first
     * @param string|null $last
     *
     * @return string|null
     */
    private static function name($first, $last)
    {
        $name = trim(trim((string) $first) . ' ' . trim((string) $last));

        return $name !== '' ? $name : null;
    }

    /**
     * A multilingual field in the default language, or a plain string.
     *
     * @param string|array|null $value
     *
     * @return string|null
     */
    public static function text($value)
    {
        if (is_array($value)) {
            $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
            $value = isset($value[$idLang]) ? $value[$idLang] : reset($value);
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * The start of a message as plain text on one line.
     *
     * @param string $message
     *
     * @return string|null
     */
    private static function excerpt($message)
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($message), ENT_QUOTES, 'UTF-8')));
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > self::EXCERPT_LENGTH
            ? rtrim(mb_substr($text, 0, self::EXCERPT_LENGTH - 1)) . '…'
            : $text;
    }

    /**
     * The visitor's IP as PrestaShop sees it. Behind a proxy or CDN this may be the proxy's.
     *
     * @return string|null
     */
    private static function remoteIp()
    {
        $ip = \Tools::getRemoteAddr();

        return is_string($ip) && $ip !== '' ? $ip : null;
    }
}
