<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Service;
use Illuminate\Http\JsonResponse;

class ServiceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Service::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'duration_minutes', 'color', 'description'])
        );
    }
}
