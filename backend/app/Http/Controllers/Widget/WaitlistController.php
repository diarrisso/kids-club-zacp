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
        if (filled($request->input('website'))) {
            return response()->json(['message' => 'Auf der Warteliste eingetragen.'], 201);
        }

        $data = $request->validated();

        // Dedup: an identical still-pending entry (same phone + requested service)
        // must not create a second row nor fire a second cabinet email. Blunts
        // double-submits and payload-replay spam on top of the per-IP rate limit.
        // Same 201 either way, so a caller can't tell "new" from "duplicate".
        $alreadyWaiting = WaitlistEntry::query()
            ->where('status', 'pending')
            ->where('parent_phone', trim($data['parent_phone']))
            ->where('service_id', $data['service_id'] ?? null)
            ->exists();

        if ($alreadyWaiting) {
            return response()->json(['message' => 'Auf der Warteliste eingetragen.'], 201);
        }

        $entry = WaitlistEntry::create($data);

        CabinetNotifier::notifyWaitlist($entry);

        return response()->json(['message' => 'Auf der Warteliste eingetragen.'], 201);
    }
}
