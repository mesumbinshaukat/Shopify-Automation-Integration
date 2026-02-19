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
        Log::info("DiscountController: Request from shop: $shop. All query params: " . json_encode($request->query()));

        // 1. Security Check: Verify Shopify App Proxy Signature
        // Shopify App Proxy signs requests with a 'signature' param using HMAC-SHA256.
        // The message is: sorted key=value pairs concatenated WITHOUT separators, WITHOUT URL-encoding.
        if (config('app.env') === 'production') {
            $queryParams = $request->query();
            $signature = $queryParams['signature'] ?? '';
            
            if (empty($signature)) {
                Log::error("DiscountController: Missing signature parameter for shop $shop");
                return response()->json(['error' => 'Missing signature'], 401);
            }

            unset($queryParams['signature']);
            ksort($queryParams);
            
            // Build message: concatenate key=value pairs with NO separator, NO URL-encoding
            // Handle array params (e.g. extra[]=1&extra[]=2) by flattening
            $messageParts = [];
            foreach ($queryParams as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $v) {
                        $messageParts[] = "{$key}={$v}";
                    }
                } else {
                    $messageParts[] = "{$key}={$value}";
                }
            }
            sort($messageParts); // lexicographic sort on the full "key=value" strings
            $message = implode('', $messageParts); // NO separator
            
            $calculatedHmac = hash_hmac('sha256', $message, env('SHOPIFY_API_SECRET'));

            if (!hash_equals($calculatedHmac, $signature)) {
                Log::error("DiscountController: HMAC mismatch for shop $shop. Message: '$message'. Calculated: $calculatedHmac, Received: $signature");
                return response()->json(['error' => 'Invalid signature'], 401);
            }
            
            Log::info("DiscountController: Signature verified successfully for shop $shop");
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
            Log::info("Checking eligibility for customer {$customerId} on product {$productId}. Target Type: {$targetType}");

            if ($percentage <= 0) {
                $isEligible = false;
                Log::info("No percentage assigned to customer → ineligible");
            } else {
                if ($targetType === 'all') {
                    $isEligible = true;
                    Log::info("Customer has 'all' products discount → eligible");
                } elseif ($targetType === 'products') {
                    $cleanProdId = (string)str_replace('gid://shopify/Product/', '', $productId);
                    foreach ($targetIds as $tid) {
                        $cleanTid = (string)str_replace('gid://shopify/Product/', '', $tid);
                        if ($cleanProdId === $cleanTid) {
                            $isEligible = true;
                            break;
                        }
                    }
                    if ($isEligible) {
                        Log::info("Product {$productId} is in specific assigned products list → eligible for {$percentage}%");
                    } else {
                        Log::info("Product {$productId} NOT in assigned products list → ineligible");
                    }
                } elseif ($targetType === 'collections') {
                    // Force strict check: only eligible if product matches target collections
                    $isEligible = $this->checkCollectionMembership($shop, $productId, $targetIds);

                    if ($isEligible) {
                        Log::info("Product {$productId} is in target collections → eligible for {$percentage}%");
                    } else {
                        Log::info("Product {$productId} NOT in target collections → ineligible");
                    }
                } else {
                    Log::warning("Unknown target type '{$targetType}' for customer {$customerId} → defaulting to ineligible");
                    $isEligible = false;
                }
            }

        } catch (\Exception $e) {
            Log::error("Eligibility check failed for customer {$customerId} on product {$productId}: " . $e->getMessage());
            $isEligible = false;  // Fail-safe: deny discount on error
        }

        // Safety net: never give discount if not explicitly eligible
        if (!$isEligible) {
            $percentage = 0;
        }

        return response()->json([
            'percent'  => $percentage,
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
