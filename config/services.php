<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'mailgun' => [
        // OUTBOUND — correo transaccional real (MAIL_MAILER=mailgun).
        // Credenciales de API, NO la firma de webhook de abajo.
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',

        // INBOUND — firma HMAC de webhooks (InboundEmailService::verifyMailgunSignature).
        // Credencial distinta de domain/secret de arriba: esta NUNCA se usa para enviar.
        'webhook_signing_key' => env('MAILGUN_WEBHOOK_SIGNING_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'hcaptcha' => [
        'sitekey' => env('HCAPTCHA_SITEKEY'),
        'secret' => env('HCAPTCHA_SECRET'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI') ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/auth/google/callback',
    ],

    'azure' => [
        // Login con Outlook / Microsoft 365 (Azure AD) vía socialiteproviders/microsoft-azure.
        // Ver docs/MICROSOFT_LOGIN_SETUP.md para el registro de la app en Azure Portal.
        'client_id' => env('AZURE_CLIENT_ID'),
        'client_secret' => env('AZURE_CLIENT_SECRET'),
        'redirect' => env('AZURE_REDIRECT_URI') ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/auth/microsoft/callback',
        'tenant' => env('AZURE_TENANT_ID', 'common'),
    ],

    'google_maps' => [
        // Distinta del bloque 'google' de arriba (ese es OAuth login) --
        // esta es la key de Google Maps Platform. SERVER-SIDE, nunca se
        // manda al navegador (GoogleGeocodingService la usa vía HTTP).
        // Ver docs/GOOGLE_MAPS_SETUP.md.
        'server_key' => env('GOOGLE_MAPS_SERVER_KEY'),

        // Key para el iframe de Maps Embed API en el frontend -- Google
        // espera que esta SÍ sea visible en el HTML, se protege con
        // restricción de HTTP referrer en Cloud Console, no con secreto.
        // Puede ser la misma que server_key si server_key tiene ambas APIs
        // habilitadas y no te importa exponerla (no recomendado) -- lo
        // simple es usar dos keys separadas, cada una restringida distinto.
        'embed_key' => env('GOOGLE_MAPS_EMBED_KEY'),
    ],

];
