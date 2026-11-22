<?php
// Copy this file to config.php and edit it.
return [
    // Shown in the header, page titles and embeds.
    'site_name' => 'Pastey',

    // Full public address of the site, no trailing slash.
    // Leave empty to detect it from the request.
    'base_url' => '',

    // Password for uploading. Either plain text, or (better) a hash made with:
    //   php -r 'echo password_hash("your-password", PASSWORD_DEFAULT), "\n";'
    'password' => 'change-me',

    // Largest file you can upload, in megabytes (after in-browser conversion).
    'max_upload_mb' => 2048,

    // Size of each upload piece in megabytes. The uploader automatically
    // shrinks this if your host rejects big requests.
    'chunk_mb' => 8,

    // Longest side of converted videos, in pixels. 1920 = 1080p.
    'max_dimension' => 1920,
];
