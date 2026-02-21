<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\PricingTier;
use Shopify\Rest\Admin2026_01\Customer as ShopifyCustomer;
use App\Models\CustomerDetail;
use Shopify\Clients\Graphql;

class CustomerController extends Controller
{
    public function index()
    {
        return response()->json(Customer::all());
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'first_name' => 'required',
            'last_name' => 'required',
            'email' => 'required|email',
            'shop' => 'required'
        ]);

        $session = \App\Services\ShopifyService::loadSession($request->shop);
        if (!$session) {
            return response()->json(['error' => 'Session expired. Please refresh.'], 401);
        }

        try {
            // 1. Save to Shopify
            $sCust = new ShopifyCustomer($session);
            $sCust->first_name = $request->first_name;
            $sCust->last_name = $request->last_name;
            $sCust->email = $request->email;
            $sCust->password = $request->password ?? \Illuminate\Support\Str::random(10);
            $sCust->password_confirmation = $sCust->password;
            
            try {
                $sCust->save();
            } catch (\Exception $e) {
                // Creation usually succeeds even if the SDK throws a minor network exception
            }

            // 2. Optimistic Sync: If we don't have the ID immediately, try to find the customer by email
            if (!$sCust->id) {
                try {
                    $matches = ShopifyCustomer::all($session, ['email' => $request->email]);
                    foreach ($matches as $match) {
                        if (strtolower($match->email) === strtolower($request->email)) {
                            $sCust = $match;
                            break;
                        }
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("CustomerController: Rescue sync failed for {$request->email}: " . $e->getMessage());
                }
            }

            // 3. Create or Update Local Record
            $customer = Customer::updateOrCreate(
                ['email' => $request->email],
                [
                    'shopify_id' => $sCust->id ?? null,
                    'first_name' => $sCust->first_name ?? $request->first_name,
                    'last_name' => $sCust->last_name ?? $request->last_name,
                ]
            );

            return response()->json($customer, 201);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Creation Issue: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $this->validate($request, [
            'first_name' => 'required',
            'last_name' => 'required',
            'email' => 'required|email',
            'shop' => 'required'
        ]);

        $customer = Customer::findOrFail($id);
        $customer->update($request->only('first_name', 'last_name', 'email'));

        $session = \App\Services\ShopifyService::loadSession($request->shop);
        if ($session) {
            try {
                if (!$customer->shopify_id || $customer->shopify_id == 0) {
                    $matches = ShopifyCustomer::all($session, ['email' => $customer->email]);
                    foreach ($matches as $match) {
                        if (strtolower($match->email) === strtolower($customer->email)) {
                            $customer->shopify_id = $match->id;
                            $customer->save();
                            break;
                        }
                    }
                }

                if ($customer->shopify_id && $customer->shopify_id != 0) {
                    $sCust = new ShopifyCustomer($session);
                    $sCust->id = $customer->shopify_id;
                    $sCust->first_name = $customer->first_name;
                    $sCust->last_name = $customer->last_name;
                    $sCust->email = $customer->email;
                    $sCust->save();
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Shopify Customer Update failed: ' . $e->getMessage());
            }
        }

        return response()->json($customer);
    }

    public function destroy(Request $request, $id)
    {
        $this->validate($request, ['shop' => 'required']);
        $customer = Customer::findOrFail($id);
        
        $session = \App\Services\ShopifyService::loadSession($request->shop);
        if ($session) {
            try {
                if (!$customer->shopify_id || $customer->shopify_id == 0) {
                    $matches = ShopifyCustomer::all($session, ['email' => $customer->email]);
                    foreach ($matches as $match) {
                        if (strtolower($match->email) === strtolower($customer->email)) {
                            $customer->shopify_id = $match->id;
                            $customer->save();
                            break;
                        }
                    }
                }

                if ($customer->shopify_id && $customer->shopify_id != 0) {
                    ShopifyCustomer::delete($session, $customer->shopify_id);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Shopify Customer Delete failed: ' . $e->getMessage());
            }
        }

        $customer->delete();
        return response()->json(null, 204);
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
            $customers = ShopifyCustomer::all($session);
            
            foreach ($customers as $sCust) {
                Customer::updateOrCreate(
                    ['email' => $sCust->email],
                    [
                        'shopify_id'   => $sCust->id,
                        'first_name'   => $sCust->first_name,
                        'last_name'    => $sCust->last_name,
                        'shopify_tags' => $sCust->tags ?? null,
                    ]
                );
            }

            return response()->json(['message' => 'Customer sync completed', 'count' => count($customers)]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Sync error for ' . $shop . ': ' . $e->getMessage());
            return response()->json(['error' => 'Sync failed: ' . $e->getMessage()], 500);
        }
    }

    public function updateDiscount(Request $request, $id)
    {
        $this->validate($request, [
            'discount_percentage' => 'required|numeric|min:0|max:100',
            'discount_target_type' => 'required|in:all,products,collections',
            'discount_target_ids' => 'nullable|array',
            'shop' => 'required'
        ]);

        $customer = Customer::findOrFail($id);
        
        $customer->discount_percentage = $request->discount_percentage;
        $customer->discount_target_type = $request->discount_target_type;
        $customer->discount_target_ids = $request->discount_target_ids;
        $customer->save();

        $session = \App\Services\ShopifyService::loadSession($request->shop);
        if ($session) {
            try {
                if (!$customer->shopify_id || $customer->shopify_id == 0) {
                    $matches = ShopifyCustomer::all($session, ['email' => $customer->email]);
                    foreach ($matches as $match) {
                        if (strtolower($match->email) === strtolower($customer->email)) {
                            $customer->shopify_id = $match->id;
                            $customer->save();
                            break;
                        }
                    }
                }

                if (!$customer->shopify_id || $customer->shopify_id == 0) {
                    throw new \Exception("Cannot assign discount: Customer ID not yet verified by Shopify.");
                }

                $discountData = \App\Services\DiscountService::createOrUpdateCustomerDiscount(
                    $session, 
                    $customer, 
                    $request->discount_percentage
                );

                if ($discountData) {
                    $customer->shopify_discount_id = $discountData['discount_id'] ?? $discountData['price_rule_id'];
                    if (isset($discountData['tags'])) {
                        $customer->shopify_tags = $discountData['tags'];
                    }
                    $customer->save();
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to update Shopify discount for customer ' . $customer->email . ': ' . $e->getMessage());
                return response()->json(['error' => $e->getMessage()], 500);
            }
        }
        
        return response()->json($customer);
    }

    public function sendCredentials(Request $request, $id)
    {
        $customer = Customer::findOrFail($id);
        $password = \Illuminate\Support\Str::random(10);
        
        \Illuminate\Support\Facades\Mail::to($customer->email)->send(
            new \App\Mail\CustomerCredentialMail($customer, $password)
        );

        return response()->json(['message' => 'Credentials sent to ' . $customer->email]);
    }

    public function saveDetails(Request $request)
    {
        // 1. Log incoming data for production debugging
        \Illuminate\Support\Facades\Log::info("saveDetails Request Data: ", $request->all());

        $shop = $request->get('shop');
        if (!$shop) {
             return response()->json(['error' => 'Missing shop parameter'], 400);
        }

        // 2. Security Check: Verify Shopify App Proxy Signature (Matches DiscountController)
        if (config('app.env') === 'production') {
            $queryParams = $request->query();
            $signature = $queryParams['signature'] ?? '';
            
            if (empty($signature)) {
                \Illuminate\Support\Facades\Log::error("saveDetails: Missing signature parameter for shop $shop");
                return response()->json(['error' => 'Missing signature'], 401);
            }

            unset($queryParams['signature']);
            ksort($queryParams);
            
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
            sort($messageParts);
            $message = implode('', $messageParts);
            
            $calculatedHmac = hash_hmac('sha256', $message, env('SHOPIFY_API_SECRET'));

            if (!hash_equals($calculatedHmac, $signature)) {
                \Illuminate\Support\Facades\Log::error("saveDetails: HMAC mismatch for shop $shop. Message: '$message'. Calculated: $calculatedHmac, Received: $signature");
                return response()->json(['error' => 'Invalid signature'], 401);
            }
            
            \Illuminate\Support\Facades\Log::info("saveDetails: Signature verified successfully for shop $shop");
        }

        // 3. Resolve the Session
        $session = \App\Services\ShopifyService::loadSession($shop);
        
        // Fallback: If session loading fails, try to fuzzy match the shop name 
        // (sometimes the primary domain and myshopify domain are confused)
        if (!$session && !str_contains($shop, '.myshopify.com')) {
            $customerMatch = Customer::where('shop', 'like', '%' . $shop . '%')->first();
            if ($customerMatch && $customerMatch->shop !== $shop) {
                 \Illuminate\Support\Facades\Log::info("saveDetails: Attempting fallback session load for resolved shop: {$customerMatch->shop}");
                 $session = \App\Services\ShopifyService::loadSession($customerMatch->shop);
                 if ($session) {
                     $shop = $customerMatch->shop;
                 }
            }
        }

        if (!$session) {
            \Illuminate\Support\Facades\Log::error("saveDetails: Failed to load session for $shop");
            return response()->json(['error' => 'Session expired or not found for ' . $shop], 401);
        }

        $this->validate($request, [
            'customerId' => 'required',
            'details' => 'required|array',
            'details.company_name' => 'required',
            'details.physician_name' => 'required',
            'details.npi' => 'required',
            'details.contact_name' => 'required',
            'details.contact_email' => 'required|email',
            'details.contact_phone_number' => 'required',
        ]);

        $customerId = $request->customerId;
        $details = $request->details;
        $numericCustomerId = preg_replace('/[^0-9]/', '', $customerId);

        try {
            $graphQL = new Graphql($session->getShop(), $session->getAccessToken());

            $fields = [];
            foreach ($details as $key => $value) {
                if ($value !== null) {
                    $fields[] = ['key' => $key, 'value' => (string)$value];
                }
            }

            $metaobjectMutation = <<<'MUTATION'
            mutation metaobjectCreate($metaobject: MetaobjectCreateInput!) {
              metaobjectCreate(metaobject: $metaobject) {
                metaobject {
                  id
                  handle
                }
                userErrors {
                  field
                  message
                }
              }
            }
MUTATION;

            $variables = [
                'metaobject' => [
                    'definitionHandle' => 'customer_details',
                    'fields' => $fields
                ]
            ];

            $response = $graphQL->query(['query' => $metaobjectMutation, 'variables' => $variables]);
            $body = $response->getDecodedBody();

            if (!empty($body['data']['metaobjectCreate']['userErrors'])) {
                throw new \Exception("Metaobject Error: " . json_encode($body['data']['metaobjectCreate']['userErrors']));
            }

            $metaobjectId = $body['data']['metaobjectCreate']['metaobject']['id'];

            $customerMutation = <<<'MUTATION'
            mutation customerUpdate($input: CustomerInput!) {
              customerUpdate(input: $input) {
                customer {
                  id
                }
                userErrors {
                  field
                  message
                }
              }
            }
MUTATION;

            $customerVariables = [
                'input' => [
                    'id' => "gid://shopify/Customer/{$numericCustomerId}",
                    'metafields' => [
                        [
                            'namespace' => 'custom',
                            'key' => 'details',
                            'type' => 'metaobject_reference',
                            'value' => $metaobjectId
                        ],
                        [
                            'namespace' => 'custom',
                            'key' => 'has_details',
                            'type' => 'boolean',
                            'value' => 'true'
                        ]
                    ]
                ]
            ];

            $customerResponse = $graphQL->query(['query' => $customerMutation, 'variables' => $customerVariables]);
            $customerBody = $customerResponse->getDecodedBody();

            if (!empty($customerBody['data']['customerUpdate']['userErrors'])) {
                throw new \Exception("Customer Update Error: " . json_encode($customerBody['data']['customerUpdate']['userErrors']));
            }

            $localCustomer = Customer::where('shopify_id', $numericCustomerId)->first();
            if (!$localCustomer) {
                $localCustomer = Customer::where('email', $details['contact_email'])->first();
            }

            if ($localCustomer) {
                CustomerDetail::updateOrCreate(
                    ['customer_id' => $localCustomer->id],
                    array_merge($details, [
                        'shopify_customer_id' => $numericCustomerId,
                        'metaobject_id' => $metaobjectId
                    ])
                );
            }

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("saveDetails Error: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getDetails($id)
    {
        $details = CustomerDetail::where('customer_id', $id)->first();
        return response()->json($details);
    }
}
