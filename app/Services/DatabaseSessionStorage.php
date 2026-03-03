<?php

namespace App\Services;

use Shopify\Auth\Session;
use Shopify\Auth\SessionStorage;
use Illuminate\Support\Facades\DB;

class DatabaseSessionStorage implements SessionStorage
{
    public function storeSession(Session $session): bool
    {
        DB::table('shopify_sessions')->updateOrInsert(
            ['id' => $session->getId()],
            [
                'shop'         => $session->getShop(),
                'is_online'    => $session->isOnline(),
                'scope'        => $session->getScope(),
                'access_token' => $session->getAccessToken(),
                'expires'      => $session->getExpires()?->format('Y-m-d H:i:s'),
                'updated_at'   => now(),
            ]
        );
        return true;
    }

    public function loadSession(string $id): ?Session
    {
        $row = DB::table('shopify_sessions')->where('id', $id)->first();
        if (!$row) {
            return null;
        }

        $session = new Session(
            $row->id,
            $row->shop,
            (bool)$row->is_online,
            '' // state is not persisted by Shopify PHP library usually for offline
        );
        
        if ($row->access_token) {
            $session->setAccessToken($row->access_token);
        }
        if ($row->scope) {
            $session->setScope($row->scope);
        }
        if ($row->expires) {
            $session->setExpires(new \DateTime($row->expires));
        }
        
        return $session;
    }

    public function deleteSession(string $id): bool
    {
        DB::table('shopify_sessions')->where('id', $id)->delete();
        return true;
    }
}
