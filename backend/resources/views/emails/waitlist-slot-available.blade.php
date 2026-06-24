<x-mail::message>
# Ein Termin ist verfügbar

Liebe Familie {{ $entry->parent_last_name }},

wir freuen uns, Ihnen mitteilen zu können, dass bei uns ein Termin frei geworden ist.

Bitte melden Sie sich so bald wie möglich bei uns, damit wir gemeinsam einen passenden Termin für **{{ $entry->patient_first_name }} {{ $entry->patient_last_name }}** vereinbaren können.

@if($entry->service)
**Gewünschte Leistung:** {{ $entry->service->name }}
@endif

Wir freuen uns auf Ihre Nachricht.

Mit freundlichen Grüßen,
{{ $cabinetName }}
</x-mail::message>
