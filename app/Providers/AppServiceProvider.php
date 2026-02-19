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
        $sessionPath = storage_path('framework/sessions');
        if (!is_dir($sessionPath)) {
            mkdir($sessionPath, 0775, true);
        }

        Context::initialize(
            env('SHOPIFY_API_KEY'),
            env('SHOPIFY_API_SECRET'),
            env('SHOPIFY_APP_SCOPES'),
            str_replace(['https://', 'http://'], '', env('SHOPIFY_APP_URL')),
            new FileSessionStorage($sessionPath),
            '2026-01',
            true, // isEmbedded
            false // isPrivateApp
        );
    }
}
