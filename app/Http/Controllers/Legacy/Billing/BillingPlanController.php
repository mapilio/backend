<?php

namespace App\Http\Controllers\Legacy\Billing;

use App\Domain\BillingCatalog\Queries\BillingPlanQuery;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingPlanController extends Controller
{
    public function packages(Request $request, BillingPlanQuery $query): JsonResponse
    {
        return response()->json($query->packages($request));
    }

    public function hosting(Request $request, BillingPlanQuery $query): JsonResponse
    {
        return response()->json($query->hosting($request));
    }
}
