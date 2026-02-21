<?php

namespace App\Services;

use Shopify\Utils;
use Shopify\Context;
use Illuminate\Support\Facades\Log;

class ShopifyService
{
    /**
     * Robustly load an offline session for a shop.
     * Includes diagnostics if loading fails.
     */
    public static function loadSession(string $shop)
    {
        // 1. Normalize shop name (Ensure it has .myshopify.com and handle common domain quirks)
        $shop = strtolower(trim($shop));
        if (!str_ends_with($shop, '.myshopify.com')) {
            // If it's just a handle, append the domain
            if (!str_contains($shop, '.')) {
                $shop .= '.myshopify.com';
            }
        }

        // 2. Attempt standard load
        $session = Utils::loadOfflineSession($shop);
        
        if ($session) {
            return $session;
        }

        // 2. Diagnostics if failed
        $sessionId = \Shopify\Auth\OAuth::getOfflineSessionId($shop);
        $rawSession = Context::$SESSION_STORAGE->loadSession($sessionId);
        
        if (!$rawSession) {
            Log::error("ShopifyService: No session file found for $shop (ID: $sessionId)");
            return null;
        }

        // 3. Analyze why it's invalid
        $sessionScopes = explode(',', (string) $rawSession->getScope());
        $requiredScopes = explode(',', (string) Context::$SCOPES->toString());
        $missingScopes = array_diff($requiredScopes, $sessionScopes);
        
        $scopesMatch = empty($missingScopes);
        $isExpired = $rawSession->getExpires() && ($rawSession->getExpires() <= new \DateTime());
        $hasToken = !empty($rawSession->getAccessToken());

        if (!$scopesMatch || $isExpired) {
            Log::warning("ShopifyService: Session for $shop is technically invalid. Diagnostics: " . 
                "Missing Scopes: " . (empty($missingScopes) ? 'None' : implode(',', $missingScopes)) . ", " .
                "Is Expired: " . ($isExpired ? 'Yes' : 'No') . ", " .
                "Has Token: " . ($hasToken ? 'Yes' : 'No')
            );
        }

        // 4. Robust Fallback: If it has a token and isn't expired, we might try to use it 
        // even if scopes don't perfectly match (e.g. library being too strict)
        if ($hasToken && !$isExpired) {
            Log::info("ShopifyService: Attempting fallback to existing session despite minor validation issues.");
            return $rawSession;
        }

        return null;
    }
}
