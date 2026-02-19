<?php

/** @var \Laravel\Lumen\Routing\Router $router */

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

$router->get('/', ['as' => 'install', 'uses' => 'ShopifyController@install']);
$router->get('/auth/begin', ['as' => 'begin', 'uses' => 'ShopifyController@begin']);
$router->get('/auth/callback', ['as' => 'callback', 'uses' => 'ShopifyController@callback']);
$router->get('/dashboard', ['as' => 'dashboard', 'uses' => 'ShopifyController@dashboard']);

// API Routes
$router->group(['prefix' => 'api'], function ($router) {
    // Customers
    $router->group(['prefix' => 'customers'], function ($router) {
        $router->get('/', 'CustomerController@index');
        $router->post('/', 'CustomerController@store');
        $router->post('/sync', 'CustomerController@sync');
        $router->put('/{id}', 'CustomerController@update');
        $router->delete('/{id}', 'CustomerController@destroy');
        $router->put('/{id}/discount', 'CustomerController@updateDiscount');
        $router->post('/{id}/send-credentials', 'CustomerController@sendCredentials');
    });

    // Products
    $router->group(['prefix' => 'products'], function ($router) {
        $router->get('/', 'ProductController@index');
        $router->post('/sync', 'ProductController@sync');
        $router->put('/{id}', 'ProductController@update');
        $router->delete('/{id}', 'ProductController@destroy');
    });

    // Collections
    $router->group(['prefix' => 'collections'], function ($router) {
        $router->get('/', 'CollectionController@index');
        $router->post('/sync', 'CollectionController@sync');
        $router->put('/{id}', 'CollectionController@update');
        $router->delete('/{id}', 'CollectionController@destroy');
    });

    // Discount Lookup (for storefront/liquid)
    $router->get('/get-discount', 'DiscountController@getDiscount');
});
