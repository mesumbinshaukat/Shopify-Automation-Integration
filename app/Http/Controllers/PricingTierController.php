<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PricingTier;

class PricingTierController extends Controller
{
    public function index()
    {
        return response()->json(PricingTier::all());
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'name' => 'required|unique:pricing_tiers',
            'discount_type' => 'required|in:percentage,fixed_amount',
            'discount_value' => 'required|numeric'
        ]);

        $tier = PricingTier::create($request->all());
        return response()->json($tier, 201);
    }

    public function update(Request $request, $id)
    {
        $tier = PricingTier::findOrFail($id);
        $tier->update($request->all());
        return response()->json($tier);
    }

    public function destroy($id)
    {
        PricingTier::findOrFail($id)->delete();
        return response()->json(null, 204);
    }
}
