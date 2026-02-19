<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class DiscountService
{
    /**
     * Creates or updates an "Amount off Products" automatic discount for a SPECIFIC customer.
     * 
     * Strategy (per-customer):
     *   1. Tag the customer with MemberDiscount_X, special_discount_X%, and SegmentTarget_{id}
     *   2. Create/reuse a customer segment scoped to the unique SegmentTarget tag
     *   3. Create/reuse an automatic product discount scoped to that segment
     */
    public static function createOrUpdateCustomerDiscount($session, $customer, $percentage)
    {
        $discountValue   = (float) $percentage;
        $memberTagName   = "MemberDiscount_" . str_replace('.', '_', (string)$discountValue);
        $specialTagName  = "special_discount_" . $discountValue . "%";
        $discountDecimal = $discountValue / 100;

        // Per-customer keys — ensures a unique segment + discount per customer
        $customerId     = (string)$customer->shopify_id;
        $customerName   = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
        if (empty($customerName)) {
            $customerName = 'Customer_' . $customerId;
        }
        $customerNameClean = str_replace(' ', '_', $customerName);

        $segmentTarget  = "SegmentTarget_{$customerId}";
        $segmentName    = "Customer_{$customerId}_Discount_Segment";
        $discountTitle  = "Discount_For_{$customerNameClean}_{$discountValue}_Percent_{$customerId}";
        // Appending the Customer ID at the end ensures absolute uniqueness across the shopify store.

        // 0. Safety Check
        if (!$customer->shopify_id || $customer->shopify_id == "0") {
            Log::error("DiscountService: Cannot assign discount — shopify_id is 0 or missing.");
            return null;
        }

        Log::info("DiscountService: Starting per-customer discount for customer {$customerId} ({$customer->email}), {$discountValue}%.");

        try {
            // ----------------------------------------------------------------
            // STEP 1: Tag the customer in Shopify via REST
            // ----------------------------------------------------------------
            $existingState = \Shopify\Rest\Admin2026_01\Customer::find($session, $customer->shopify_id);

            if (!$existingState) {
                throw new \Exception("Could not fetch customer {$customerId} from Shopify to read existing tags.");
            }

            $rawTags = !empty($existingState->tags) ? $existingState->tags : '';
            $tags = array_filter(
                array_map('trim', explode(',', $rawTags)),
                function ($t) { return $t !== ''; }
            );

            // Remove stale discount tags, preserve the SegmentTarget tag
            $tags = array_filter($tags, function ($t) {
                return strpos($t, 'MemberDiscount_') === false
                    && strpos($t, 'special_discount_') === false;
            });

            $tags[] = $memberTagName;
            $tags[] = $specialTagName;
            $tags[] = $segmentTarget;
            $finalTagsString = implode(', ', array_values(array_unique($tags)));

            $sCust       = new \Shopify\Rest\Admin2026_01\Customer($session);
            $sCust->id   = $customerId;
            $sCust->tags = $finalTagsString;

            try {
                $sCust->save();
                Log::info("DiscountService: Tagged customer {$customerId} → [{$finalTagsString}].");
            } catch (\Exception $saveEx) {
                Log::warning("DiscountService: Customer tagging failed (non-fatal): " . $saveEx->getMessage());
            }

            // ----------------------------------------------------------------
            // STEP 2: Ensure a per-customer segment exists
            // ----------------------------------------------------------------
            $graphQL = new \Shopify\Clients\Graphql($session->getShop(), $session->getAccessToken());

            $segmentQuery = <<<QUERY
            query {
              segments(first: 1, query: "name:'{$segmentName}'") {
                edges {
                  node {
                    id
                  }
                }
              }
            }
QUERY;
            $response = $graphQL->query(['query' => $segmentQuery]);
            $body = $response->getDecodedBody();
            $segmentId = $body['data']['segments']['edges'][0]['node']['id'] ?? null;

            if (!$segmentId) {
                Log::info("DiscountService: Creating segment '{$segmentName}' using tag '{$segmentTarget}'.");

                $segmentMutation = <<<MUTATION
                mutation {
                  segmentCreate(name: "{$segmentName}", query: "customer_tags CONTAINS '{$segmentTarget}'") {
                    segment {
                      id
                    }
                    userErrors {
                      field
                      message
                    }
                  }
                }
MUTATION;
                $res = $graphQL->query(['query' => $segmentMutation]);
                $b = $res->getDecodedBody();

                if (!empty($b['data']['segmentCreate']['userErrors'])) {
                    throw new \Exception("Segment creation failed: " . json_encode($b['data']['segmentCreate']['userErrors']));
                }

                $segmentId = $b['data']['segmentCreate']['segment']['id'] ?? null;
            }

            if (!$segmentId) {
                throw new \Exception("Failed to obtain segment ID.");
            }

            // ----------------------------------------------------------------
            // STEP 3: Create or update the per-customer automatic discount
            // ----------------------------------------------------------------
            // 3a. Primary lookup via Database
            $discountId = $customer->shopify_discount_id;

            // 3b. Fallback lookup via API (Strict Title Match)
            if (!$discountId) {
                Log::info("DiscountService: Querying for existing discount by title '{$discountTitle}'.");
                $discountQuery = <<<QUERY
                query {
                  automaticDiscountNodes(first: 10, query: "title:'{$discountTitle}'") {
                    edges {
                      node {
                        id
                        automaticDiscount {
                          ... on DiscountAutomaticBasic {
                            title
                          }
                        }
                      }
                    }
                  }
                }
QUERY;
                $res = $graphQL->query(['query' => $discountQuery]);
                $b = $res->getDecodedBody();
                
                $nodes = $b['data']['automaticDiscountNodes']['edges'] ?? [];
                foreach ($nodes as $edge) {
                    $nodeTitle = $edge['node']['automaticDiscount']['title'] ?? '';
                    if ($nodeTitle === $discountTitle) {
                        $discountId = $edge['node']['id'];
                        break;
                    }
                }
            }

            if ($discountId) {
                Log::info("DiscountService: Found existing discount {$discountId}. Updating to {$discountValue}%.");
                $discountId = self::updateAutomaticDiscount($graphQL, $discountId, $segmentId, $discountDecimal, $customer);
            } else {
                Log::info("DiscountService: No existing discount found. Creating fresh.");
                $discountId = self::createAutomaticDiscount($graphQL, $discountTitle, $segmentId, $discountDecimal, $customer);
            }

            // 3c. Persist the ID to our database
            if ($discountId && $customer->shopify_discount_id !== $discountId) {
                $customer->update(['shopify_discount_id' => $discountId]);
                Log::info("DiscountService: Persisted discount ID {$discountId} to customer database.");
            }

            Log::info("DiscountService: Successfully processed discount ID → {$discountId}.");

            return [
                'price_rule_id' => $memberTagName,
                'discount_code' => 'AUTOMATIC',
                'tags'          => $finalTagsString,
                'discount_id'   => $discountId,
            ];

        } catch (\Exception $e) {
            Log::error("DiscountService: FAILED for customer {$customerId} — " . $e->getMessage());
            throw $e;
        }
    }

    private static function buildTargets($customer): string
    {
        if ($customer->discount_target_type === 'products' && !empty($customer->discount_target_ids)) {
            $ids = array_map(function($id) {
                return (strpos($id, 'gid://') === 0) ? $id : "gid://shopify/Product/{$id}";
            }, (array)$customer->discount_target_ids);
            $idsJson = json_encode(array_values($ids));
            return "products: { productsToAdd: {$idsJson} }";
        }
        if ($customer->discount_target_type === 'collections' && !empty($customer->discount_target_ids)) {
            $ids = array_map(function($id) {
                return (strpos($id, 'gid://') === 0) ? $id : "gid://shopify/Collection/{$id}";
            }, (array)$customer->discount_target_ids);
            $idsJson = json_encode(array_values($ids));
            return "collections: { add: {$idsJson} }";
        }
        return 'all: true';
    }

    private static function createAutomaticDiscount($graphQL, string $discountTitle, string $segmentId, float $discountDecimal, $customer): string
    {
        $targets = self::buildTargets($customer);
        $now     = date('c');

        // Note: Using discountAutomaticBasicCreate for "Amount off products"
        $mutation = <<<'MUTATION'
        mutation {
          discountAutomaticBasicCreate(automaticBasicDiscount: {
            title: "DISCOUNT_TITLE",
            startsAt: "STARTS_AT",
            context: {
              customerSegments: {
                add: ["SEGMENT_ID"]
              }
            },
            customerGets: {
              value: {
                percentage: DISCOUNT_DECIMAL
              },
              items: {
                TARGETS
              }
            }
          }) {
            automaticDiscountNode {
              id
            }
            userErrors {
              field
              message
            }
          }
        }
MUTATION;
        $mutation = str_replace(
            ['DISCOUNT_TITLE', 'STARTS_AT', 'SEGMENT_ID', 'DISCOUNT_DECIMAL', 'TARGETS'],
            [$discountTitle,   $now,        $segmentId,   $discountDecimal,   $targets],
            $mutation
        );

        $res = $graphQL->query(['query' => $mutation]);
        $b = $res->getDecodedBody();

        if (!empty($b['errors'])) {
            throw new \Exception("GraphQL top-level errors: " . json_encode($b['errors']));
        }

        if (!empty($b['data']['discountAutomaticBasicCreate']['userErrors'])) {
            throw new \Exception("Discount creation failed: " . json_encode($b['data']['discountAutomaticBasicCreate']['userErrors']));
        }

        $id = $b['data']['discountAutomaticBasicCreate']['automaticDiscountNode']['id'] ?? null;
        if (!$id) {
            throw new \Exception("Discount creation returned no ID. Response: " . json_encode($b));
        }

        return $id;
    }

    private static function updateAutomaticDiscount($graphQL, string $discountId, string $segmentId, float $discountDecimal, $customer): string
    {
        $targets = self::buildTargets($customer);

        // Note: Using discountAutomaticBasicUpdate
        $mutation = <<<'MUTATION'
        mutation {
          discountAutomaticBasicUpdate(
            id: "DISCOUNT_ID",
            automaticBasicDiscount: {
              context: {
                customerSegments: {
                  add: ["SEGMENT_ID"]
                }
              },
              customerGets: {
                value: {
                  percentage: DISCOUNT_DECIMAL
                },
                items: {
                  TARGETS
                }
              }
            }
          ) {
            automaticDiscountNode {
              id
            }
            userErrors {
              field
              message
            }
          }
        }
MUTATION;
        $mutation = str_replace(
            ['DISCOUNT_ID', 'SEGMENT_ID', 'DISCOUNT_DECIMAL', 'TARGETS'],
            [$discountId,  $segmentId,   $discountDecimal,   $targets],
            $mutation
        );

        $res = $graphQL->query(['query' => $mutation]);
        $b = $res->getDecodedBody();

        if (!empty($b['errors'])) {
            throw new \Exception("GraphQL top-level errors: " . json_encode($b['errors']));
        }

        if (!empty($b['data']['discountAutomaticBasicUpdate']['userErrors'])) {
            throw new \Exception("Discount update failed: " . json_encode($b['data']['discountAutomaticBasicUpdate']['userErrors']));
        }

        $id = $b['data']['discountAutomaticBasicUpdate']['automaticDiscountNode']['id'] ?? $discountId;
        return $id;
    }
}
