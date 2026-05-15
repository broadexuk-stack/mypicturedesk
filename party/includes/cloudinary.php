<?php
declare(strict_types=1);

// ============================================================
// includes/cloudinary.php — Cloudinary upload / delete helpers.
//
// Config (define in config.php on the server):
//   define('CLOUDINARY_CLOUD_NAME', 'your_cloud_name');
//   define('CLOUDINARY_API_KEY',    'your_api_key');
//   define('CLOUDINARY_API_SECRET', 'your_api_secret');
//
// Photos are stored at public_id: mypicturedesk/{slug}/{uuid}
// No file extension in the public_id — Cloudinary stores the
// original and serves in the best format via f_auto.
// ============================================================

function cloudinary_globally_configured(): bool {
    return defined('CLOUDINARY_CLOUD_NAME') && CLOUDINARY_CLOUD_NAME !== ''
        && defined('CLOUDINARY_API_KEY')    && CLOUDINARY_API_KEY    !== ''
        && defined('CLOUDINARY_API_SECRET') && CLOUDINARY_API_SECRET !== '';
}

// Build a signed parameter string for Cloudinary API calls.
function _cloudinary_sign(array $params): string {
    ksort($params);
    $str = '';
    foreach ($params as $k => $v) {
        $str .= ($str !== '' ? '&' : '') . $k . '=' . $v;
    }
    return sha1($str . CLOUDINARY_API_SECRET);
}

// Upload a local file to Cloudinary.
// Returns the decoded API response array on success, false on failure.
function cloudinary_upload(string $file_path, string $public_id): array|false {
    if (!cloudinary_globally_configured()) return false;

    $ts        = time();
    $sign_params = ['public_id' => $public_id, 'timestamp' => $ts];
    $signature = _cloudinary_sign($sign_params);

    $ch = curl_init(
        'https://api.cloudinary.com/v1_1/' . CLOUDINARY_CLOUD_NAME . '/image/upload'
    );
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'file'      => new CURLFile($file_path),
            'api_key'   => CLOUDINARY_API_KEY,
            'timestamp' => $ts,
            'public_id' => $public_id,
            'signature' => $signature,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body      = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $http_code !== 200) {
        error_log("cloudinary_upload: HTTP $http_code for public_id=$public_id");
        return false;
    }

    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['public_id'])) {
        error_log('cloudinary_upload: unexpected response: ' . substr($body, 0, 256));
        return false;
    }

    return $data;
}

// Delete a resource from Cloudinary by its public_id.
function cloudinary_delete(string $public_id): bool {
    if (!cloudinary_globally_configured()) return false;

    $ts          = time();
    $sign_params = ['public_id' => $public_id, 'timestamp' => $ts];
    $signature   = _cloudinary_sign($sign_params);

    $ch = curl_init(
        'https://api.cloudinary.com/v1_1/' . CLOUDINARY_CLOUD_NAME . '/image/destroy'
    );
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'public_id' => $public_id,
            'api_key'   => CLOUDINARY_API_KEY,
            'timestamp' => $ts,
            'signature' => $signature,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $body      = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string)$body, true);
    return $http_code === 200 && ($data['result'] ?? '') === 'ok';
}

// Canonical public_id for a photo: mypicturedesk/{slug}/{uuid}
function cloudinary_public_id(string $slug, string $uuid): string {
    return 'mypicturedesk/' . $slug . '/' . $uuid;
}

// CDN URL for the guest gallery thumbnail (600×600, face-aware fill).
function cloudinary_thumb_url(string $public_id): string {
    return 'https://res.cloudinary.com/' . CLOUDINARY_CLOUD_NAME
         . '/image/upload/w_600,h_600,c_fill,g_auto:faces,f_auto,q_auto/'
         . $public_id;
}

// CDN URL for the guest gallery full-size image.
function cloudinary_full_url(string $public_id): string {
    return 'https://res.cloudinary.com/' . CLOUDINARY_CLOUD_NAME
         . '/image/upload/f_auto,q_auto/'
         . $public_id;
}

// CDN URL for the admin grid thumbnail (300×300, face-aware fill).
function cloudinary_admin_thumb_url(string $public_id): string {
    return 'https://res.cloudinary.com/' . CLOUDINARY_CLOUD_NAME
         . '/image/upload/w_300,h_300,c_fill,g_auto:faces,f_auto,q_auto/'
         . $public_id;
}
