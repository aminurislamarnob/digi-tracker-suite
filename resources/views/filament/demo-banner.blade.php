@php
    $demoProjects = \App\Models\Project::query()->where('is_demo', true)->count();
@endphp

@if ($demoProjects > 0)
    {{--
        Deliberately not dismissible.

        A dashboard exists to answer questions people act on -- "can we drop
        PHP 7.4?", "is this release landing?" -- and an invented number that
        looks measured is worse than no number at all. This stays on screen
        for as long as invented data is in the account.
    --}}
    <div class="fi-demo-banner w-full bg-amber-500 px-4 py-2 text-center text-sm font-medium text-amber-950">
        <span aria-hidden="true">⚠</span>
        Demo data — every number below was invented by
        <code class="rounded bg-amber-600/25 px-1 py-0.5">telemetry:seed-demo</code>.
        Nothing here was reported by a real site.
    </div>
@endif
