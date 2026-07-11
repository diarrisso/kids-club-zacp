<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\StoreWaitlistRequest;
use App\Models\WaitlistEntry;
use App\Support\CabinetNotifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;

class WaitlistController extends Controller
{
    public function store(StoreWaitlistRequest $request): JsonResponse
    {
        if (filled($request->input('website'))) {
            return $this->ok();
        }

        $data = $request->validated();

        // Dedup fast path: an identical still-pending entry (same phone + requested
        // service) must not create a second row nor fire a second cabinet email.
        // Blunts double-submits and payload-replay spam on top of the per-IP limit.
        $alreadyWaiting = WaitlistEntry::query()
            ->where('status', 'pending')
            ->where('parent_phone', trim($data['parent_phone']))
            ->where('service_id', $data['service_id'] ?? null)
            ->exists();

        if ($alreadyWaiting) {
            return $this->ok();
        }

        // Race backstop: the exists-check above is not atomic — two concurrent
        // identical posts could both pass it. A partial unique index enforces the
        // same dedup at the DB level, so the loser's insert throws here; swallow it
        // and return the same 201, without a second cabinet email.
        try {
            $entry = WaitlistEntry::create($data);
        } catch (UniqueConstraintViolationException) {
            return $this->ok();
        }

        CabinetNotifier::notifyWaitlist($entry);

        return $this->ok();
    }

    /** Uniform 201 — a caller can never tell "new" from "duplicate" from honeypot. */
    private function ok(): JsonResponse
    {
        return response()->json(['message' => 'Auf der Warteliste eingetragen.'], 201);
    }
}
