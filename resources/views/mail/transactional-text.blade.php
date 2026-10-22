{{ $heading }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
@if ($cta)
{{ $cta['label'] }}: {{ $cta['url'] }}

@endif
--
Pilotwardrobe never asks for your password, card details or verification codes by email or phone.
@if ($unsubscribeUrl)
Manage email preferences: {{ $unsubscribeUrl }}
@endif
