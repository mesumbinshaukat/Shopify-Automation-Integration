<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use Shopify\Rest\Admin2026_01\Product as ShopifyProduct;

class ProductController extends Controller
{
    public function index()
    {
        return response()->json(Product::all());
    }

    public function sync(Request $request)
    {
        $shop = $request->get('shop');
        if (!$shop) {
            return response()->json(['error' => 'Missing shop'], 400);
        }

        $session = \App\Services\ShopifyService::loadSession($shop);
        if (!$session) {
            return response()->json(['error' => 'No session found for ' . $shop], 401);
        }

        try {
            $products = ShopifyProduct::all($session);
            
            foreach ($products as $sProd) {
                Product::updateOrCreate(
                    ['shopify_id' => $sProd->id],
                    [
                        'title' => $sProd->title,
                        'handle' => $sProd->handle,
                        'image_url' => $sProd->images[0]->src ?? null,
                    ]
                );
            }

            return response()->json(['message' => 'Product sync completed', 'count' => count($products)]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Product sync error for ' . $shop . ': ' . $e->getMessage());
            return response()->json(['error' => 'Sync failed: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $this->validate($request, [
            'title' => 'required',
            'shop' => 'required'
        ]);

        $product = Product::findOrFail($id);
        $product->update($request->only('title'));

        // Sync back to Shopify
        $session = \App\Services\ShopifyService::loadSession($request->shop);
        if ($session) {
            try {
                $sProd = new ShopifyProduct($session);
                $sProd->id = $product->shopify_id;
                $sProd->title = $product->title;
                $sProd->save();
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Shopify Product Update Error: ' . $e->getMessage());
            }
        }

        return response()->json($product);
    }

    public function destroy(Request $request, $id)
    {
        $this->validate($request, ['shop' => 'required']);
        $product = Product::findOrFail($id);
        
        $session = \App\Services\ShopifyService::loadSession($request->shop);
        if ($session) {
            try {
                ShopifyProduct::delete($session, $product->shopify_id);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Shopify Product Delete Error: ' . $e->getMessage());
            }
        }

        $product->delete();
        return response()->json(['success' => true, 'message' => 'Product deleted locally and from Shopify.']);
    }
}
