<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\PricingTier;

class ShopifyController extends Controller
{
    public function install(Request $request)
    {
        $shop = $request->query('shop');
        if (!$shop) {
            return response('Missing shop parameter', 400);
        }

        // Redirect to a top-level route to ensure first-party cookie context
        $beginUrl = "/auth/begin?shop={$shop}";
        return view('breakout', ['installUrl' => $beginUrl]);
    }

    public function begin(Request $request)
    {
        $shop = $request->query('shop');
        if (!$shop) {
            return response('Missing shop parameter', 400);
        }

        try {
            $installUrl = \Shopify\Auth\OAuth::begin(
                $shop,
                '/auth/callback',
                false, // isOnline = false (requesting offline session)
                [$this, 'setShopifyCookie'] // Custom cookie setter to fix path/SameSite issues
            );

            return redirect($installUrl);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('OAuth begin failed for ' . $shop . ': ' . $e->getMessage());
            return response('Authentication failed: ' . $e->getMessage(), 500);
        }
    }

    public function callback(Request $request)
    {
        $shop = $request->query('shop');
        if (!$shop) {
            return response('Missing shop parameter', 400);
        }

        try {
            $session = \Shopify\Auth\OAuth::callback(
                $_COOKIE,
                $request->query(),
                [$this, 'setShopifyCookie']
            );
            
            // Explicitly store the session and log the result
            $stored = \Shopify\Context::$SESSION_STORAGE->storeSession($session);
            \Illuminate\Support\Facades\Log::info("Session stored for {$shop}. ID: {$session->getId()}, Online: " . ($session->isOnline() ? 'Yes' : 'No') . ", Success: " . ($stored ? 'Yes' : 'No'));

            // Verify the file actually exists on disk (Hostinger/Lumen quirk check)
            $sessionPath = storage_path('framework/sessions/' . $session->getId());
            if (file_exists($sessionPath)) {
                \Illuminate\Support\Facades\Log::info("Verified: Session disk file exists at $sessionPath");
            } else {
                \Illuminate\Support\Facades\Log::error("CRITICAL: Session file MISSING from disk after storeSession. Expected: $sessionPath");
            }
            
            $host = $request->query('host');
            return redirect("/dashboard?shop={$shop}&host={$host}");

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Authentication callback failed for ' . $shop . ': ' . $e->getMessage());
            return response('Authentication failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Custom cookie setter to ensure cookies are set with Path=/ and SameSite=None
     */
    public function setShopifyCookie(\Shopify\Auth\OAuthCookie $cookie)
    {
        $options = [
            'expires' => $cookie->getExpire(),
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'None', // Required for Shopify embedded apps
        ];

        return setcookie($cookie->getName(), $cookie->getValue(), $options);
    }
    
    public function dashboard()
    {
        $customers = Customer::all();
        return view('dashboard', compact('customers'));
    }
}
