<?php

return [
    'http_cron_enabled' => env('WHATSAPP_HTTP_CRON_ENABLED', false),
    'provider' => env('WHATSAPP_PROVIDER', 'meta'),
    'gupshup_app_id' => env('GUPSHUP_APP_ID'),
    'gupshup_app_name' => env('GUPSHUP_APP_NAME'),
    'gupshup_source' => env('GUPSHUP_SOURCE'),
    'gupshup_api_key' => env('GUPSHUP_API_KEY'),
    'gupshup_auth_mode' => env('GUPSHUP_AUTH_MODE', 'auto'),
    'gupshup_customer_secret' => env('GUPSHUP_CUSTOMER_SECRET'),
    'gupshup_webhook_secret' => env('GUPSHUP_WEBHOOK_SECRET'),
    'gupshup_automation_enabled' => env('GUPSHUP_AUTOMATION_ENABLED', false),
    'enabled' => env('WHATSAPP_ENABLED', false),
    'api_version' => env('WHATSAPP_API_VERSION', 'v26.0'),
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
    'app_secret' => env('WHATSAPP_APP_SECRET'),
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    'default_country_code' => env('WHATSAPP_DEFAULT_COUNTRY_CODE', '91'),
];
