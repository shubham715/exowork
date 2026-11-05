<x-mail::message>
# {{ $greeting }}

@foreach ($introLines as $line)
{{ $line }}

@endforeach
<x-mail::button :url="$actionUrl">
{{ $actionText }}
</x-mail::button>

EXOWORK Administration
</x-mail::message>
