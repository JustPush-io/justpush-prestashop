<?php
/**
 * Copyright © JustPush. MIT License.
 */

namespace JustPush\PrestaShop;

/**
 * Limits `admin.login_failed` to one message per 15 minutes per email address.
 *
 * Every failure is counted. When a message may go out, it carries the number of failures
 * since the previous message for that address, including the current one. The counters live
 * in one global configuration value; entries older than a day are dropped.
 */
class LoginThrottle
{
    const WINDOW = 900;
    const FORGET_AFTER = 86400;
    const MAX_ENTRIES = 200;

    /**
     * Records one failed login. Returns the attempts count to send, or null to stay quiet.
     *
     * @param string $email
     * @param int|null $now Unix time, for tests
     *
     * @return int|null
     */
    public static function registerFailure($email, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $key = sha1(mb_strtolower(trim($email)));

        $all = json_decode((string) Config::get(Config::LOGIN_FAILURES), true);
        $all = is_array($all) ? $all : [];

        $state = isset($all[$key]) && is_array($all[$key]) ? $all[$key] : ['last_sent' => null, 'pending' => 0];
        $state['pending'] = (int) $state['pending'] + 1;

        $attempts = null;
        if ($state['last_sent'] === null || $now - (int) $state['last_sent'] >= self::WINDOW) {
            $attempts = $state['pending'];
            $state = ['last_sent' => $now, 'pending' => 0];
        }
        $all[$key] = $state;

        foreach ($all as $k => $entry) {
            if ($now - (int) (isset($entry['last_sent']) ? $entry['last_sent'] : 0) > self::FORGET_AFTER && empty($entry['pending'])) {
                unset($all[$k]);
            }
        }
        $all = array_slice($all, -self::MAX_ENTRIES, null, true);

        Config::set(Config::LOGIN_FAILURES, json_encode($all));

        return $attempts;
    }
}
