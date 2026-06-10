<x-mail::message>
# Ein Termin wurde storniert

Ein Patiententermin wurde über die Online-Buchung storniert.

- **Datum:** {{ $appointment->clinicStartsAt()->locale('de')->translatedFormat('l, d. F Y') }}
- **Uhrzeit:** {{ $appointment->clinicStartsAt()->format('H:i') }} Uhr
- **Leistung:** {{ $appointment->service->name }}
- **Behandler:in:** {{ $appointment->practitioner->fullName() }}
- **Kind:** {{ $appointment->patient_first_name }} {{ $appointment->patient_last_name }}
- **Elternteil:** {{ $appointment->parent_first_name }} {{ $appointment->parent_last_name }}

Der Termin ist nun wieder frei buchbar.

{{ $cabinetName }}
</x-mail::message>
