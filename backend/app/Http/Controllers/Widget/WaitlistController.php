<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\StoreWaitlistRequest;
use App\Models\WaitlistEntry;
use App\Support\CabinetNotifier;
use Illuminate\Http\JsonResponse;

class WaitlistController extends Controller
{
    public function store(StoreWaitlistRequest $request): JsonResponse
    {
        $entry = WaitlistEntry::create($request->validated());

        CabinetNotifier::notifyWaitlist($entry);

        return response()->json(['message' => 'Auf der Warteliste eingetragen.'], 201);
    }
}
