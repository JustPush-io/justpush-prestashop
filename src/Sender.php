<?php
/**
 * Copyright © JustPush. MIT License.
 */

namespace JustPush\PrestaShop;

use PrestaShopLogger;

/**
 * POSTs payloads to webhook URLs. Never throws.
 *
 * Hooks only queue a payload. The queue is sent when PHP shuts down, after the response has
 * been handed to the visitor where the server allows it (PHP-FPM, LiteSpeed), so a slow or
 * unreachable JustPush never slows down checkout. On servers that can't finish the response
 * early (Apache mod_php) the visitor waits for the send, so it's capped at 2 seconds there.
 */
class Sender
{
    const VERSION = '1.0.0';
    const USER_AGENT = 'JustPush-PrestaShop/' . self::VERSION;

    /** Total seconds per request once the visitor has their response. */
    const BACKGROUND_TIMEOUT = 5;

    /** Total seconds per request when the visitor is still waiting. */
    const FOREGROUND_TIMEOUT = 2;

    /** @var array<int, array{url: string, body: array}> */
    private static $queue = [];

    /** @var bool */
    private static $registered = false;

    /**
     * Queues a payload for sending at the end of the request.
     *
     * @param string $url
     * @param array $body
     */
    public static function queue($url, array $body)
    {
        self::$queue[] = ['url' => $url, 'body' => $body];

        if (!self::$registered) {
            self::$registered = true;
            register_shutdown_function([self::class, 'flush']);
        }
    }

    /**
     * Sends everything queued. Called at shutdown.
     */
    public static function flush()
    {
        if (!self::$queue) {
            return;
        }

        $timeout = self::finishResponse() ? self::BACKGROUND_TIMEOUT : self::FOREGROUND_TIMEOUT;

        while ($message = array_shift(self::$queue)) {
            self::send($message['url'], $message['body'], $timeout);
        }
    }

    /**
     * Sends one payload now and returns the HTTP status, or 0 when the request failed.
     *
     * `sent_at` is filled in right before sending.
     *
     * @param string $url
     * @param array $body
     * @param int $timeout Total timeout in seconds
     *
     * @return int
     */
    public static function send($url, array $body, $timeout = self::BACKGROUND_TIMEOUT)
    {
        $event = isset($body['event']) ? (string) $body['event'] : 'unknown';

        try {
            if (array_key_exists('sent_at', $body)) {
                $body['sent_at'] = gmdate('Y-m-d\TH:i:s\Z');
            }

            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            if ($json === false) {
                self::log(sprintf('%s: could not encode the payload: %s', $event, json_last_error_msg()));

                return 0;
            }

            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => min($timeout, 2),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if ($response === false) {
                self::log(sprintf('%s to %s failed: %s', $event, $url, $error));

                return 0;
            }

            if ($status < 200 || $status >= 300) {
                self::log(sprintf('%s to %s answered HTTP %d: %s', $event, $url, $status, mb_substr((string) $response, 0, 300)));
            }

            return $status;
        } catch (\Throwable $e) {
            self::log(sprintf('%s to %s failed: %s', $event, $url, $e->getMessage()));

            return 0;
        }
    }

    /**
     * Hands the response to the visitor before sending, where the server supports that.
     *
     * @return bool Whether the visitor is no longer waiting
     */
    private static function finishResponse()
    {
        if (PHP_SAPI === 'cli') {
            return true;
        }

        if (function_exists('fastcgi_finish_request')) {
            return fastcgi_finish_request();
        }

        if (function_exists('litespeed_finish_request')) {
            return litespeed_finish_request();
        }

        return false;
    }

    /**
     * Logs to Advanced Parameters > Logs.
     *
     * @param string $message
     */
    public static function log($message)
    {
        try {
            PrestaShopLogger::addLog('JustPush: ' . $message, 3, null, 'JustPush', 0, true);
        } catch (\Throwable $e) {
            error_log('JustPush: ' . $message);
        }
    }
}
