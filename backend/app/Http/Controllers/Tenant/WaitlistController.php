<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateWaitlistRequest;
use App\Models\WaitlistEntry;
use App\Support\WaitlistStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WaitlistController extends Controller
{
    public function index(Request $request): Response
    {
        // Use has() to distinguish "param absent" (→ default 'pending') from
        // "param present but empty" (→ show all). ConvertEmptyStringsToNull
        // turns ?status= into null, so we cannot rely on the default value of
        // query('status', 'pending') to detect the "show all" intent.
        $statusFilter = $request->has('status')
            ? ($request->query('status') ?? '')
            : 'pending';

        $entries = WaitlistEntry::query()
            ->with('service')
            ->when($statusFilter !== '', fn ($q) => $q->where('status', $statusFilter))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Tenant/Waitlist/Index', [
            'entries' => $entries,
            'filters' => ['status' => $statusFilter],
            'statusOptions' => WaitlistStatus::options(),
        ]);
    }

    public function update(UpdateWaitlistRequest $request, WaitlistEntry $entry): RedirectResponse
    {
        $validated = $request->validated();
        $newStatus = WaitlistStatus::from($validated['status']);
        $childName = $entry->patient_first_name.' '.$entry->patient_last_name;

        $entry->status = $newStatus;
        $entry->save();

        return redirect()->back()->with('success', "{$childName}: Status → {$newStatus->label()}");
    }
}
