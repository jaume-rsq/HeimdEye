<x-filament-panels::page>
    @php
        $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', ''), '0'), ',');
        $statusColor = fn ($estat) => match ($estat?->value) {
            'pass' => 'rgb(22,163,74)',
            'fail' => 'rgb(220,38,38)',
            null, 'omes' => 'rgb(156,163,175)',
            default => 'rgb(217,119,6)',
        };
        $student = $this->student;
    @endphp
    <style>
        .he-tr { font-size: .875rem; display: grid; gap: 1rem; }
        .he-tr select, .he-tr .he-btn { padding: .35rem .6rem; border: 1px solid rgba(128,128,128,.35); border-radius: .5rem; background: transparent; font-size: .85rem; }
        .he-tr .he-btn { cursor: pointer; white-space: nowrap; }
        .he-tr .he-btn:hover { background: rgba(245,158,11,.15); }
        .he-tr .he-btn[disabled] { opacity: .35; cursor: default; }
        .he-tr .bar { position: relative; height: .45rem; border-radius: 999px; background: rgba(128,128,128,.18); overflow: hidden; }
        .he-tr .bar > span { position: absolute; inset: 0 auto 0 0; background: rgb(245,158,11); }
        .he-tr .bar.done > span { background: rgb(22,163,74); }
        .he-tr .sub { font-size: .75rem; opacity: .65; }
        .he-tr .cards { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr)); }
        .he-tr .card { border: 1px solid rgba(128,128,128,.25); border-radius: .75rem; padding: .75rem 1rem; cursor: pointer; display: grid; gap: .4rem; text-align: left; }
        .he-tr .card.on { border-color: rgb(245,158,11); box-shadow: 0 0 0 1px rgb(245,158,11); }
        .he-tr table { width: 100%; border-collapse: collapse; }
        .he-tr th, .he-tr td { padding: .45rem .5rem; text-align: left; vertical-align: top; border-bottom: 1px solid rgba(128,128,128,.15); }
        .he-tr th { font-size: .72rem; text-transform: uppercase; letter-spacing: .03em; opacity: .7; }
        .he-tr .grp td { background: rgba(128,128,128,.08); font-weight: 600; }
        .he-tr .blk td { background: rgba(245,158,11,.1); font-weight: 700; }
        .he-tr .chip { display: inline-block; padding: .05rem .5rem; border-radius: 999px; font-size: .75rem; font-weight: 600; color: #fff; white-space: nowrap; }
        .he-tr .dot { display: inline-block; width: .55rem; height: .55rem; border-radius: 999px; margin-right: .3rem; }
        .he-tr pre { white-space: pre-wrap; font-size: .75rem; max-height: 20rem; overflow: auto; background: rgba(128,128,128,.08); padding: .5rem; border-radius: .5rem; margin: 0; }
        .he-tr .row-main { cursor: pointer; }
        .he-tr .row-main:hover td { background: rgba(128,128,128,.05); }
    </style>

    <div class="he-tr">
        @if (! $student)
            <x-filament::section>No hi ha cap alumne en els teus mòduls.</x-filament::section>
        @else
            {{-- Capçalera de l'alumne --}}
            <div style="display: flex; flex-wrap: wrap; gap: .75rem; align-items: center">
                <button type="button" class="he-btn" wire:click="go(-1)" @disabled(! $this->neighbour(-1)) title="Alumne anterior">←</button>
                <select wire:model.live="alumne" aria-label="Alumne" style="min-width: 16rem">
                    @foreach ($this->students as $s)
                        <option value="{{ $s->id }}">{{ sprintf('%02d', $s->num_taula) }} · {{ $s->nom_complet }}{{ $s->es_actiu ? '' : ' (inactiu)' }}</option>
                    @endforeach
                </select>
                <button type="button" class="he-btn" wire:click="go(1)" @disabled(! $this->neighbour(1)) title="Alumne següent">→</button>
                <div>
                    <strong style="font-size: 1.05rem">{{ $student->nom_complet }}</strong>
                    <span class="sub">· taula {{ sprintf('%02d', $student->num_taula) }} · {{ $student->usuari }}{{ $student->alies ? ' · àlies «'.$student->alies.'»' : '' }}{{ $student->es_actiu ? '' : ' · inactiu' }}</span>
                </div>
                <a class="he-btn" style="margin-left: auto" href="{{ \App\Filament\Resources\Students\StudentResource::getUrl('edit', ['record' => $student->id]) }}">Fitxa</a>
            </div>

            {{-- Una targeta per mòdul --}}
            @if ($this->cards === [])
                <x-filament::section>Aquest alumne no està matriculat en cap dels teus mòduls.</x-filament::section>
            @endif
            <div class="cards">
                @foreach ($this->cards as $card)
                    @php $row = $card['row']; @endphp
                    <button type="button" wire:click="$set('modul', {{ $card['module']->id }})" @class(['card', 'on' => $card['module']->id === $modul])>
                        <div style="display: flex; justify-content: space-between; gap: .5rem">
                            <strong>{{ trim($card['module']->codi.' '.$card['module']->nom) }}</strong>
                            <span class="sub">{{ $card['module']->academicYear?->nom }}</span>
                        </div>
                        @if ($row)
                            <div>
                                <strong style="font-size: 1.1rem">{{ $fmt($row['punts']) }}</strong> / {{ $fmt($card['board']['punts_maxims']) }} punts
                                · <strong>{{ $row['posicio'] }}a</strong> de {{ $card['alumnes'] }}
                            </div>
                            <div>
                                <div @class(['bar', 'done' => $row['percentatge'] === 100])><span style="width: {{ $row['percentatge'] }}%"></span></div>
                                <div class="sub">Obligatoris {{ $row['obligatoris'] }}/{{ $row['obligatoris_total'] }} · {{ $row['percentatge'] }}%</div>
                            </div>
                            @foreach ($card['board']['blocks'] as $b)
                                @php $cell = $row['blocs'][$b['block']->id]; @endphp
                                <div style="display: grid; grid-template-columns: 4.5rem 1fr 2.5rem; gap: .4rem; align-items: center" title="{{ $b['block']->titol }}">
                                    <span class="sub">Bloc {{ $b['block']->numero }}</span>
                                    <div @class(['bar', 'done' => $cell['percentatge'] === 100])><span style="width: {{ $cell['percentatge'] }}%"></span></div>
                                    <span class="sub">{{ $cell['assolits'] }}/{{ $cell['total'] }}</span>
                                </div>
                            @endforeach
                            <div class="sub">
                                Darrer assoliment: {{ $row['darrer_assoliment']?->format('d/m/Y H:i') ?? '—' }}
                                · darrera execució: {{ $card['darrera_execucio'] ? \Illuminate\Support\Carbon::parse($card['darrera_execucio'])->format('d/m/Y H:i') : '—' }}
                            </div>
                        @else
                            <div class="sub">Inactiu: no surt al leaderboard.</div>
                        @endif
                    </button>
                @endforeach
            </div>

            @if ($this->module)
                {{-- Filtres --}}
                <div style="display: flex; flex-wrap: wrap; gap: .75rem; align-items: center">
                    <select wire:model.live="bloc" aria-label="Bloc">
                        <option value="">Tots els blocs</option>
                        @foreach ($this->blocks as $b)
                            <option value="{{ $b->id }}">Bloc {{ $b->numero }}: {{ $b->titol }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="tasca" aria-label="Tasca">
                        <option value="">Totes les tasques</option>
                        @foreach ($this->blocks->when($bloc, fn ($c) => $c->where('id', $bloc)) as $b)
                            @foreach ($b->tasks as $t)
                                <option value="{{ $t->id }}">{{ $b->numero }} · {{ $t->titol }}</option>
                            @endforeach
                        @endforeach
                    </select>
                    <select wire:model.live="estat" aria-label="Estat">
                        <option value="">Tots</option>
                        <option value="pendents">Pendents</option>
                        <option value="assolits">Assolits</option>
                        <option value="error">Amb error a la darrera execució</option>
                    </select>
                    <label style="display: flex; gap: .35rem; align-items: center"><input type="checkbox" wire:model.live="obligatoris" /> Només obligatoris</label>
                    <button type="button" class="he-btn" style="margin-left: auto" wire:click="verify" wire:confirm="Verificar ara tots els checks del mòdul per a aquest alumne?">Verificar tot el mòdul</button>
                </div>

                {{-- Taula de checks --}}
                <x-filament::section>
                    @if ($this->progress === [])
                        <div class="sub">Cap check amb aquests filtres.</div>
                    @else
                        <div style="overflow-x: auto">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Check</th>
                                        <th>Estat</th>
                                        <th>Darrera execució</th>
                                        <th>Missatge</th>
                                        <th>Primer assoliment</th>
                                        <th style="text-align: right">Punts</th>
                                        <th>Comentari</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                @foreach ($this->progress as $group)
                                    <tbody>
                                        <tr class="blk"><td colspan="8">Bloc {{ $group['block']->numero }}: {{ $group['block']->titol }}</td></tr>
                                    </tbody>
                                    @foreach ($group['tasks'] as $t)
                                        <tbody>
                                            <tr class="grp">
                                                <td colspan="7">{{ $t['task']->titol }} <span class="sub">· {{ $t['assolits'] }}/{{ $t['total'] }} assolits</span></td>
                                                <td style="text-align: right"><button type="button" class="he-btn" wire:click="verify(null, {{ $t['task']->id }})">Verificar la tasca</button></td>
                                            </tr>
                                        </tbody>
                                        @foreach ($t['rows'] as $r)
                                            @php
                                                $check = $r['check'];
                                                $state = $r['state'];
                                                $last = $r['darrera'];
                                            @endphp
                                            <tbody x-data="{ open: false }" wire:key="chk-{{ $check->id }}">
                                                <tr class="row-main" x-on:click="open = ! open">
                                                    <td>
                                                        <strong>{{ $check->nom }}</strong>
                                                        <div class="sub">{{ $check->tipus->value }} · {{ $check->punts }} punts{{ $r['compta'] ? '' : ' · no compta (no ha començat o desactivat)' }}</div>
                                                    </td>
                                                    <td>
                                                        <span class="chip" style="background: {{ $r['assolit'] ? 'rgb(22,163,74)' : 'rgb(107,114,128)' }}">{{ $r['assolit'] ? 'Assolit' : 'No assolit' }}</span>
                                                        @if ($r['corregit'])
                                                            <div class="sub" title="{{ $state->updated_at?->format('d/m/Y H:i') }}">✎ corregit per {{ $state->teacherUpdatedBy?->nom ?: 'el professor' }}</div>
                                                        @endif
                                                    </td>
                                                    <td style="white-space: nowrap">
                                                        @if ($last)
                                                            <span class="dot" style="background: {{ $statusColor($last->estat) }}"></span>{{ $last->estat->value }}
                                                            <div class="sub">{{ $last->data_execucio?->format('d/m/Y H:i') }} · v{{ $last->checkVersion?->versio ?? '?' }}{{ $last->durada_ms !== null ? ' · '.round($last->durada_ms / 1000, 1).' s' : '' }}</div>
                                                        @else
                                                            <span class="sub">Mai executat</span>
                                                        @endif
                                                    </td>
                                                    <td style="max-width: 22rem">{{ \Illuminate\Support\Str::limit($last?->missatge, 120) }}</td>
                                                    <td style="white-space: nowrap">
                                                        {{ $state?->data_primer_assoliment?->format('d/m/Y H:i') ?? '—' }}
                                                        @if ($r['a_temps'] !== null)
                                                            <div class="sub">{{ $r['a_temps'] ? 'a temps (+'.$check->bonus_puntualitat_pct.'%)' : 'tard' }}</div>
                                                        @endif
                                                    </td>
                                                    <td style="text-align: right"><strong>{{ $fmt($r['punts']) }}</strong></td>
                                                    <td style="max-width: 16rem">{{ $state?->comentari_professor }}</td>
                                                    <td style="text-align: right; white-space: nowrap" x-on:click.stop>
                                                        <button type="button" class="he-btn" wire:click="verify({{ $check->id }})">Verificar</button>
                                                        <button type="button" class="he-btn" wire:click="mountAction('correct', { check: {{ $check->id }} })">Corregir</button>
                                                    </td>
                                                </tr>
                                                <tr x-show="open" x-cloak>
                                                    <td colspan="8" style="background: rgba(128,128,128,.04)">
                                                        <div style="display: grid; gap: 1rem; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr)">
                                                            <div>
                                                                <div class="sub" style="margin-bottom: .3rem">Sortida de la darrera execució</div>
                                                                @if ($r['sortida'])<pre>{{ $r['sortida'] }}</pre>@else<span class="sub">Sense sortida.</span>@endif
                                                            </div>
                                                            <div>
                                                                <div class="sub" style="margin-bottom: .3rem">Canvis d'estat ({{ $r['execucions'] }} execucions)</div>
                                                                @forelse ($r['canvis'] as $c)
                                                                    <div style="margin-bottom: .35rem">
                                                                        <span class="dot" style="background: {{ $statusColor($c['estat']) }}"></span><strong>{{ $c['estat']->value }}</strong>
                                                                        <span class="sub">des del {{ $c['data']?->format('d/m/Y H:i') }}{{ $c['versio'] ? ' · v'.$c['versio'] : '' }}{{ $c['execucions'] > 1 ? ' · '.$c['execucions'].' execucions igual' : '' }}</span>
                                                                        @if ($c['missatge'])<div class="sub" style="padding-left: .9rem">{{ \Illuminate\Support\Str::limit($c['missatge'], 140) }}</div>@endif
                                                                    </div>
                                                                @empty
                                                                    <span class="sub">Encara no s'ha executat.</span>
                                                                @endforelse
                                                                <a class="sub" style="text-decoration: underline" href="{{ \App\Filament\Resources\Checks\CheckResource::getUrl('runs', ['record' => $check->id]) }}">Tot l'historial del check</a>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        @endforeach
                                    @endforeach
                                @endforeach
                            </table>
                        </div>
                    @endif
                </x-filament::section>
            @endif
        @endif
    </div>

</x-filament-panels::page>
