<?php

return [
    'store_domain' => env('SHOPIFY_STORE_DOMAIN'),
    'access_token' => env('SHOPIFY_ACCESS_TOKEN'),
    'api_version'  => '2024-10',
    'verify_ssl' => env('SHOPIFY_VERIFY_SSL', env('APP_ENV') !== 'local'),
    'gold_api' => [

        /*
    |--------------------------------------------------------------------------
    | Angel One API URL
    |--------------------------------------------------------------------------
    */
        'url' => env('ANGEL_ONE_API_URL'),

        /*
    |--------------------------------------------------------------------------
    | Login Credentials
    |--------------------------------------------------------------------------
    */
        'client_code' => env('ANGEL_ONE_CLIENT_CODE'),

        'client_pin' => env('ANGEL_ONE_CLIENT_PIN'),

        /*
    |--------------------------------------------------------------------------
    | TOTP Secret
    |--------------------------------------------------------------------------
    */
        'totp_secret' => env('ANGEL_ONE_TOTP_SECRET'),

        /*
    |--------------------------------------------------------------------------
    | API Configuration
    |--------------------------------------------------------------------------
    */
        'private_key' => env('ANGEL_ONE_PRIVATE_KEY'),

        'mac_address' => env('ANGEL_ONE_MAC_ADDRESS'),

        'local_ip' => env('ANGEL_ONE_LOCAL_IP'),

        'public_ip' => env('ANGEL_ONE_PUBLIC_IP'),

        /*
    |--------------------------------------------------------------------------
    | Market Details
    |--------------------------------------------------------------------------
    */
        'exchange' => env('ANGEL_ONE_EXCHANGE', 'MCX'),

        'trading_symbol' => env('ANGEL_ONE_TRADING_SYMBOL'),

        'symbol_token' => env('ANGEL_ONE_SYMBOL_TOKEN'),
    ],
];
