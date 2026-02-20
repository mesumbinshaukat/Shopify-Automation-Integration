<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\CustomerDetail;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handleCustomerUpdate(Request $request)
    {
        $shop = $request->header('X-Shopify-Shop-Domain');
        $data = $request->all();
        $shopifyId = $data['id'] ?? null;

        if (!$shopifyId) {
            return response()->json(['error' => 'Missing customer ID'], 400);
        }

        Log::info("Webhook received: customers/update for {$shopifyId} on {$shop}");

        // Sync basic customer info
        $customer = Customer::updateOrCreate(
            ['email' => $data['email']],
            [
                'shopify_id' => $shopifyId,
                'first_name' => $data['first_name'] ?? '',
                'last_name' => $data['last_name'] ?? '',
                'shopify_tags' => $data['tags'] ?? '',
            ]
        );

        // Here we could also fetch metaobjects if needed, 
        // but typically the saveDetails endpoint handles the initial storage.
        // If metaobjects are edited in Shopify Admin, we'd need a GraphQL call here.

        return response()->json(['success' => true]);
    }
}
