<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Termin stornieren — {{ $cabinetName }}</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #f3f4f6; margin: 0; padding: 2rem 1rem; color: #111827; }
        .card { max-width: 32rem; margin: 0 auto; background: #fff; border-radius: 0.75rem; padding: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        h1 { font-size: 1.25rem; margin: 0 0 1rem; }
        dl { margin: 1rem 0; }
        dt { font-weight: 600; color: #6b7280; font-size: .875rem; }
        dd { margin: 0 0 .75rem; }
        button { background: #dc2626; color: #fff; border: 0; border-radius: .5rem; padding: .75rem 1.5rem; font-size: 1rem; cursor: pointer; }
        button:hover { background: #b91c1c; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Termin stornieren</h1>
        <p>Möchten Sie den folgenden Termin bei <strong>{{ $cabinetName }}</strong> stornieren?</p>
        <dl>
            <dt>Datum</dt>
            <dd>{{ $appointment->starts_at->timezone('Europe/Berlin')->locale('de')->translatedFormat('l, d. F Y') }}</dd>
            <dt>Uhrzeit</dt>
            <dd>{{ $appointment->starts_at->timezone('Europe/Berlin')->format('H:i') }} Uhr</dd>
            <dt>Leistung</dt>
            <dd>{{ $appointment->service->name }}</dd>
            <dt>Kind</dt>
            <dd>{{ $appointment->patient_first_name }}</dd>
        </dl>
        <form method="POST" action="{{ route('storno.cancel', ['token' => $token]) }}">
            @csrf
            <button type="submit">Termin stornieren</button>
        </form>
    </div>
</body>
</html>
