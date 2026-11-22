<?php

/*
|--------------------------------------------------------------------------
| UI Lib licensing
|--------------------------------------------------------------------------
|
| Values from the UI Lib licensing integration guide (ui-lib.com). The
| public key only verifies the licence server's signed answers; it is
| public by design and never changes. The product ID identifies Stocky SaaS
| on ui-lib.com: a licence bought for another UI Lib product is refused
| with `wrong_product`, and a token earned by another product fails the
| offline verification. The licence belongs to the platform owner (central
| app), never to a tenant.
|
*/

return [
    'license_endpoint' => env('UILIB_LICENSE_ENDPOINT', 'https://ui-lib.com/wp-json/ul-license/v1/'),
    'license_public_key' => 'vh/l6RU6dvuxtofYIOUT0HT/mSRdGHbPDF5vVe9291U=',
    'license_product' => 'stocky-saas',

    // Days the platform keeps working after the signed token has expired
    // without any answer from ui-lib.com (outage, blocked network). A real
    // negative answer (refund, revoked, released) locks at once. Installs
    // that skipped activation in the installer get the same number of days
    // from install. 0 = lock as soon as the token expires, 30 at most.
    'grace_days' => (int) env('UILIB_GRACE_DAYS', 14),
];
