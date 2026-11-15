<?php

declare(strict_types=1);

return [
    'routes' => [
        // Account & general settings
        ['name' => 'settings#getState', 'url' => '/api/settings/{scope}', 'verb' => 'GET'],
        ['name' => 'settings#saveAccount', 'url' => '/api/settings/{scope}/account', 'verb' => 'POST'],
        ['name' => 'settings#disconnectAccount', 'url' => '/api/settings/{scope}/account', 'verb' => 'DELETE'],
        ['name' => 'settings#testAccount', 'url' => '/api/settings/{scope}/test', 'verb' => 'POST'],
        ['name' => 'settings#saveGeneral', 'url' => '/api/settings/{scope}/general', 'verb' => 'POST'],

        // OAuth 2.0 flow
        ['name' => 'auth#authorize', 'url' => '/oauth/{scope}/authorize', 'verb' => 'GET'],
        ['name' => 'auth#callback', 'url' => '/oauth/callback', 'verb' => 'GET'],

        // Google Drive folder explorer
        ['name' => 'settings#listDriveFolders', 'url' => '/api/drive/{scope}/folders', 'verb' => 'GET'],

        // Volumes CRUD & verification
        ['name' => 'settings#listVolumes', 'url' => '/api/volumes/{scope}', 'verb' => 'GET'],
        ['name' => 'settings#createVolume', 'url' => '/api/volumes/{scope}', 'verb' => 'POST'],
        ['name' => 'settings#updateVolume', 'url' => '/api/volumes/{scope}/{id}', 'verb' => 'PUT'],
        ['name' => 'settings#deleteVolume', 'url' => '/api/volumes/{scope}/{id}', 'verb' => 'DELETE'],
        ['name' => 'settings#testVolume', 'url' => '/api/volumes/{scope}/{id}/test', 'verb' => 'POST'],

        // Metadata & path cache
        ['name' => 'settings#clearCache', 'url' => '/api/cache/{scope}/clear', 'verb' => 'POST'],
    ],
];
