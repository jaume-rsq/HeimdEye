@php
    $statePath = $getStatePath();
    $rows = $getRows();
    $headers = $getHeaders();
    $isLive = $isLive();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @once
        <style>
            .he-picker-head { position: sticky; top: 0; z-index: 1; background: var(--gray-50, #f9fafb); }
            .dark .he-picker-head { background: var(--gray-800, #1f2937); }
            .he-picker-row { border-top: 1px solid rgba(128,128,128,.15); cursor: pointer; }
            .he-picker-row:hover { background: rgba(128,128,128,.06); }
            .he-picker-row.he-on { background: rgba(245,158,11,.12); }
        </style>
    @endonce
    <div
        x-data="{
            state: $wire.$entangle(@js($statePath), @js($isLive)),
            search: '',
            get selected() { return (this.state ?? []).map(String) },
            isOn(id) { return this.selected.includes(id) },
            toggle(id) {
                this.state = this.isOn(id) ? this.selected.filter(v => v !== id) : [...this.selected, id]
            },
            visibleIds() {
                return [...this.$refs.body.querySelectorAll('tr[data-id]')]
                    .filter(tr => tr.style.display !== 'none')
                    .map(tr => tr.dataset.id)
            },
            setVisible(on) {
                const ids = this.visibleIds()
                this.state = on
                    ? [...new Set([...this.selected, ...ids])]
                    : this.selected.filter(v => ! ids.includes(v))
            },
            get allVisibleOn() {
                const selected = this.selected, search = this.search
                const ids = this.$refs.body ? this.visibleIds() : []
                return ids.length > 0 && ids.every(id => selected.includes(id))
            },
            matches(text) { return text.includes(this.search.trim().toLowerCase()) },
        }"
        style="display: flex; flex-direction: column; gap: .5rem"
    >
        <div style="display: flex; flex-wrap: wrap; gap: .5rem; align-items: center">
            <input
                type="search"
                x-model.debounce.200ms="search"
                placeholder="Cerca..."
                style="flex: 1 1 12rem; min-width: 0; padding: .35rem .6rem; border: 1px solid rgba(128,128,128,.35); border-radius: .5rem; background: transparent; font-size: .875rem"
            />
            <button type="button" x-on:click="setVisible(true)" style="font-size: .8rem; font-weight: 600; color: rgb(217,119,6)">Marcar tots</button>
            <button type="button" x-on:click="setVisible(false)" style="font-size: .8rem; font-weight: 600; color: rgb(107,114,128)">Desmarcar tots</button>
            <span style="font-size: .8rem; opacity: .7" x-text="selected.length + ' de {{ count($rows) }} seleccionats'"></span>
        </div>

        <div style="max-height: 18rem; overflow: auto; border: 1px solid rgba(128,128,128,.25); border-radius: .5rem">
            <table style="width: 100%; border-collapse: collapse; font-size: .85rem">
                <thead class="he-picker-head" wire:ignore>
                    <tr>
                        <th style="width: 2rem; padding: .4rem .5rem; text-align: left">
                            <input type="checkbox" :checked="allVisibleOn" x-on:change="setVisible($event.target.checked)" aria-label="Marcar tots" />
                        </th>
                        @foreach ($headers as $header)
                            <th style="padding: .4rem .5rem; text-align: left; font-weight: 600; white-space: nowrap">{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody x-ref="body">
                    @forelse ($rows as $id => $cells)
                        <tr
                            data-id="{{ $id }}"
                            wire:key="{{ $getLivewireKey() }}.row.{{ $id }}"
                            x-show="matches(@js(mb_strtolower(implode(' ', array_filter($cells)))))"
                            x-on:click="if ($event.target.tagName !== 'INPUT') toggle(@js((string) $id))"
                            class="he-picker-row"
                            :class="{ 'he-on': isOn(@js((string) $id)) }"
                        >
                            <td style="padding: .35rem .5rem">
                                <input type="checkbox" :checked="isOn(@js((string) $id))" x-on:change="toggle(@js((string) $id))" />
                            </td>
                            @foreach ($cells as $cell)
                                <td style="padding: .35rem .5rem; white-space: nowrap">{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($headers) + 1 }}" style="padding: .75rem; opacity: .6">No hi ha elements.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-dynamic-component>
