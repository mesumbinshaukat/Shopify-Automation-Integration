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
    // ... existing index method ...

    public function saveDetails(Request $request)
    {
        $this->validate($request, [
            'customerId' => 'required',
            'shop' => 'required',
            'details' => 'required|array',
            'details.company_name' => 'required',
            'details.physician_name' => 'required',
            'details.npi' => 'required',
            'details.contact_name' => 'required',
            'details.contact_email' => 'required|email',
            'details.contact_phone_number' => 'required',
        ]);

        $shop = $request->shop;
        $customerId = $request->customerId; // This might be long ID or GID
        $details = $request->details;

        // Ensure customerId is just the numeric part if GID passed
        $numericCustomerId = preg_replace('/[^0-9]/', '', $customerId);

        $session = \App\Services\ShopifyService::loadSession($shop);
        if (!$session) {
            return response()->json(['error' => 'Session expired.'], 401);
        }

        try {
            $graphQL = new Graphql($session->getShop(), $session->getAccessToken());

            // 1. Create Metaobject Entry
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

            // 2. Attach to Customer Metafields
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

            // 3. Sync to Local Database
            $localCustomer = Customer::where('shopify_id', $numericCustomerId)->first();
            if (!$localCustomer) {
                // Try to find by email if shopify_id is null/0 in our DB
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
            } else {
                \Illuminate\Support\Facades\Log::warning("saveDetails: Local customer record not found for ID {$numericCustomerId} or email {$details['contact_email']}");
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
