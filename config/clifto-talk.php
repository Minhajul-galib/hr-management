<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    | The prefix is used for both the URLs and the route names, and it is
    | published to the frontend so the JS client never hardcodes a path.
    */

    'route_prefix' => env('CLIFTO_TALK_PREFIX', 'clifto-talk'),

    'middleware' => ['auth'],

    /*
    |--------------------------------------------------------------------------
    | Access control
    |--------------------------------------------------------------------------
    | Name of the ability that grants access to the chat. Set to null to let
    | every authenticated user in — do that when the host app has no
    | permission package installed.
    */

    'permission' => env('CLIFTO_TALK_PERMISSION', 'access clifto talk'),

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    */

    'brand' => env('CLIFTO_TALK_BRAND', 'Clifto Talk'),

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    | Files are stored on a private disk and streamed through an authorised
    | route, so membership is checked on every read.
    */

    'attachments' => [
        'disk' => env('CLIFTO_TALK_DISK', 'local'),
        'path' => 'clifto-talk',
        'max_kb' => 20480,
        'max_per_message' => 10,
    ],

    /*
    | Group avatars are public images, so they live on the public disk.
    */

    'avatar_disk' => 'public',

    /*
    |--------------------------------------------------------------------------
    | Behaviour
    |--------------------------------------------------------------------------
    */

    // Messages fetched per page when scrolling back through history.
    'page_size' => 30,

    // How long after sending a message it can still be edited.
    'edit_window_minutes' => 1440,

    // Conversations loaded into the sidebar on boot.
    'conversation_limit' => 100,

    /*
    |--------------------------------------------------------------------------
    | User directory
    |--------------------------------------------------------------------------
    | Columns searched by "New chat", and an optional relation shown as the
    | subtitle under each name. Set `subtitle_relation` to null if the host
    | app's User model has no such relation.
    */

    'directory' => [
        'search_columns' => ['name', 'email'],
        'subtitle_relation' => 'department',
        'subtitle_column' => 'name',
        'limit' => 30,
    ],

];
