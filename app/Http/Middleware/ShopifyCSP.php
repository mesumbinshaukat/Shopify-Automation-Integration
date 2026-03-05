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

        if ($response instanceof \Symfony\Component\HttpFoundation\Response) {
            $shop = $request->get('shop');
            $cspDomain = $shop ? "https://{$shop}" : "https://*.myshopify.com";
            $response->headers->set('Content-Security-Policy', "frame-ancestors {$cspDomain} https://admin.shopify.com;");
        }

        return $response;
    }
}
