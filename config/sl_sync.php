<?php

/**
 * Ramiz Application Sync Configuration
 * Do NOT rename these keys — they are referenced internally.
 * Do NOT commit this file or the .env values to version control.
 */
return [

    // Installation node identifier — provided by Ramiz after registration
    'node'    => env('APP_SYNC_NODE'),

    // Synchronization authentication token — provided by Ramiz after registration
    'token'   => env('APP_SYNC_TOKEN'),

    // Product channel identifier — provided by Ramiz
    'channel' => env('APP_SYNC_CHANNEL'),

];
