<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Shopify\Context;
use Shopify\Auth\FileSessionStorage;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        Context::initialize(
            env('SHOPIFY_API_KEY'),
            env('SHOPIFY_API_SECRET'),
            env('SHOPIFY_APP_SCOPES'),
            str_replace(['https://', 'http://'], '', env('SHOPIFY_APP_URL')),
            new \App\Services\DatabaseSessionStorage(),
            '2026-01',
            true, // isEmbedded
            false // isPrivateApp
        );
    }
}
