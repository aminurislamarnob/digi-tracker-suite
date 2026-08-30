@php
    use App\Filament\Resources\Projects\ProjectResource;

    $flags = $this->getFlags();
@endphp

{{--
    A root element unconditionally: Livewire cannot render a component
    whose template resolves to no root tag, and this widget is legitimately
    empty most days.
--}}
<div>
    @if ($flags->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">Needs attention</x-slot>
            <x-slot name="description">
                {{ trans_choice(':count thing|:count things', $flags->count(), ['count' => $flags->count()]) }}
                worth a look. Nothing here is measuring badly — these are places we are not measuring at all.
            </x-slot>

            <ul class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($flags as $flag)
                    <li class="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
                        {{--
                            A dot rather than an icon. At this size an icon
                            per row competes with the project name for the
                            eye, and the only thing the mark has to carry is
                            severity.
                        --}}
                        <span @class([
                            'mt-1.5 size-2 shrink-0 rounded-full',
                            'bg-danger-500' => $flag['colour'] === 'danger',
                            'bg-warning-500' => $flag['colour'] !== 'danger',
                        ])></span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <a
                                    href="{{ ProjectResource::getUrl('view', ['record' => $flag['project']]) }}"
                                    wire:navigate
                                    class="text-sm font-medium text-gray-950 hover:underline dark:text-white"
                                >
                                    {{ $flag['project']->name }}
                                </a>

                                <x-filament::badge :color="$flag['colour']" size="sm">
                                    {{ $flag['title'] }}
                                </x-filament::badge>
                            </div>

                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                {{ $flag['body'] }}
                            </p>
                        </div>

                        {{--
                            Straight to the page that shows the problem.
                            "Never captured" is fixed on the repository tab,
                            not on the overview.
                        --}}
                        <a
                            href="{{ ProjectResource::getUrl(
                                $flag['project']->isOnRepository() ? 'repository' : 'view',
                                ['record' => $flag['project']],
                            ) }}"
                            wire:navigate
                            class="shrink-0 self-center text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                        >
                            Open
                        </a>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</div>
