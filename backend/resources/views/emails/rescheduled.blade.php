<x-mail::message>
# Ihr Termin wurde verschoben

Hallo {{ $appointment->parent_first_name }},

der Termin für **{{ $appointment->patient_first_name }}** bei **{{ $cabinetName }}** wurde verschoben.

**Bisher:** {{ $oldStart->locale('de')->translatedFormat('l, d. F Y') }}, {{ $oldStart->format('H:i') }} Uhr — bei {{ $oldPractitionerName }}

**Neu:**

- **Datum:** {{ $appointment->clinicStartsAt()->locale('de')->translatedFormat('l, d. F Y') }}
- **Uhrzeit:** {{ $appointment->clinicStartsAt()->format('H:i') }} Uhr
- **Leistung:** {{ $appointment->service->name }}
- **Behandler:in:** {{ $appointment->practitioner->fullName() }}

<x-mail::button :url="$cancelUrl">
Termin stornieren
</x-mail::button>

Falls der neue Termin nicht passt, stornieren Sie ihn bitte über den Button oben.

Mit freundlichen Grüßen,<br>
{{ $cabinetName }}
</x-mail::message>
