<?php

return [
    'enabled' => env('WHATSAPP_ENABLED', false),
    'api_version' => env('WHATSAPP_API_VERSION', 'v26.0'),
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
    'app_secret' => env('WHATSAPP_APP_SECRET'),
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    'default_country_code' => env('WHATSAPP_DEFAULT_COUNTRY_CODE', '91'),
];
