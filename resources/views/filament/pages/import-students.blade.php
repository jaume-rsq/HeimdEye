<x-filament-panels::page>
    {{ $this->form }}

    @if ($error)
        <x-filament::section>
            <div style="color: rgb(220,38,38)">{{ $error }}</div>
        </x-filament::section>
    @endif

    @if ($preview)
        @php
            $box = 'font-size: .875rem; display: grid; gap: .25rem';
        @endphp
        <x-filament::section heading="Previsualització" description="Encara no s'ha desat res.">
            <div style="display: grid; gap: 1.25rem; grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr)); align-items: start">
                <div style="{{ $box }}">
                    <strong style="color: rgb(22,163,74)">Nous ({{ count($preview['nous']) }})</strong>
                    @forelse ($preview['nous'] as $line)<div>{{ $line }}</div>@empty<div style="opacity: .6">Cap.</div>@endforelse
                </div>
                <div style="{{ $box }}">
                    <strong style="color: rgb(217,119,6)">Amb canvis ({{ count($preview['canvis']) }})</strong>
                    @forelse ($preview['canvis'] as $change)
                        <div>
                            {{ $change['alumne'] }}
                            @foreach ($change['diferencies'] as $field => [$old, $new])
                                <div style="font-size: .8rem; opacity: .8; padding-left: .75rem">
                                    <code>{{ $field }}</code>: <s>{{ $old }}</s> → {{ $new }}
                                </div>
                            @endforeach
                        </div>
                    @empty<div style="opacity: .6">Cap.</div>@endforelse
                    <div style="opacity: .6">Sense canvis: {{ $preview['iguals'] }}</div>
                </div>
                <div style="{{ $box }}">
                    <strong style="color: rgb(107,114,128)">Ja no hi són ({{ count($preview['absents']) }})</strong>
                    @forelse ($preview['absents'] as $line)<div>{{ $line }}</div>@empty<div style="opacity: .6">Cap.</div>@endforelse
                    @if ($preview['absents'])
                        <div style="font-size: .8rem; opacity: .7">
                            {{ ($data['desactivar'] ?? false) ? 'Es marcaran inactius.' : 'Es quedaran com estan.' }}
                        </div>
                    @endif
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
