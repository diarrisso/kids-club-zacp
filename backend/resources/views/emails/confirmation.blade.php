<x-mail::message>
# Ihr Termin ist bestätigt

Hallo {{ $appointment->parent_first_name }},

der Termin für **{{ $appointment->patient_first_name }}** bei **{{ $cabinetName }}** wurde gebucht.

- **Datum:** {{ $appointment->clinicStartsAt()->locale('de')->translatedFormat('l, d. F Y') }}
- **Uhrzeit:** {{ $appointment->clinicStartsAt()->format('H:i') }} Uhr
- **Leistung:** {{ $appointment->service->name }}
- **Behandler:in:** {{ $appointment->practitioner->fullName() }}

<x-mail::button :url="$cancelUrl">
Termin stornieren
</x-mail::button>

Sollten Sie den Termin nicht wahrnehmen können, stornieren Sie ihn bitte rechtzeitig über den Button oben.

Mit freundlichen Grüßen,<br>
{{ $cabinetName }}
</x-mail::message>
