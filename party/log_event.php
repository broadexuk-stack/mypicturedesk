<?php
declare(strict_types=1);

// ============================================================
// log_event.php — Receives client-side events from app.js and
// forwards them to Axiom via mpd_log(). The API key never
// reaches the browser — all Axiom calls happen server-side.
// Requires a valid guest CSRF token (same session as upload.php).
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/logger.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit(json_encode(['ok' => false]));
}

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403); exit(json_encode(['ok' => false]));
}

$event = trim($_POST['event'] ?? '');
if ($event === '' || strlen($event) > 100) {
    http_response_code(400); exit(json_encode(['ok' => false]));
}

// Decode client-supplied attributes — only accept shallow scalar values
$attrs = [];
try {
    $decoded = json_decode($_POST['attrs'] ?? '{}', true, 4, JSON_THROW_ON_ERROR);
    if (is_array($decoded)) {
        foreach ($decoded as $k => $v) {
            if (is_string($k) && strlen($k) <= 64 &&
                (is_string($v) || is_int($v) || is_float($v) || is_bool($v) || $v === null)) {
                $attrs[substr($k, 0, 64)] = is_string($v) ? substr($v, 0, 512) : $v;
            }
        }
    }
} catch (JsonException) {}

mpd_log($event, $attrs);

echo json_encode(['ok' => true]);
