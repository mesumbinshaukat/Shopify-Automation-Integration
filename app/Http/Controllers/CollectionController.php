<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Collection;
use Shopify\Rest\Admin2026_01\CustomCollection as ShopifyCollection;
use Shopify\Rest\Admin2026_01\SmartCollection as ShopifySmartCollection;

class CollectionController extends Controller
{
    public function index()
    {
        return response()->json(Collection::all());
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
            // Shopify has custom and smart collections
            $custom = ShopifyCollection::all($session);
            $smart = ShopifySmartCollection::all($session);
            
            $all = array_merge($custom, $smart);
            
            foreach ($all as $sColl) {
                Collection::updateOrCreate(
                    ['shopify_id' => $sColl->id],
                    [
                        'title' => $sColl->title,
                        'handle' => $sColl->handle,
                    ]
                );
            }

            return response()->json(['message' => 'Collection sync completed', 'count' => count($all)]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Collection sync error for ' . $shop . ': ' . $e->getMessage());
            return response()->json(['error' => 'Sync failed: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $this->validate($request, [
            'title' => 'required',
            'shop' => 'required'
        ]);

        $collection = Collection::findOrFail($id);
        $collection->update($request->only('title'));

        // Note: Generic collection update is complex because of smart vs custom. 
        // For simplicity, we update local and attempt custom if possible.
        $session = \App\Services\ShopifyService::loadSession($request->shop);
        if ($session) {
            try {
                $sColl = new ShopifyCollection($session);
                $sColl->id = $collection->shopify_id;
                $sColl->title = $collection->title;
                $sColl->save();
            } catch (\Exception $e) {
                // Might be a smart collection
                try {
                    $sColl = new ShopifySmartCollection($session);
                    $sColl->id = $collection->shopify_id;
                    $sColl->title = $collection->title;
                    $sColl->save();
                } catch (\Exception $ex) {
                    \Illuminate\Support\Facades\Log::error('Shopify Collection Update Error: ' . $ex->getMessage());
                }
            }
        }

        return response()->json($collection);
    }

    public function destroy(Request $request, $id)
    {
        $this->validate($request, ['shop' => 'required']);
        $collection = Collection::findOrFail($id);
        
        $session = \App\Services\ShopifyService::loadSession($request->shop);
        if ($session) {
            try {
                ShopifyCollection::delete($session, $collection->shopify_id);
            } catch (\Exception $e) {
                try {
                    ShopifySmartCollection::delete($session, $collection->shopify_id);
                } catch (\Exception $ex) {
                    \Illuminate\Support\Facades\Log::error('Shopify Collection Delete Error: ' . $ex->getMessage());
                }
            }
        }

        $collection->delete();
        return response()->json(null, 204);
    }
}
