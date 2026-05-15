<?php
declare(strict_types=1);

// ============================================================
// includes/logger.php — Async event logger → Axiom
//
// Events are queued in memory and flushed to Axiom's ingest API
// after the HTTP response is sent (via fastcgi_finish_request on
// PHP-FPM, or register_shutdown_function on mod_php).
//
// Field names follow OpenTelemetry semantic conventions.
// Config: define AXIOM_API_KEY and AXIOM_DATASET in config.php.
// ============================================================

final class MpdLogger
{
    private static array $queue      = [];
    private static bool  $registered = false;

    public static function queue(string $event, array $attrs = []): void
    {
        if (!defined('AXIOM_API_KEY') || AXIOM_API_KEY === '') return;

        if (!self::$registered) {
            register_shutdown_function([self::class, 'flush']);
            self::$registered = true;
        }

        self::$queue[] = array_merge([
            '_time'        => gmdate('Y-m-d\TH:i:s.v\Z'),
            'service.name' => 'mypicturedesk',
            'event.name'   => $event,
        ], $attrs);
    }

    public static function flush(): void
    {
        if (empty(self::$queue) || !defined('AXIOM_API_KEY') || AXIOM_API_KEY === '') return;

        // PHP-FPM: close the connection to the client before the curl call
        // so logging doesn't add latency to the response.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        $payload     = json_encode(self::$queue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::$queue = [];

        $ch = curl_init('https://api.axiom.co/v1/datasets/' . AXIOM_DATASET . '/ingest');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . AXIOM_API_KEY,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

// Procedural wrapper — call this anywhere in the app.
function mpd_log(string $event, array $attrs = []): void
{
    MpdLogger::queue($event, $attrs);
}
