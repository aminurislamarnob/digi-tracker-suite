<x-mail::message>
# {{ $project->name }} was removed

**Reason:** {{ $reasonLabel }}

@if ($deactivation->reason_info)
<x-mail::panel>
{{ $deactivation->reason_info }}
</x-mail::panel>
@else
They didn't leave a comment.
@endif

- **Site:** {{ $site ?? 'unknown' }}
- **Plugin version:** {{ $deactivation->project_version ?? 'unknown' }}
@if ($deactivation->theme_name)
- **Theme:** {{ $deactivation->theme_name }}
@endif
- **When:** {{ $deactivation->created_at->toDayDateTimeString() }}

@if ($userEmail)
Replying to this message reaches them directly.
@else
No address on file for this site, so there is nobody to reply to.
@endif

<x-slot:subcopy>
Sent because deactivation forwarding is switched on for {{ $project->name }}. Turn it off in the
project's email settings.
</x-slot:subcopy>
</x-mail::message>
