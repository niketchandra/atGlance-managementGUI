<?php

/*
| Offline licence files (.atgl). Format: docs/LICENSING.md.
|
| The keyring (key_id => Ed25519 public key + AES key) is embedded in the image at build
| time from config/license-keyring.json (Docker build arg ATGLANCE_LICENSE_KEYRING). It is
| deliberately not read from the runtime environment: whoever controls a console's env
| could otherwise add their own key and sign their own licences.
*/

$keyringFile = __DIR__ . '/license-keyring.json';
$keyring = is_file($keyringFile) ? json_decode((string) file_get_contents($keyringFile), true) : null;

return [

    // key_id => ['public_key' => base64 (32 bytes), 'enc_key' => hex (32 bytes)]
    'keyring' => is_array($keyring['keys'] ?? null) ? $keyring['keys'] : [],

    // Licence file, activation record and clock record (on the app-storage volume).
    'path' => storage_path('app/license'),

    // Optional binding: show this console's Installation ID in the installer so it can be
    // entered on atglance.live. Admin Settings > Licence always shows it. A file that contains
    // an install_id is always checked against it, whatever this setting.
    'show_install_id' => (bool) env('ATGLANCE_LICENSE_SHOW_INSTALL_ID', false),

    // Expiry banners appear from these many days before expiry.
    'warn_days' => [30, 7, 1],

    // Activation is refused when the clock is this far behind the latest recorded time.
    'clock_rollback_seconds' => 86400,

    'portal_url' => env('ATGLANCE_LICENSE_PORTAL_URL', 'https://atglance.live'),

    // Licence key API (online activation; consoles with internet access only). atglance.live
    // answers an activation with the licence file, which is then checked offline like an upload.
    'api_url' => env('ATGLANCE_LICENSE_API_URL', rtrim((string) env('ATGLANCE_LICENSE_PORTAL_URL', 'https://atglance.live'), '/') . '/api/licenses'),

];
