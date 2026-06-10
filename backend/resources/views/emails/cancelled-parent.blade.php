<x-mail::message>
# Ihr Termin wurde storniert

Guten Tag {{ $appointment->parent_first_name }} {{ $appointment->parent_last_name }},

der folgende Termin wurde storniert:

- **Referenz:** {{ $appointment->publicReference() }}
- **Datum:** {{ $appointment->starts_at->timezone('Europe/Berlin')->locale('de')->translatedFormat('l, d. F Y') }}
- **Uhrzeit:** {{ $appointment->starts_at->timezone('Europe/Berlin')->format('H:i') }} Uhr
- **Leistung:** {{ $appointment->service->name }}
- **Behandler:in:** {{ $appointment->practitioner->fullName() }}
- **Kind:** {{ $appointment->patient_first_name }} {{ $appointment->patient_last_name }}

Möchten Sie einen neuen Termin vereinbaren? Besuchen Sie unsere Website oder kontaktieren Sie uns direkt.

Freundliche Grüße
{{ $cabinetName }}
</x-mail::message>
