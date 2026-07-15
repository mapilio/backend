<?php

use App\Support\Network\TrustedProxyConfiguration;

return [
    'proxies' => TrustedProxyConfiguration::parse(env('MAPILIO_TRUSTED_PROXIES')),
];
