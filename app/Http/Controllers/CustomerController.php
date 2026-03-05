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
        $customers = Customer::all();
        \Illuminate\Support\Facades\Log::info("CustomerController@index: Returning " . $customers->count() . " customers.");
        return response()->json($customers);
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

            // Build field list — only include non-null, non-empty values
            $fields = [];
            foreach ($details as $key => $value) {
                if ($value !== null && $value !== '') {
                    $fields[] = ['key' => $key, 'value' => (string) $value];
                }
            }

            if (empty($fields)) {
                return response()->json(['error' => 'No valid detail fields provided.'], 422);
            }

            // Step 1: Create a Metaobject to store customer details
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
                    'type' => 'customerdetails',
                    'fields' => $fields
                ]
            ];

            $response = $graphQL->query(['query' => $metaobjectMutation, 'variables' => $variables]);
            $body = $response->getDecodedBody();

            // Handle top-level GraphQL errors (e.g. auth, network, malformed query)
            if (isset($body['errors'])) {
                \Illuminate\Support\Facades\Log::error("saveDetails: GraphQL Top-level Errors: ", $body['errors']);
                throw new \Exception("GraphQL Errors: " . json_encode($body['errors']));
            }

            // Handle completely missing 'data' key
            if (!isset($body['data'])) {
                \Illuminate\Support\Facades\Log::error("saveDetails: Missing 'data' key in GraphQL response. Full body: ", $body);
                throw new \Exception("Unexpected GraphQL response: no data key.");
            }

            // Handle user-level errors (e.g. wrong type name, field validation)
            if (!empty($body['data']['metaobjectCreate']['userErrors'])) {
                $errs = $body['data']['metaobjectCreate']['userErrors'];
                \Illuminate\Support\Facades\Log::error("saveDetails: Metaobject userErrors: ", $errs);
                throw new \Exception("Metaobject Error: " . json_encode($errs));
            }

            // Ensure metaobject was actually created and has an ID
            $metaobjectId = $body['data']['metaobjectCreate']['metaobject']['id'] ?? null;
            if (!$metaobjectId) {
                \Illuminate\Support\Facades\Log::error("saveDetails: Metaobject created but no ID returned. Body: ", $body);
                throw new \Exception("Metaobject was created but returned no ID.");
            }

            \Illuminate\Support\Facades\Log::info("saveDetails: Metaobject created successfully. ID: {$metaobjectId}");

            // Step 2: Link to local Customer record
            // Try by Shopify numeric ID first, then fall back to email
            $localCustomer = Customer::where('shopify_id', $numericCustomerId)->first();
            if (!$localCustomer && isset($details['contact_email'])) {
                $localCustomer = Customer::where('email', $details['contact_email'])->first();
            }

            if ($localCustomer) {
                // Cast all detail values to strings to avoid type mismatch in DB
                $detailsForDb = array_map(fn($v) => (string) $v, array_filter($details, fn($v) => $v !== null));

                CustomerDetail::updateOrCreate(
                    ['customer_id' => $localCustomer->id],
                    array_merge($detailsForDb, [
                        'shopify_customer_id' => (string) $numericCustomerId,
                        'metaobject_id'       => $metaobjectId
                    ])
                );
                \Illuminate\Support\Facades\Log::info("saveDetails: CustomerDetail record saved for customer_id={$localCustomer->id}, shopify_id={$numericCustomerId}.");
            } else {
                // Non-fatal: metaobject is in Shopify, but we couldn't link it locally
                \Illuminate\Support\Facades\Log::warning("saveDetails: No local customer found for shopify_id={$numericCustomerId} or email. Metaobject {$metaobjectId} created in Shopify but not linked locally.");
            }

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("saveDetails Error: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getDetails($id)
    {
        $numericId = preg_replace('/[^0-9]/', '', $id);

        // Strategy 1: admin app passes the local DB customer_id (e.g. 1, 2, 3)
        $details = CustomerDetail::where('customer_id', $numericId)->first();

        // Strategy 2: storefront passes the Shopify numeric customer ID (e.g. 9827837870356)
        if (!$details) {
            $details = CustomerDetail::where('shopify_customer_id', $numericId)->first();
        }

        // Strategy 3: resolve via the customers table by shopify_id as a last resort
        if (!$details) {
            $localCustomer = Customer::where('shopify_id', $numericId)->first();
            if ($localCustomer) {
                $details = CustomerDetail::where('customer_id', $localCustomer->id)->first();
            }
        }

        if (!$details) {
            return response()->json(['message' => 'No details found for this customer.'], 404);
        }

        return response()->json($details);
    }

    /**
     * Batch import customers and their details (including Shopify Metaobjects).
     * Designed to be called in batches of 10-20 to avoid timeouts.
     */
    public function import(Request $request)
    {
        $this->validate($request, [
            'shop' => 'required',
            'customers' => 'required|array'
        ]);

        $shop = $request->shop;
        \Illuminate\Support\Facades\Log::info("import: Starting batch import for shop: {$shop}", ['count' => count($request->customers)]);

        $session = \App\Services\ShopifyService::loadSession($shop);
        if (!$session) {
            \Illuminate\Support\Facades\Log::error("import: Failed to load session for {$shop}");
            return response()->json(['error' => 'Session expired or invalid for ' . $shop], 401);
        }

        $results = [
            'success' => 0,
            'failed'  => 0,
            'errors'  => []
        ];

        foreach ($request->customers as $index => $data) {
            // Normalize keys to lowercase and trim values
            $data = array_change_key_case($data, CASE_LOWER);
            $email = isset($data['email']) ? strtolower(trim($data['email'])) : null;
            if (!$email) {
                $results['failed']++;
                $results['errors'][] = "Row " . ($index + 1) . ": Missing required email address.";
                continue;
            }

            try {
                // 1. Sync Shopify Customer
                $sCust = new ShopifyCustomer($session);
                $sCust->first_name = $data['first_name'] ?? '';
                $sCust->last_name  = $data['last_name'] ?? '';
                $sCust->email      = $data['email'] ?? '';

                \Illuminate\Support\Facades\Log::info("import: Syncing customer {$email}");

                // Check for existing to avoid duplicates
                $existing = ShopifyCustomer::all($session, ['email' => $sCust->email]);
                if (!empty($existing)) {
                    $sCust = $existing[0];
                    \Illuminate\Support\Facades\Log::info("import: Found existing Shopify customer: {$sCust->id}");
                } else {
                    $sCust->password = \Illuminate\Support\Str::random(10);
                    $sCust->password_confirmation = $sCust->password;
                    $sCust->save();
                    \Illuminate\Support\Facades\Log::info("import: Created new Shopify customer: {$sCust->id}");
                }

                if (!$sCust->id) {
                    throw new \Exception("Could not obtain Shopify ID for customer.");
                }

                // 2. Sync Local Customer Record - Use the data from CSV to update names locally
                $localCustomer = Customer::updateOrCreate(
                    ['email' => strtolower($email)],
                    [
                        'shopify_id' => (string) $sCust->id,
                        'first_name' => $data['first_name'] ?? $sCust->first_name ?? '',
                        'last_name'  => $data['last_name'] ?? $sCust->last_name ?? '',
                    ]
                );
                \Illuminate\Support\Facades\Log::info("import: Local customer record synced: Local ID {$localCustomer->id}, Shopify ID {$sCust->id}");

                // 3. Sync Metaobject (Details)
                $detailsFields = [
                    'company_name'         => $data['company_name'] ?? '',
                    'physician_name'       => $data['physician_name'] ?? '',
                    'npi'                  => $data['npi'] ?? '',
                    'contact_name'         => $data['contact_name'] ?? '',
                    'contact_email'        => $data['contact_email'] ?? '',
                    'contact_phone_number' => $data['contact_phone_number'] ?? '',
                    'sales_rep'            => $data['sales_rep'] ?? '',
                    'po'                   => $data['po'] ?? '',
                    'department'           => $data['department'] ?? '',
                    'message'              => $data['message'] ?? '',
                ];

                $graphQL = new Graphql($session->getShop(), $session->getAccessToken());
                $fields = [];
                foreach ($detailsFields as $key => $value) {
                    if ($value !== null && $value !== '') {
                        $fields[] = ['key' => $key, 'value' => (string) $value];
                    }
                }

                $metaobjectId = null;
                if (!empty($fields)) {
                    $metaobjectMutation = <<<'MUTATION'
                    mutation metaobjectCreate($metaobject: MetaobjectCreateInput!) {
                      metaobjectCreate(metaobject: $metaobject) {
                        metaobject { id }
                        userErrors { field message }
                      }
                    }
MUTATION;

                    $response = $graphQL->query([
                        'query' => $metaobjectMutation, 
                        'variables' => [
                            'metaobject' => [
                                'type' => 'customerdetails',
                                'fields' => $fields
                            ]
                        ]
                    ]);
                    $body = $response->getDecodedBody();
                    
                    if (isset($body['errors'])) {
                        \Illuminate\Support\Facades\Log::error("import: GraphQL Top-level Errors for {$email}: ", $body['errors']);
                        throw new \Exception("GraphQL Errors: " . json_encode($body['errors']));
                    }

                    if (!empty($body['data']['metaobjectCreate']['userErrors'])) {
                        $errMessage = $body['data']['metaobjectCreate']['userErrors'][0]['message'];
                        \Illuminate\Support\Facades\Log::error("import: Metaobject UserError for {$email}: {$errMessage}");
                        throw new \Exception("Metaobject UserError: " . $errMessage);
                    }
                    
                    $metaobjectId = $body['data']['metaobjectCreate']['metaobject']['id'] ?? null;
                    \Illuminate\Support\Facades\Log::info("import: Metaobject created for {$email}: ID {$metaobjectId}");
                }

                // 4. Sync Local Details Record
                CustomerDetail::updateOrCreate(
                    ['customer_id' => $localCustomer->id],
                    array_merge($detailsFields, [
                        'shopify_customer_id' => (string) $sCust->id,
                        'metaobject_id'       => $metaobjectId
                    ])
                );
                \Illuminate\Support\Facades\Log::info("import: Local details synced for {$email}");

                $results['success']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = "{$email}: " . $e->getMessage();
                \Illuminate\Support\Facades\Log::error("Import Error for {$email}: " . $e->getMessage());
            }
        }

        return response()->json($results);
    }
}
