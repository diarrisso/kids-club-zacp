<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StatisticsRequest;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

class StatisticsController extends Controller
{
    private const TZ = 'Europe/Berlin';

    public function index(StatisticsRequest $request): Response
    {
        $user = $request->user();
        $data = $request->validated();
        $now = CarbonImmutable::now(self::TZ);

        $from = isset($data['from'])
            ? CarbonImmutable::parse($data['from'], self::TZ)->startOfDay()
            : $now->subDays(30)->startOfDay();
        $to = isset($data['to'])
            ? CarbonImmutable::parse($data['to'], self::TZ)->endOfDay()
            : $now->endOfDay();

        // No-show only makes sense for appointments that already happened: cap the
        // upper bound at "now" so future slots (attendance null by nature) never count.
        $upperBound = $to->greaterThan($now) ? $now : $to;

        // A medecin is ALWAYS scoped to their own practitioner (fail-closed): an
        // unlinked medecin (practitioner_id null) sees nothing — never the whole
        // cabinet. Reception/admin (non-medecin) see everything.
        $isMedecin = $user->isMedecin();
        $practitionerId = $isMedecin ? $user->practitioner_id : null;

        // ONE aggregated query. toBase() bypasses Eloquent casting so $row->attendance
        // is the raw string ('arrived'/'no_show') or null — never the enum.
        $rows = Appointment::query()
            ->where('status', '!=', 'cancelled')
            ->whereBetween('starts_at', [$from, $upperBound])
            // Linked medecin → their own rows; unlinked medecin → none (?? -1 can
            // never match a real practitioner id, so the result is empty). Bound
            // param, no raw SQL.
            ->when($isMedecin, fn ($q) => $q->where('practitioner_id', $practitionerId ?? -1))
            ->selectRaw('practitioner_id, attendance, COUNT(*) as total')
            ->groupBy('practitioner_id', 'attendance')
            ->toBase()
            ->get();

        $arrived = 0;
        $noShow = 0;
        $notRecorded = 0;
        $byPract = []; // practitioner_id => ['arrived'=>int, 'noShow'=>int]

        foreach ($rows as $row) {
            $total = (int) $row->total;
            $pid = $row->practitioner_id;
            $byPract[$pid] ??= ['arrived' => 0, 'noShow' => 0];

            if ($row->attendance === 'arrived') {
                $arrived += $total;
                $byPract[$pid]['arrived'] += $total;
            } elseif ($row->attendance === 'no_show') {
                $noShow += $total;
                $byPract[$pid]['noShow'] += $total;
            } else {
                $notRecorded += $total;
            }
        }

        $practitioners = Practitioner::query()
            ->whereIn('id', array_keys($byPract))
            ->get()
            ->keyBy('id');

        $perPractitioner = collect($byPract)
            ->map(function (array $c, $pid) use ($practitioners) {
                $p = $practitioners->get($pid);
                $denom = $c['arrived'] + $c['noShow'];

                return [
                    'id' => $pid,
                    'name' => $p?->fullName() ?? '—',
                    'color' => $p?->color ?? '#94a3b8',
                    'arrived' => $c['arrived'],
                    'noShow' => $c['noShow'],
                    'rate' => $denom > 0 ? round($c['noShow'] / $denom * 100, 1) : null,
                ];
            })
            // Sort by no-show rate desc; null rates (nothing recorded) sink to the bottom.
            ->sortByDesc(fn (array $r) => $r['rate'] ?? -1)
            ->values()
            ->all();

        $denom = $arrived + $noShow;

        return Inertia::render('Tenant/Statistics/Index', [
            'kpis' => [
                'arrived' => $arrived,
                'noShow' => $noShow,
                'notRecorded' => $notRecorded,
                'rate' => $denom > 0 ? round($noShow / $denom * 100, 1) : null,
            ],
            'perPractitioner' => $perPractitioner,
            // `to` echoes the user's requested range for display; the query itself
            // is capped at `$upperBound` (min(to, now)). Keep these distinct on purpose.
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'scoped' => $isMedecin,
        ]);
    }
}
