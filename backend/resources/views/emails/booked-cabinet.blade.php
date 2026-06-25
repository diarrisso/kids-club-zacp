<x-mail::message>
# Neue Online-Buchung

Es wurde ein neuer Termin online gebucht:

- **Kind:** {{ $appointment->patient_first_name }} {{ $appointment->patient_last_name }}
- **Eltern:** {{ $appointment->parent_first_name }} {{ $appointment->parent_last_name }}
- **Kontakt:** {{ $appointment->parent_email }}@if($appointment->parent_phone) · {{ $appointment->parent_phone }}@endif
- **Datum:** {{ $appointment->clinicStartsAt()->locale('de')->translatedFormat('l, d. F Y') }}
- **Uhrzeit:** {{ $appointment->clinicStartsAt()->format('H:i') }} Uhr
- **Leistung:** {{ $appointment->service->name }}
- **Behandler:** {{ $appointment->practitioner->name }}
- **Referenz:** {{ $appointment->publicReference() }}

{{ $cabinetName }}
</x-mail::message>
