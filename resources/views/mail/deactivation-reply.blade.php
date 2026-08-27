<x-mail::message>
# Thanks — that's genuinely useful

You removed **{{ $project->name }}** and took a moment to say why. Most people don't, so
thank you.

You told us:

<x-mail::panel>
{{ $comment }}
</x-mail::panel>

@if ($reasonLabel)
Filed under *{{ $reasonLabel }}*.
@endif

{{-- No discount code, no "are you sure", no reinstall link. They removed the plugin on
     purpose; a reply that argues with that decision is why people stop giving feedback. --}}
We read every one of these. If it was something we can fix, it goes on the list — and if you
replied to this message with more detail, it would reach us directly.

@if ($footer)
{{ $footer }}
@endif

Thanks again,<br>
{{ $project->from_name ?: $project->name }}

<x-slot:subcopy>
This is a one-off reply to the feedback you sent. We won't email you again about it.
[Unsubscribe]({{ $unsubscribeUrl }}) to make sure of it.
</x-slot:subcopy>
</x-mail::message>
