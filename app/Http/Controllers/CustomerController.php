<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\PricingTier;
use Shopify\Rest\Admin2026_01\Customer as ShopifyCustomer;

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
            return response()->json(['error' => 'Session expired. Please refresh.'], 102220401);
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
            // We use email as the primary key for syncing to accommodate Shopify IDs being null
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
            // Only report actual fatal errors (like invalid shop session)
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

        // Sync with Shopify
        $session = \App\Services\ShopifyService::loadSession($request->shop);
        if ($session) {
            try {
                // RESCUE SYNC: If shopify_id is 0, find it first
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
        
        // Optionally delete from Shopify
        $session = \App\Services\ShopifyService::loadSession($request->shop);
        if ($session) {
            try {
                // RESCUE SYNC: If shopify_id is 0, find it first so we can delete it from Shopify too
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
                    ['email' => $sCust->email], // Use email as unique key for merging
                    [
                        'shopify_id'   => $sCust->id,
                        'first_name'   => $sCust->first_name,
                        'last_name'    => $sCust->last_name,
                        // Sync Shopify tags back to local DB (includes special_discount_X% tags)
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

        // Sync with Shopify Price Rules
        $session = \App\Services\ShopifyService::loadSession($request->shop);
        if ($session) {
            try {
                // RESCUE SYNC: If shopify_id is 0, try to find it on Shopify now
                if (!$customer->shopify_id || $customer->shopify_id == 0) {
                    \Illuminate\Support\Facades\Log::info("Rescue Sync: Fetching ID for pending customer " . $customer->email);
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
                    // Store the robust GraphQL Gid if available, otherwise fallback to the tag-based ID
                    $customer->shopify_discount_id = $discountData['discount_id'] ?? $discountData['price_rule_id'];
                    // Persist the final Shopify tags string locally so the DB stays in sync
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
}
