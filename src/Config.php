<?php
/**
 * Copyright © JustPush. MIT License.
 */

namespace JustPush\PrestaShop;

use Configuration;

/**
 * Reads and writes the module's settings (Modules > JustPush > Configure).
 *
 * Every setting is per shop, except the admin login URL: back-office logins don't belong to a
 * shop, so that one is stored globally.
 */
class Config
{
    const ENABLED = 'JUSTPUSH_ENABLED';
    const STOCK_THRESHOLD = 'JUSTPUSH_STOCK_THRESHOLD';
    const ADMIN_DIR = 'JUSTPUSH_ADMIN_DIR';
    const LOGIN_FAILURES = 'JUSTPUSH_LOGIN_FAILURES';

    /**
     * Payload event => configuration key of its webhook URL.
     */
    const EVENT_KEYS = [
        'order.placed' => 'JUSTPUSH_URL_ORDER_PLACED',
        'order.paid' => 'JUSTPUSH_URL_ORDER_PAID',
        'order.refunded' => 'JUSTPUSH_URL_ORDER_REFUNDED',
        'customer.registered' => 'JUSTPUSH_URL_CUSTOMER_REGISTERED',
        'message.received' => 'JUSTPUSH_URL_MESSAGE_RECEIVED',
        'stock.low' => 'JUSTPUSH_URL_STOCK_LOW',
        'admin.login' => 'JUSTPUSH_URL_ADMIN_LOGIN',
        'admin.login_failed' => 'JUSTPUSH_URL_ADMIN_LOGIN',
    ];

    const GLOBAL_KEYS = ['JUSTPUSH_URL_ADMIN_LOGIN', self::ADMIN_DIR, self::LOGIN_FAILURES];

    /**
     * Whether the module is switched on for a shop (null: the current shop context).
     *
     * @param int|null $idShop
     *
     * @return bool
     */
    public static function isEnabled($idShop = null)
    {
        return (bool) self::get(self::ENABLED, $idShop);
    }

    /**
     * The webhook URL for an event, or null when that event is turned off.
     *
     * @param string $event
     * @param int|null $idShop
     *
     * @return string|null
     */
    public static function webhookUrl($event, $idShop = null)
    {
        if (!isset(self::EVENT_KEYS[$event])) {
            return null;
        }

        $url = trim((string) self::get(self::EVENT_KEYS[$event], $idShop));

        return $url === '' ? null : $url;
    }

    /**
     * All filled-in webhook URLs for a shop, keyed by configuration key, without duplicates.
     *
     * @param int|null $idShop
     *
     * @return array<string, string>
     */
    public static function allWebhookUrls($idShop = null)
    {
        $urls = [];
        foreach (array_unique(self::EVENT_KEYS) as $event => $key) {
            $url = self::webhookUrl($event, $idShop);
            if ($url !== null) {
                $urls[$key] = $url;
            }
        }

        return $urls;
    }

    /**
     * Quantity at or below which `stock.low` fires for products without their own threshold.
     *
     * @param int|null $idShop
     *
     * @return int
     */
    public static function stockThreshold($idShop = null)
    {
        return (int) self::get(self::STOCK_THRESHOLD, $idShop);
    }

    /**
     * @param string $key
     * @param int|null $idShop
     *
     * @return mixed
     */
    public static function get($key, $idShop = null)
    {
        if (in_array($key, self::GLOBAL_KEYS, true)) {
            return Configuration::getGlobalValue($key);
        }

        if ($idShop === null) {
            return Configuration::get($key);
        }

        $shop = new \Shop((int) $idShop);

        return Configuration::get($key, null, (int) $shop->id_shop_group, (int) $idShop);
    }

    /**
     * Stores a value in the current shop context (or globally for the global keys).
     *
     * @param string $key
     * @param string|int $value
     *
     * @return bool
     */
    public static function set($key, $value)
    {
        if (in_array($key, self::GLOBAL_KEYS, true)) {
            return Configuration::updateGlobalValue($key, $value);
        }

        return Configuration::updateValue($key, $value);
    }

    /**
     * Every key the module owns, for uninstalling.
     *
     * @return string[]
     */
    public static function allKeys()
    {
        return array_values(array_unique(array_merge(
            [self::ENABLED, self::STOCK_THRESHOLD],
            array_values(self::EVENT_KEYS),
            self::GLOBAL_KEYS
        )));
    }
}
