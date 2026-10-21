<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Read by Laravel's TrustProxies middleware. Leave SOLQUBIT_TRUSTED_PROXIES
    | empty when PHP receives visitors directly or through a web server on the
    | same machine (CloudPanel, HestiaCP, Plesk...). Behind Cloudflare or a load
    | balancer, list its addresses separated by commas, or use * only when the
    | server accepts traffic exclusively from that proxy.
    |
    */

    'proxies' => env('SOLQUBIT_TRUSTED_PROXIES') ?: null,

];
