<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use App\Services\ShopifyService;
use Illuminate\Support\Facades\Log;

class DiscountController extends Controller
{
    /**
     * Endpoint for Liquid storefront to fetch a customer's specific discount.
     * GET /api/get-discount?product_id=123&customer_id=456&shop=xxx.myshopify.com
     */
    public function getDiscount(Request $request)
    {
        $productId  = $request->get('product_id');
        $customerId = $request->get('customer_id');
        $shop       = $request->get('shop');

        if (!$productId || !$customerId || !$shop) {
            return response()->json(['error' => 'Missing required parameters'], 400);
        }

        // Debug: Log all incoming parameters to investigate shop name and signature issues
        Log::info("DiscountController: Request from shop: $shop. Parameters: " . json_encode($request->all()));

        // 1. Security Check: Verify Shopify Request Signature (HMAC) for App Proxy
        if (config('app.env') === 'production') {
            $queryParams = $request->query();
            $signature = $queryParams['signature'] ?? '';
            
            unset($queryParams['signature']);
            ksort($queryParams);
            
            $queryString = http_build_query($queryParams);
            $calculatedHmac = hash_hmac('sha256', $queryString, config('app.shopify_api_secret') ?: env('SHOPIFY_API_SECRET'));

            if (!hash_equals($calculatedHmac, $signature)) {
                Log::error("DiscountController: Invalid HMAC signature for shop $shop. Expected: $calculatedHmac, Received: $signature");
                return response()->json(['error' => 'Invalid signature'], 401);
            }
        }

        // 2. Lookup Customer
        $customer = Customer::where('shopify_id', $customerId)->first();

        if (!$customer || (float)$customer->discount_percentage <= 0) {
            return response()->json([
                'percent'  => 0,
                'eligible' => false
            ]);
        }

        $percentage = (float)$customer->discount_percentage;
        $targetType = $customer->discount_target_type;
        $targetIds  = (array)$customer->discount_target_ids;

        // 3. Eligibility Check
        $isEligible = false;

        try {
            if ($targetType === 'all') {
                $isEligible = true;
            } elseif ($targetType === 'products') {
                // Check if product ID matches (coerce both to string/numeric for safety)
                $cleanProdId = (string)str_replace('gid://shopify/Product/', '', $productId);
                foreach ($targetIds as $tid) {
                    $cleanTid = (string)str_replace('gid://shopify/Product/', '', $tid);
                    if ($cleanProdId === $cleanTid) {
                        $isEligible = true;
                        break;
                    }
                }
            } elseif ($targetType === 'collections') {
                $isEligible = $this->checkCollectionMembership($shop, $productId, $targetIds);
            }
        } catch (\Exception $e) {
            Log::error("DiscountController: Eligibility check failed for customer {$customerId} on product {$productId}: " . $e->getMessage());
            // Fallback to false on error to be safe
        }

        return response()->json([
            'percent'  => $isEligible ? $percentage : 0,
            'eligible' => $isEligible
        ]);
    }

    /**
     * Uses GraphQL to check if a product belongs to target collections.
     */
    private function checkCollectionMembership(string $shop, string $productId, array $targetCollectionIds): bool
    {
        $session = ShopifyService::loadSession($shop);
        if (!$session) {
            Log::error("DiscountController: No session found for collection check on shop $shop");
            return false;
        }

        $graphQL = new \Shopify\Clients\Graphql($session->getShop(), $session->getAccessToken());
        
        $productGid = (strpos($productId, 'gid://') === 0) ? $productId : "gid://shopify/Product/{$productId}";

        $query = <<<QUERY
        query {
          product(id: "{$productGid}") {
            inCollections(first: 50) {
              edges {
                node {
                  id
                }
              }
            }
          }
        }
QUERY;

        $response = $graphQL->query(['query' => $query]);
        $body = $response->getDecodedBody();

        if (empty($body['data']['product']['inCollections']['edges'])) {
            return false;
        }

        $productCollectionGids = array_map(function($edge) {
            return $edge['node']['id'];
        }, $body['data']['product']['inCollections']['edges']);

        // Normalize target IDs to GIDs
        $targetGids = array_map(function($tid) {
            return (strpos($tid, 'gid://') === 0) ? $tid : "gid://shopify/Collection/{$tid}";
        }, $targetCollectionIds);

        // intersection check
        $matches = array_intersect($productCollectionGids, $targetGids);

        return !empty($matches);
    }
}
