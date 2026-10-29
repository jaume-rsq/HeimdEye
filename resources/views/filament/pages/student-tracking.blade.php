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
        $readonly = $this->readonly();
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
        .he-tr .blk { cursor: pointer; }
        .he-tr .blk .agg { display: flex; flex-wrap: wrap; gap: .35rem 1.1rem; align-items: center; font-weight: 400; font-size: .8rem; margin-top: .3rem; }
        .he-tr .blk .agg .bar { width: 7rem; display: inline-block; vertical-align: middle; }
        .he-tr .pos { font-variant-numeric: tabular-nums; white-space: nowrap; opacity: .75; }
        .he-tr tbody.chk td { border-bottom: 0; }
        .he-tr tbody.chk tr.l1 td { border-top: 1px solid rgba(128,128,128,.15); }
        .he-tr tr.l1 td { padding-bottom: .15rem; }
        .he-tr tr.l2 td, .he-tr tr.l3 td { padding-top: 0; padding-bottom: .4rem; font-size: .8rem; }
        .he-tr .run { display: inline-flex; flex-wrap: wrap; gap: .2rem .9rem; align-items: baseline; }
        .he-tr .run > span:not(.msg) { white-space: nowrap; opacity: .85; }
        .he-tr .caret { display: inline-block; width: 1rem; transition: transform .15s; }
    </style>

    <div class="he-tr">
        @if (! $student)
            <x-filament::section>{{ $readonly ? 'El teu usuari no té fitxa d\'alumne: parla amb el professor.' : 'No hi ha cap alumne en els teus mòduls.' }}</x-filament::section>
        @else
            {{-- Capçalera de l'alumne --}}
            <div style="display: flex; flex-wrap: wrap; gap: .75rem; align-items: center">
                @unless ($readonly)
                <button type="button" class="he-btn" wire:click="go(-1)" @disabled(! $this->neighbour(-1)) title="Alumne anterior">←</button>
                <select wire:model.live="alumne" aria-label="Alumne" style="min-width: 16rem">
                    @foreach ($this->students as $s)
                        <option value="{{ $s->id }}">{{ sprintf('%02d', $s->num_taula) }} · {{ $s->nom_complet }}{{ $s->es_actiu ? '' : ' (inactiu)' }}</option>
                    @endforeach
                </select>
                <button type="button" class="he-btn" wire:click="go(1)" @disabled(! $this->neighbour(1)) title="Alumne següent">→</button>
                @endunless
                <div>
                    <strong style="font-size: 1.05rem">{{ $student->nom_complet }}</strong>
                    <span class="sub">· taula {{ sprintf('%02d', $student->num_taula) }} · {{ $student->usuari }}{{ $student->alies ? ' · àlies «'.$student->alies.'»' : '' }}{{ $student->es_actiu ? '' : ' · inactiu' }}</span>
                </div>
                @unless ($readonly)
                    <a class="he-btn" style="margin-left: auto" href="{{ \App\Filament\Resources\Students\StudentResource::getUrl('edit', ['record' => $student->id]) }}">Fitxa</a>
                @endunless
            </div>

            {{-- Una targeta per mòdul --}}
            @if ($this->cards === [])
                <x-filament::section>{{ $readonly ? 'Encara no estàs matriculat en cap mòdul.' : 'Aquest alumne no està matriculat en cap dels teus mòduls.' }}</x-filament::section>
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
                    @if ($pending)
                        <span class="sub" style="margin-left: auto" wire:poll.5s="refreshPending">⏳ Verificant… la pàgina es posa al dia sola</span>
                    @endif
                    @unless ($readonly)
                    <button type="button" class="he-btn" @style(['margin-left: auto' => ! $pending]) wire:click="verify" wire:confirm="Verificar ara tots els checks del mòdul per a aquest alumne?">Verificar tot el mòdul</button>
                    @endunless
                </div>

                {{-- Taula de checks: cada bloc es plega clicant-ne la línia --}}
                @php $blockIds = collect($this->progress)->map(fn ($g) => $g['block']->id)->values(); @endphp
                <div x-data="{ closed: {}, all(v) { {{ $blockIds->toJson() }}.forEach(id => this.closed[id] = v) } }" wire:key="taula-{{ $modul }}">
                <div style="display: flex; gap: .5rem; justify-content: flex-end; margin-bottom: .5rem">
                    <button type="button" class="he-btn" x-on:click="all(true)">Plegar tots els blocs</button>
                    <button type="button" class="he-btn" x-on:click="all(false)">Desplegar-los</button>
                </div>
                <x-filament::section>
                    @if ($this->progress === [])
                        <div class="sub">Cap check amb aquests filtres.</div>
                    @else
                        <div style="overflow-x: auto">
                            <table>
                                <thead>
                                    <tr>
                                        <th title="Bloc.tasca.check: l'ordre en què s'han d'anar assolint">Pos.</th>
                                        <th>Check</th>
                                        <th>Estat</th>
                                        <th>Primer assoliment</th>
                                        <th style="text-align: right">Punts</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                @foreach ($this->progress as $group)
                                    @php
                                        $blockId = $group['block']->id;
                                        $sum = $group['resum'];
                                    @endphp
                                    <tbody>
                                        <tr class="blk" x-on:click="closed[{{ $blockId }}] = ! closed[{{ $blockId }}]">
                                            <td colspan="6">
                                                <span class="caret" x-bind:style="closed[{{ $blockId }}] ? 'transform: rotate(-90deg)' : ''">▾</span>
                                                Bloc {{ $group['block']->numero }}: {{ $group['block']->titol }}
                                                <div class="agg" style="padding-left: 1rem">
                                                    <span><strong>{{ $sum['assolits'] }}/{{ $sum['total'] }}</strong> checks assolits</span>
                                                    <span>
                                                        <span @class(['bar', 'done' => $sum['percentatge'] === 100])><span style="width: {{ $sum['percentatge'] }}%"></span></span>
                                                        obligatoris {{ $sum['obligatoris'] }}/{{ $sum['obligatoris_total'] }} · {{ $sum['percentatge'] }}%
                                                    </span>
                                                    <span><strong>{{ $fmt($sum['punts']) }}</strong> / {{ $fmt($sum['punts_maxims']) }} punts</span>
                                                    @if ($sum['errors'])
                                                        <span style="color: rgb(217,119,6)">{{ $sum['errors'] }} amb error</span>
                                                    @endif
                                                    <span class="sub">darrer assoliment: {{ $sum['darrer_assoliment']?->format('d/m/Y H:i') ?? '—' }}</span>
                                                    @if ($group['block']->data_inici || $group['block']->data_fi)
                                                        <span class="sub">{{ $group['block']->data_inici?->format('d/m') ?? '…' }} – {{ $group['block']->data_fi?->format('d/m/Y') ?? '…' }}</span>
                                                    @endif
                                                    @unless ($readonly)
                                                        <button type="button" class="he-btn" x-on:click.stop wire:click="verify(null, null, {{ $blockId }})">Verificar el bloc</button>
                                                    @endunless
                                                    @if ($this->canEditModule())
                                                        <button type="button" class="he-btn" x-on:click.stop wire:click="mountAction('extend', { block: {{ $blockId }} })" title="Dates noves per a tots els checks del bloc, per a tota la classe">Prorrogar</button>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                    @foreach ($group['tasks'] as $t)
                                        <tbody x-show="! closed[{{ $blockId }}]">
                                            <tr class="grp">
                                                <td class="pos">{{ $group['block']->numero }}.{{ $t['task']->posicio }}</td>
                                                <td colspan="4">{{ $t['task']->titol }} <span class="sub">· {{ $t['assolits'] }}/{{ $t['total'] }} assolits</span></td>
                                                <td style="text-align: right">
                                                    @unless ($readonly)
                                                        <button type="button" class="he-btn" wire:click="verify(null, {{ $t['task']->id }})">Verificar la tasca</button>
                                                    @endunless
                                                </td>
                                            </tr>
                                        </tbody>
                                        @foreach ($t['rows'] as $r)
                                            @php
                                                $check = $r['check'];
                                                $state = $r['state'];
                                                $last = $r['darrera'];
                                            @endphp
                                            <tbody class="chk" x-data="{ open: false }" x-show="! closed[{{ $blockId }}]" wire:key="chk-{{ $check->id }}">
                                                {{-- Línia 1: el check i el seu estat per a l'alumne --}}
                                                <tr class="row-main l1" x-on:click="open = ! open">
                                                    <td class="pos">{{ $check->positionLabel() }}</td>
                                                    <td style="min-width: 16rem">
                                                        <strong>{{ $check->nom }}</strong>
                                                        <span class="sub" style="white-space: nowrap">· {{ $check->tipus->value }} · {{ $check->punts }} {{ $check->punts == 1 ? 'punt' : 'punts' }}{{ $r['compta'] ? '' : ' · no compta encara' }}</span>
                                                    </td>
                                                    <td style="white-space: nowrap">
                                                        <span class="chip" style="background: {{ $r['assolit'] ? 'rgb(22,163,74)' : 'rgb(107,114,128)' }}">{{ $r['assolit'] ? 'Assolit' : 'No assolit' }}</span>
                                                        @if ($r['corregit'])
                                                            <span class="sub" title="{{ $state->data_correccio?->format('d/m/Y H:i') }}">✎ {{ $state->teacherUpdatedBy?->nom ?: 'professor' }}</span>
                                                        @endif
                                                    </td>
                                                    <td style="white-space: nowrap">
                                                        {{ $state?->data_primer_assoliment?->format('d/m/Y H:i') ?? '—' }}
                                                        @if ($r['a_temps'] !== null)
                                                            <span class="sub">· {{ $r['a_temps'] ? 'a temps (+'.$check->bonus_puntualitat_pct.'%)' : 'tard' }}</span>
                                                        @endif
                                                    </td>
                                                    <td style="text-align: right"><strong>{{ $fmt($r['punts']) }}</strong></td>
                                                    <td style="text-align: right; white-space: nowrap" x-on:click.stop>
                                                        <span style="display: inline-flex; gap: .15rem; align-items: center; vertical-align: middle">
                                                            <x-filament::icon-button icon="heroicon-o-information-circle" color="gray" size="sm" label="Info" tooltip="Info: objectiu, justificació i codi amb les dades de l'alumne" wire:click="mountAction('info', { check: {{ $check->id }} })" />
                                                            @if ($this->canVerify($check))
                                                                @php($wait = $this->verifyWaitMinutes($check))
                                                                @if ($wait)
                                                                    <x-filament::icon-button icon="heroicon-o-play" color="gray" size="sm" label="Verificar" :tooltip="'Podràs tornar a verificar-lo d\'aquí a '.$wait.' min'" disabled />
                                                                @elseif ($readonly)
                                                                    <x-filament::icon-button icon="heroicon-o-play" color="primary" size="sm" label="Verificar" tooltip="Verificar ara (un cop cada 10 minuts)" wire:click="verify({{ $check->id }})" wire:confirm="Abans de verificar, comprova tu mateix que funciona: només ho podràs tornar a provar d'aquí a 10 minuts. Verificar ara?" />
                                                                @else
                                                                    <x-filament::icon-button icon="heroicon-o-play" color="primary" size="sm" label="Verificar" tooltip="Verificar ara per a aquest alumne" wire:click="verify({{ $check->id }})" />
                                                                @endif
                                                            @endif
                                                        </span>
                                                        @unless ($readonly)
                                                            <button type="button" class="he-btn" wire:click="mountAction('correct', { check: {{ $check->id }} })">Corregir</button>
                                                        @endunless
                                                        @if ($this->canEdit($check))
                                                            <a class="he-btn" href="{{ \App\Filament\Resources\Checks\CheckResource::getUrl('edit', ['record' => $check->id]) }}" title="Editar el check">✎</a>
                                                        @endif
                                                    </td>
                                                </tr>
                                                {{-- Línia 2: la darrera execució --}}
                                                <tr class="row-main l2" x-on:click="open = ! open">
                                                    <td></td>
                                                    <td colspan="5">
                                                        @if ($last)
                                                            <span class="run">
                                                                <span><span class="dot" style="background: {{ $statusColor($last->estat) }}"></span><strong>{{ $last->estat->value }}</strong></span>
                                                                <span>{{ $last->data_execucio?->format('d/m/Y H:i') }}</span>
                                                                <span>v{{ $last->checkVersion?->versio ?? '?' }}</span>
                                                                @if ($last->durada_ms !== null)<span>{{ round($last->durada_ms / 1000, 1) }} s</span>@endif
                                                                @if (filled($last->missatge))
                                                                    <span class="msg" style="color: {{ $last->estat === \App\Enums\CheckStatus::Pass ? 'inherit' : $statusColor($last->estat) }}">{{ \Illuminate\Support\Str::limit($last->missatge, 200) }}</span>
                                                                @endif
                                                            </span>
                                                        @elseif (! $r['omesa'])
                                                            <span class="sub">Mai executat</span>
                                                        @endif
                                                        @if ($r['omesa'])
                                                            <span class="run" style="opacity: .75">
                                                                <span><span class="dot" style="background: {{ $statusColor($r['omesa']->estat) }}"></span><strong>omès</strong></span>
                                                                <span>{{ $r['omesa']->data_execucio?->format('d/m/Y H:i') }}</span>
                                                                @if (filled($r['omesa']->missatge))<span class="msg">{{ \Illuminate\Support\Str::limit($r['omesa']->missatge, 200) }}</span>@endif
                                                            </span>
                                                        @endif
                                                    </td>
                                                </tr>
                                                {{-- Línia 3: el comentari del professor, si n'hi ha --}}
                                                @if (filled($state?->comentari_professor))
                                                    <tr class="l3">
                                                        <td></td>
                                                        <td colspan="5"><span class="sub">Comentari:</span> {{ $state->comentari_professor }}</td>
                                                    </tr>
                                                @endif
                                                <tr x-show="open" x-cloak>
                                                    <td colspan="6" style="background: rgba(128,128,128,.04)">
                                                        <div style="display: grid; gap: 1rem; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr)">
                                                            <div>
                                                                <div class="sub" style="margin-bottom: .3rem">Sortida de la darrera execució</div>
                                                                @if ($r['sortida'])<pre>{{ $r['sortida'] }}</pre>@else<span class="sub">Sense sortida.</span>@endif
                                                            </div>
                                                            <div>
                                                                <div class="sub" style="margin-bottom: .3rem">Canvis d'estat ({{ $r['execucions'] }} execucions)</div>
                                                                @forelse ($r['canvis'] as $c)
                                                                    <div style="margin-bottom: .35rem">
                                                                        @if (isset($c['professor']))
                                                                            <span class="dot" style="background: {{ $c['estat'] ? $statusColor($c['estat']) : 'rgb(107,114,128)' }}"></span><strong>✎ {{ match ($c['estat']) { \App\Enums\CheckStatus::Pass => 'assolit', \App\Enums\CheckStatus::Fail => 'no assolit', default => 'automàtic' } }}</strong>
                                                                            <span class="sub">correcció de {{ $c['professor'] }} el {{ $c['data']?->format('d/m/Y H:i') }}</span>
                                                                        @else
                                                                        <span class="dot" style="background: {{ $statusColor($c['estat']) }}"></span><strong>{{ $c['estat']->value }}</strong>
                                                                        <span class="sub">des del {{ $c['data']?->format('d/m/Y H:i') }}{{ $c['versio'] ? ' · v'.$c['versio'] : '' }}{{ $c['execucions'] > 1 ? ' · '.$c['execucions'].' execucions igual' : '' }}</span>
                                                                        @endif
                                                                        @if ($c['missatge'])<div class="sub" style="padding-left: .9rem">{{ \Illuminate\Support\Str::limit($c['missatge'], 140) }}</div>@endif
                                                                    </div>
                                                                @empty
                                                                    <span class="sub">Encara no s'ha executat.</span>
                                                                @endforelse
                                                                @unless ($readonly)
                                                                    <a class="sub" style="text-decoration: underline" href="{{ \App\Filament\Resources\Checks\CheckResource::getUrl('runs', ['record' => $check->id]) }}">Tot l'historial del check</a>
                                                                @endunless
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
                </div>
            @endif
        @endif
    </div>

</x-filament-panels::page>
