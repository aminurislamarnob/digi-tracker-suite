@props(['title', 'counts'])

<div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
    <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ $title }}</h3>

    {{-- A zero total is not the same as no data: three site statuses all at
     zero is a real, populated distribution of nothing. Both render as
     "no data yet", but only one of them would divide by zero. --}}
@php($total = array_sum($counts))

@if (empty($counts) || $total === 0)
        <p class="py-10 text-sm text-center text-gray-400 dark:text-gray-500">No data yet</p>
    @else
        {{-- Data travels in a JSON script tag rather than a data- attribute:
             locale and theme names contain quotes and slashes, and one
             unescaped apostrophe would silently break the chart. --}}
        <div class="mt-4 donut-chart" data-donut>
            <script type="application/json">@json($counts)</script>
        </div>

        <dl class="mt-4 space-y-1.5">
            @foreach ($counts as $label => $count)
                <div class="flex items-center justify-between text-sm">
                    <dt class="text-gray-600 truncate dark:text-gray-400">{{ $label }}</dt>
                    <dd class="ml-3 shrink-0 tabular-nums text-gray-800 dark:text-white/90">
                        {{ number_format($count) }}
                        <span class="text-gray-400">({{ round($count / $total * 100) }}%)</span>
                    </dd>
                </div>
            @endforeach
        </dl>
    @endif
</div>
