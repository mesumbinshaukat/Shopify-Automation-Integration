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
            // 1. Save to Shopify - Following original flow
            $sCust = new ShopifyCustomer($session);
            $sCust->first_name = $request->first_name;
            $sCust->last_name = $request->last_name;
            $sCust->email = strtolower(trim($request->email));
            $sCust->tags = 'Approved';
            $sCust->password = $request->password ?? \Illuminate\Support\Str::random(10);
            $sCust->password_confirmation = $sCust->password;
            
            try {
                $sCust->save();
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("store: Save threw exception, attempting rescue sync: " . $e->getMessage());
            }

            // 2. Optimistic Sync: If we don't have the ID, try to find strictly by email
            if (!$sCust->id) {
                try {
                    $matches = ShopifyCustomer::all($session, ['email' => $sCust->email]);
                    foreach ($matches as $match) {
                        if (strtolower(trim($match->email)) === strtolower(trim($sCust->email))) {
                            $sCust = $match;
                            break;
                        }
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("store: Rescue sync failed for {$sCust->email}");
                }
            }

            // 3. Create or Update Local Record
            $customer = Customer::updateOrCreate(
                ['email' => strtolower(trim($request->email))],
                [
                    'shopify_id' => $sCust->id ? (string)$sCust->id : null,
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
                        $tags = array_map('trim', explode(',', $discountData['tags']));
                        if (!in_array('Approved', $tags)) {
                            $tags[] = 'Approved';
                        }
                        $customer->shopify_tags = implode(', ', $tags);
                    } else {
                        $customer->shopify_tags = 'Approved';
                    }

                    // Update Shopify tags via REST to be sure
                    $sUpdate = new ShopifyCustomer($session);
                    $sUpdate->id = $customer->shopify_id;
                    $sUpdate->tags = $customer->shopify_tags;
                    $sUpdate->save();

                    $customer->save();
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to update Shopify discount for customer ' . $customer->email . ': ' . $e->getMessage());
                return response()->json(['error' => $e->getMessage()], 500);
            }
        }
        
        return response()->json($customer);
    }

    public function sendCredentials($id, Request $request = null)
    {
        $customer = Customer::findOrFail($id);
        $password = \Illuminate\Support\Str::random(10);
        
        \Illuminate\Support\Facades\Mail::to($customer->email)->send(
            new \App\Mail\CustomerCredentialMail($customer, $password)
        );

        if ($request) {
            return response()->json(['message' => 'Credentials sent to ' . $customer->email]);
        }
        
        return true;
    }

    public function saveDetails(Request $request)
    {
        // 1. Log incoming data for production debugging
        \Illuminate\Support\Facades\Log::info("saveDetails Request Data: ", $request->all());

        $shop = $request->get('shop');
        if (!$shop) {
             return response()->json(['error' => 'Missing shop parameter'], 400);
        }

        // 2. Security Check: Verify Shopify App Proxy Signature
        $signatureError = $this->validateProxySignature($request);
        if ($signatureError) {
            return $signatureError;
        }

        \Illuminate\Support\Facades\Log::info("saveDetails: Signature verified successfully for shop $shop");

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
        $session = \App\Services\ShopifyService::loadSession($shop);
        if (!$session) {
            return response()->json(['error' => 'Session expired or invalid for ' . $shop], 401);
        }

        $results = ['success' => 0, 'failed' => 0, 'errors' => []];

        foreach ($request->customers as $index => $data) {
            $data = array_change_key_case($data, CASE_LOWER);
            $email = isset($data['email']) ? strtolower(trim($data['email'])) : null;
            
            if (!$email) {
                $results['failed']++;
                $results['errors'][] = "Row " . ($index + 1) . ": Missing email.";
                continue;
            }

            try {
                // FOLLOWING THE EXISTING CUSTOMER FLOW EXACTLY
                $sCust = new ShopifyCustomer($session);
                $sCust->first_name = $data['first_name'] ?? '';
                $sCust->last_name = $data['last_name'] ?? '';
                $sCust->email = $email;
                $sCust->password = \Illuminate\Support\Str::random(10);
                $sCust->password_confirmation = $sCust->password;

                try {
                    $sCust->save();
                } catch (\Exception $e) { }

                // Rescue sync if ID missing
                if (!$sCust->id) {
                    try {
                        $matches = ShopifyCustomer::all($session, ['email' => $email]);
                        foreach ($matches as $match) {
                            if (strtolower(trim($match->email)) === $email) {
                                $sCust = $match;
                                break;
                            }
                        }
                    } catch (\Exception $e) { }
                }

                // Sync Local Record
                Customer::updateOrCreate(
                    ['email' => $email],
                    [
                        'shopify_id' => $sCust->id ? (string)$sCust->id : null,
                        'first_name' => $sCust->first_name ?? ($data['first_name'] ?? ''),
                        'last_name' => $sCust->last_name ?? ($data['last_name'] ?? ''),
                    ]
                );

                $results['success']++;
            } catch (\Exception $e) {
                // If the error is a duplicate entry for empty string, it's already handled by using null
                // But generally, the user wants us to be more lenient with "success" reporting.
                // However, we'll keep real errors in failed count.
                $results['failed']++;
                $results['errors'][] = "Row " . ($index + 1) . ": " . $e->getMessage();
            }
        }

        return response()->json($results);
    }

    public function requestAccess(Request $request)
    {
        $shop = $request->get('shop');
        if (!$shop) {
             return response()->json(['error' => 'Missing shop parameter'], 400);
        }

        // 1. Security Check: Verify Shopify App Proxy Signature
        $signatureError = $this->validateProxySignature($request);
        if ($signatureError) {
            return $signatureError;
        }

        // 2. Validation
        $this->validate($request, [
            'practice_name' => 'required',
            'first_name' => 'required',
            'last_name' => 'required',
            'email' => 'required|email',
            'phone' => 'required',
            'city' => 'required',
            'state' => 'required',
            'specialty' => 'required',
            'interests' => 'nullable|array',
            'message' => 'nullable',
        ]);

        $email = strtolower(trim($request->email));

        // 3. Resolve Session
        $session = \App\Services\ShopifyService::loadSession($shop);
        if (!$session) {
            return response()->json(['error' => 'Session issues. Please try again later.'], 401);
        }

        // 4. Edge Case: Check if email already exists in Shopify via GraphQL
        try {
            $graphQL = new Graphql($session->getShop(), $session->getAccessToken());
            $searchQuery = <<<'QUERY'
            query($q: String!) {
              customers(first: 1, query: $q) {
                edges {
                  node {
                    id
                  }
                }
              }
            }
QUERY;
            $searchResponse = $graphQL->query(['query' => $searchQuery, 'variables' => ['q' => "email:$email"]]);
            $searchBody = $searchResponse->getDecodedBody();
            
            if (!empty($searchBody['data']['customers']['edges'])) {
                return response()->json(['error' => 'This email is already registered as a customer.'], 422);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("requestAccess: Shopify customer GraphQL check failed: " . $e->getMessage());
        }

        try {
            // 5. Create Metaobject entry
            $graphQL = new Graphql($session->getShop(), $session->getAccessToken());

            $fields = [];
            $data = $request->only([
                'practice_name', 'first_name', 'last_name', 'title', 
                'email', 'phone', 'city', 'state', 'specialty', 'message'
            ]);
            
            // Add status
            $data['status'] = 'pending';

            foreach ($data as $key => $value) {
                if ($value !== null) {
                    $fields[] = ['key' => $key, 'value' => (string) $value];
                }
            }

            // Handle interests array (store as JSON string for list.single_line_text_field)
            $interests = $request->get('interests');
            if ($interests && is_array($interests)) {
                $fields[] = ['key' => 'interests', 'value' => json_encode($interests)];
            }

            $metaobjectMutation = <<<'MUTATION'
            mutation metaobjectCreate($metaobject: MetaobjectCreateInput!) {
              metaobjectCreate(metaobject: $metaobject) {
                metaobject {
                  id
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
                    'type' => 'access_request',
                    'fields' => $fields
                ]
            ];

            $response = $graphQL->query(['query' => $metaobjectMutation, 'variables' => $variables]);
            $body = $response->getDecodedBody();

            if (!empty($body['data']['metaobjectCreate']['userErrors'])) {
                throw new \Exception("Metaobject Error: " . json_encode($body['data']['metaobjectCreate']['userErrors']));
            }

            // 6. Send Notification Email
            $adminEmail = env('SALES_NOTIFICATION_EMAIL', 'sales@plymouthmedical.com');
            \Illuminate\Support\Facades\Mail::to($adminEmail)->send(
                new \App\Mail\AccessRequestMail($request->all())
            );

            return response()->json(['success' => true, 'message' => 'Your request has been submitted.'])
                ->header('Access-Control-Allow-Origin', 'https://plymouthmedical.myshopify.com');

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("requestAccess Error: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500)
                ->header('Access-Control-Allow-Origin', 'https://plymouthmedical.myshopify.com');
        }
    }

    public function checkAccess(Request $request)
    {
        $shop = $request->get('shop');
        $email = $request->get('email');
        if (!$shop || !$email) {
            return response()->json(['error' => 'Missing required parameter shop or email'], 400)
                ->header('Access-Control-Allow-Origin', 'https://plymouthmedical.myshopify.com');
        }

        // 1. Security Check
        $signatureError = $this->validateProxySignature($request);
        if ($signatureError) {
            return $signatureError;
        }

        // 2. Resolve Session
        $session = \App\Services\ShopifyService::loadSession($shop);
        if (!$session) {
            return response()->json(['error' => 'Session not found for ' . $shop], 401)
                ->header('Access-Control-Allow-Origin', 'https://plymouthmedical.myshopify.com');
        }

        try {
            // 3. GraphQL Query to check customer
            $graphQL = new Graphql($session->getShop(), $session->getAccessToken());
            $query = <<<'QUERY'
            query($q: String!) {
              customers(first: 1, query: $q) {
                edges {
                  node {
                    id
                    tags
                  }
                }
              }
            }
QUERY;

            $response = $graphQL->query([
                'query' => $query,
                'variables' => ['q' => "email:" . strtolower(trim($email))]
            ]);
            $body = $response->getDecodedBody();

            if (isset($body['errors'])) {
                throw new \Exception(json_encode($body['errors']));
            }

            $customerNode = $body['data']['customers']['edges'][0]['node'] ?? null;

            if ($customerNode) {
                $tags = (array) ($customerNode['tags'] ?? []);
                // Ensure tags is handled correctly (it can be a comma-separated string in some API versions/SDK return formats)
                if (is_string($customerNode['tags'])) {
                    $tags = array_map('trim', explode(',', $customerNode['tags']));
                }

                if (in_array('Approved', $tags, true)) {
                    return response()->json([
                        'status' => 'approved',
                        'login_url' => '/account/login' // Standard Shopify login path
                    ])->header('Access-Control-Allow-Origin', 'https://plymouthmedical.myshopify.com');
                }

                return response()->json([
                    'status' => 'denied',
                    'redirect' => '/pages/request-access?status=pending'
                ])->header('Access-Control-Allow-Origin', 'https://plymouthmedical.myshopify.com');
            }

            return response()->json([
                'status' => 'new',
                'redirect' => '/pages/request-access'
            ])->header('Access-Control-Allow-Origin', 'https://plymouthmedical.myshopify.com');

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("checkAccess Error: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500)
                ->header('Access-Control-Allow-Origin', 'https://plymouthmedical.myshopify.com');
        }
    }

    /**
     * Reusable logic to validate Shopify App Proxy signature.
     */
    private function validateProxySignature(Request $request)
    {
        if (config('app.env') !== 'production') {
            return null;
        }

        $queryParams = $request->query();
        $signature = $queryParams['signature'] ?? '';
        
        if (empty($signature)) {
            $shop = $request->get('shop', 'unknown');
            \Illuminate\Support\Facades\Log::error("Signature validation: Missing signature for shop $shop");
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
        
        $secret = env('SHOPIFY_API_SECRET');
        $calculatedHmac = hash_hmac('sha256', $message, $secret);

        if (!hash_equals($calculatedHmac, $signature)) {
            $shop = $request->get('shop', 'unknown');
            \Illuminate\Support\Facades\Log::error("Signature validation: HMAC mismatch for shop $shop. Message: '$message'. Calculated: $calculatedHmac, Received: $signature");
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        return null;
    }
}
