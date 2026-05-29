<x-mail::message>
# Erinnerung an Ihren Termin

Hallo {{ $appointment->parent_first_name }},

wir möchten Sie an den morgigen Termin für **{{ $appointment->patient_first_name }}** bei **{{ $cabinetName }}** erinnern.

- **Datum:** {{ $appointment->starts_at->timezone('Europe/Berlin')->locale('de')->translatedFormat('l, d. F Y') }}
- **Uhrzeit:** {{ $appointment->starts_at->timezone('Europe/Berlin')->format('H:i') }} Uhr
- **Leistung:** {{ $appointment->service->name }}

<x-mail::button :url="$cancelUrl">
Termin stornieren
</x-mail::button>

Bis morgen!<br>
{{ $cabinetName }}
</x-mail::message>
