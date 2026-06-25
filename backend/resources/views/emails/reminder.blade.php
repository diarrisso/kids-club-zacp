<x-mail::message>
# Erinnerung an Ihren Termin

Hallo {{ $appointment->parent_first_name }},

{{ $reminderMessage }}

- **Referenz:** {{ $appointment->publicReference() }}
- **Datum:** {{ $appointment->clinicStartsAt()->locale('de')->translatedFormat('l, d. F Y') }}
- **Uhrzeit:** {{ $appointment->clinicStartsAt()->format('H:i') }} Uhr
- **Leistung:** {{ $appointment->service->name }}

<x-mail::button :url="$cancelUrl">
Termin stornieren
</x-mail::button>

Bis bald!<br>
{{ $cabinetName }}
</x-mail::message>
