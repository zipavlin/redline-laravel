<x-mail::message>
# New bug report

**{{ $reporterName }}** filed a new report:

> {{ $title }}

<x-mail::button :url="$reportUrl">
Open report
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
