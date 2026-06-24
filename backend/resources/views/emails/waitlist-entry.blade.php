<x-mail::message>
# Neue Warteliste-Anfrage

Ein Elternteil hat sich auf die Warteliste eingetragen.

**Kind:** {{ $entry->patient_first_name }} {{ $entry->patient_last_name }}

**Elternteil:** {{ $entry->parent_first_name }} {{ $entry->parent_last_name }}

**Telefon:** {{ $entry->parent_phone }}

@if($entry->parent_email)
**E-Mail:** {{ $entry->parent_email }}
@endif

**Gewünschte Leistung:** {{ $entry->service?->name ?? 'Keine Präferenz' }}

@if($entry->notes)
**Notiz:** {{ $entry->notes }}
@endif

Mit freundlichen Grüßen,<br>
{{ $cabinetName }}
</x-mail::message>
