<?php

return [
    App\Providers\SensitiveDataServiceProvider::class,   // field encryption: must be first (App\Support\Sensitive)
    App\Providers\AppServiceProvider::class,
];
