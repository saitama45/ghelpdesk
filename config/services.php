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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    'google' => [
        'maps_api_key'  => env('GOOGLE_MAPS_API_KEY'),
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI'),
    ],

    'linkportal' => [
        'base_url' => env('LINKPORTAL_URL'),
        'token' => env('LINKPORTAL_API_TOKEN'),
        'review_sla_days' => env('LINKPORTAL_REVIEW_SLA_DAYS', 3),
        // Absolute path to the portal's public storage root, when both apps
        // share a filesystem. Set it and vendor documents are read straight off
        // disk; leave it unset and they are fetched over `base_url` instead.
        'documents_root' => env('LINKPORTAL_DOCUMENTS_ROOT'),
    ],

    // Shared keys for sibling apps calling /api/integrations/* server-to-server
    // (header X-Integration-Key). Empty = that integration is disabled.
    'integrations' => [
        'david' => [
            'key' => env('DAVID_INTEGRATION_KEY'),
            // This entity's Success Rate tally counts every company's tickets
            // (DAVID is only live for Nono's). Set it empty once each entity
            // should count only its own company's tickets.
            'all_tickets_entity' => env('DAVID_TALLY_ALL_TICKETS_ENTITY', 'NONOS'),
        ],
    ],

    // App Store / Play Store review accounts. A reviewer signs in with demo
    // credentials on a device whose mailbox they cannot read, so the post-login
    // email OTP would lock them out of the app entirely. Naming the demo
    // account here makes `Api\OtpController` issue the FIXED code below instead
    // of mailing a random one — the verification step still runs exactly as it
    // does for a member, it just has a code the reviewer was given in App Store
    // Connect. `email` may list several accounts separated by commas — the
    // review notes give one to keep and one to delete (`AppReviewAccounts`).
    // Leave either value unset in production-for-members deployments and the
    // allowlist is off.
    'app_review' => [
        'email' => env('APP_REVIEW_EMAIL'),
        'otp' => env('APP_REVIEW_OTP'),
    ],

    // The one account the delete icon on /users removes for GOOD instead of
    // archiving (`HardDeleteAccounts`). An exception by address, not a second
    // delete mode: every other login still goes to Settings → Account Archive.
    // Comma-separated if it ever needs a second address; an empty value turns
    // the exception off.
    'hard_delete_accounts' => [
        'email' => env('HARD_DELETE_ACCOUNT_EMAIL', 'garudaperez45+review@gmail.com'),
    ],

];
