{{-- La pàgina «El meu agent» de l'alumne: la seva comanda d'instal·lació (caixa reutilitzable amb
     pestanyes Servei/Executable, hostname i selector d'usuari), una ajuda desplegable i les seves
     instàncies. La caixa i els estils venen del partial compartit amb la fitxa del professor. --}}
<x-filament-panels::page>
    {{-- 1) La comanda d'instal·lació, en primer pla. --}}
    @include('partials.agent-command-box', [
        'ctx' => $this->commandContext,
        'initial' => $this->installCommand,
        'emptyMessage' => "Encara no tens cap token d'agent. Demana al professor que te'n generi un.",
    ])

    {{-- 2) Ajuda desplegable: què és l'agent i els paràmetres que es poden canviar. --}}
    <div x-data="{ open: false }" class="he-agent-sec">
        <button type="button" class="he-agent-btn" @click="open = ! open">
            <span x-show="! open">Què és el meu agent?</span>
            <span x-show="open" x-cloak>Amaga l'ajuda</span>
        </button>

        <div x-show="open" x-cloak class="he-agent-help he-agent-prose">
            <p>
                L'agent és un programa que instal·les a la teva instància (p. ex. una VM d'AWS Learner
                Lab o una WSL de Windows) perquè HeimdEye hi pugui comprovar la teva feina i
                executar-hi accions, encara que la màquina sigui efímera i canviï d'adreça.
            </p>
            <p>
                Funciona «cap enfora»: és l'agent qui pregunta a HeimdEye si té alguna comprovació o
                acció a fer i l'executa a la teva instància; HeimdEye no es connecta mai cap a tu, així
                que no has d'obrir cap port ni donar cap contrasenya.
            </p>
            <p style="font-weight: 600;">Els dos modes (pestanyes sobre la comanda):</p>
            <ul>
                <li><strong>Servei</strong>: s'instal·la com a servei i es torna a engegar sol. És el mode normal.</li>
                <li><strong>Executable</strong>: l'arrenques tu a la terminal (<code>./heimdeye-agent run</code>), sense sudo i com el teu usuari; útil per provar coses des de la WSL.</li>
            </ul>
            <p style="font-weight: 600;">El que pots canviar:</p>
            <ul>
                <li>
                    <strong>Hostname</strong> (camp sobre la comanda, <code>--hostname</code>): el nom que
                    identifica aquesta instància (per defecte <code>web</code>). Si en tens més d'una,
                    dona-li un nom diferent a cadascuna (web, bd, dns…).
                </li>
                <li>
                    <strong>Usuari</strong> (selector sobre la comanda): sota quin usuari corre l'agent.
                    <ul>
                        <li><strong>Usuari actual</strong> (per defecte): corre amb el teu usuari, el del
                        <code>whoami</code>, que és el que té configurat l'AWS CLI en una WSL. És el cas normal.</li>
                        <li><strong>root</strong>: el fa córrer com a root. Cal, per exemple, a una EC2 per
                        <strong>AWS SSM</strong>, on hi entres com a <code>ssm-user</code>: aquest usuari no
                        té les credencials (venen del rol de la instància, accessible des de root).</li>
                    </ul>
                    Al mode Servei això posa <code>--user root</code>; al mode Executable, afegeix un
                    <code>sudo</code> davant.
                </li>
            </ul>
        </div>
    </div>

    {{-- 3) Les instàncies de l'alumne, amb refresc automàtic i botó d'actualitzar. --}}
    <div class="he-agent-sec" wire:poll.15s>
        <div class="he-agent-head">
            <span style="font-size: .8rem; font-weight: 600;">Les instàncies de {{ $this->student?->nom_complet }}</span>
            <button type="button" class="he-agent-btn" wire:click="$refresh">Actualitza</button>
        </div>
        @if ($this->instances->isNotEmpty())
            <table class="he-agent-inst">
                <thead>
                    <tr><th>Instància</th><th>Estat</th><th>IP pública</th><th>Darrer senyal</th></tr>
                </thead>
                <tbody>
                    @foreach ($this->instances as $inst)
                        <tr>
                            <td>{{ $inst->hostname }}</td>
                            <td>
                                <span class="he-agent-dot" style="background: {{ $inst->estat === 'activa' ? '#22c55e' : '#9ca3af' }};"></span>
                                {{ $inst->estat === 'activa' ? 'Activa' : 'Inactiva' }}
                            </td>
                            <td>{{ $inst->ip_publica ?? '—' }}</td>
                            <td>{{ $inst->darrer_batec?->diffForHumans() ?? 'mai' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="he-agent-empty">Encara no has registrat cap instància. Apareixeran aquí quan hi instal·lis i engeguis l'agent.</p>
        @endif
    </div>
</x-filament-panels::page>
