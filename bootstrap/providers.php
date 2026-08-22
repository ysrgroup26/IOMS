<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\InertiaServiceProvider;
use App\Providers\PaymentServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    InertiaServiceProvider::class,
    PaymentServiceProvider::class,
];
