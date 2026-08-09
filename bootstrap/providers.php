<?php

// Telescope `require-dev` e ache, tai `composer install --no-dev` diye banano
// production image e oi class ta thake na. Shorto chhara register korle
// prottekta artisan command crash kore ("TelescopeApplicationServiceProvider not found").
// Tai class ta thakle tobei register kora hoy - dev e age jemon chhilo temon-i thake.
return array_values(array_filter([
    App\Providers\AppServiceProvider::class,
    class_exists(\Laravel\Telescope\TelescopeApplicationServiceProvider::class)
        ? App\Providers\TelescopeServiceProvider::class
        : null,
    Laravel\Reverb\ReverbServiceProvider::class,
]));
