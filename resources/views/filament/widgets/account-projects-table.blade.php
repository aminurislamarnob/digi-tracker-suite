@php
    use App\Filament\Resources\Projects\ProjectResource;

    $rows = $this->getRows();
@endphp

<div>
    <x-filament::section>
        <x-slot name="heading">Projects</x-slot>
        <x-slot name="description">
            What we measure beside what wordpress.org publishes. A dash means not measured,
            which is never the same as measured as nothing.
        </x-slot>

        @if ($rows->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No active projects yet.
            </p>
        @else
            {{--
                The table scrolls inside its own box rather than pushing the
                page sideways: seven columns do not fit a laptop once the
                sidebar has taken its share.
            --}}
            <div class="-mx-6 overflow-x-auto">
                <table class="w-full min-w-[48rem] text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="px-6 py-2 text-left font-medium">Project</th>
                            <th class="px-3 py-2 text-right font-medium">Tracked</th>
                            <th class="px-3 py-2 text-right font-medium">Public installs</th>
                            <th class="px-3 py-2 text-right font-medium">Opt-in</th>
                            <th class="px-3 py-2 text-right font-medium">Downloads</th>
                            <th class="px-3 py-2 text-left font-medium">Version</th>
                            <th class="px-6 py-2 text-right font-medium">Last seen</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($rows as $row)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="px-6 py-3">
                                    <a
                                        href="{{ ProjectResource::getUrl('view', ['record' => $row['project']]) }}"
                                        wire:navigate
                                        class="font-medium text-gray-950 hover:underline dark:text-white"
                                    >
                                        {{ $row['project']->name }}
                                    </a>

                                    @unless ($row['project']->isOnRepository())
                                        {{--
                                            Stated plainly rather than left
                                            to be inferred from three empty
                                            columns, which reads as missing
                                            data rather than a deliberate
                                            absence.
                                        --}}
                                        <p class="text-xs text-gray-400 dark:text-gray-500">not on wordpress.org</p>
                                    @endunless
                                </td>

                                {{-- tabular-nums so the columns line up digit for digit. --}}
                                <td class="px-3 py-3 text-right tabular-nums text-gray-950 dark:text-white">
                                    {{ $row['tracked'] !== null ? number_format($row['tracked']) : '—' }}
                                </td>

                                <td class="px-3 py-3 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                    {{ $row['publicInstalls'] !== null ? number_format($row['publicInstalls']) : '—' }}
                                </td>

                                <td class="px-3 py-3 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                    {{ $row['optInRate'] !== null ? $row['optInRate'].'%' : '—' }}
                                </td>

                                <td class="px-3 py-3 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                    {{ $row['downloads'] !== null ? number_format($row['downloads']) : '—' }}
                                </td>

                                <td class="px-3 py-3">
                                    @if ($row['version'])
                                        <x-filament::badge color="gray" size="sm">{{ $row['version'] }}</x-filament::badge>
                                    @else
                                        <span class="text-gray-400 dark:text-gray-500">—</span>
                                    @endif
                                </td>

                                <td class="px-6 py-3 text-right text-gray-500 dark:text-gray-400">
                                    {{ $row['lastRollup']?->diffForHumans() ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</div>
