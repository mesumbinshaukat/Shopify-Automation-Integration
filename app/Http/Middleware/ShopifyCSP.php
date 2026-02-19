<?php

namespace App\Http\Middleware;

use Closure;

class ShopifyCSP
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        $shop = $request->get('shop');
        $cspDomain = $shop ? "https://{$shop}" : "https://*.myshopify.com";

        $response->header('Content-Security-Policy', "frame-ancestors {$cspDomain} https://admin.shopify.com;");

        return $response;
    }
}
