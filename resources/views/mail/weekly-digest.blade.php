<x-mail::message>
# {{ $project->name }} this week

<x-mail::table>
| Metric              | Now                                    | Change |
| :------------------ | -------------------------------------: | -----: |
| Tracked installs    | {{ number_format($summary['installs']) }} | {{ $summary['installsDelta'] === null ? '—' : ($summary['installsDelta'] > 0 ? '+' : '') . number_format($summary['installsDelta']) }} |
| New installs        | {{ number_format($summary['new']) }}   |        |
| Deactivations       | {{ number_format($summary['deactivations']) }} |  |
| Came back           | {{ number_format($summary['reactivations']) }} |  |
| Opt-in rate         | {{ $summary['optInRate'] }}%           |        |
</x-mail::table>

@if ($summary['comments']->isNotEmpty())
## What people said on their way out

@foreach ($summary['comments'] as $comment)
> {{ $comment->reason_info }}
>
> — *{{ $comment->reasonLabel() ?? 'no reason given' }}*

@endforeach
@else
Nobody left a comment this week.
@endif

@if ($summary['topVersion'])
{{-- The single most actionable line in the whole email: it is the difference
     between "the release went out" and "the release arrived". --}}
{{ $summary['topVersionShare'] }}% of tracked installs are on {{ $summary['topVersion'] }}.
@endif

<x-mail::button :url="$summary['url']">
Open the dashboard
</x-mail::button>

<x-slot:subcopy>
Counts are claimed, not proven — telemetry is unauthenticated by protocol design, and tracked
installs only counts sites that opted in. Turn this digest off in the project's email settings.
</x-slot:subcopy>
</x-mail::message>
