{{-- La pàgina «El meu agent» de l'alumne: la seva comanda d'instal·lació i les seves instàncies.
     Estils en línia: el tema de Filament no compila classes noves. --}}
<x-filament-panels::page>
    <style>
        .he-agent-prose { font-size: .9rem; line-height: 1.6; max-width: 48rem; }
        .he-agent-prose code { background: rgba(128,128,128,.14); padding: .05rem .3rem; border-radius: .3rem; font-family: ui-monospace, monospace; }
        .he-agent-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: .4rem; font-size: .8rem; font-weight: 600; }
        .he-agent-code { background: #0b0f1a; color: #d6deeb; border-radius: .6rem; padding: .9rem 1rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8rem; line-height: 1.55; overflow-x: auto; white-space: pre; margin: 0; }
        .he-agent-copy { padding: .3rem .7rem; border-radius: .5rem; border: 1px solid rgba(128,128,128,.4); background: rgba(128,128,128,.08); font-size: .8rem; cursor: pointer; }
        .he-agent-note { font-size: .82rem; opacity: .8; margin-top: .6rem; max-width: 48rem; }
        .he-agent-inst { width: 100%; border-collapse: collapse; font-size: .85rem; margin-top: .4rem; }
        .he-agent-inst th, .he-agent-inst td { text-align: left; padding: .4rem .6rem; border-bottom: 1px solid rgba(128,128,128,.2); }
        .he-agent-dot { display: inline-block; width: .55rem; height: .55rem; border-radius: 50%; margin-right: .35rem; vertical-align: middle; }
    </style>

    @php($cmd = $this->installCommand)

    <div class="he-agent-prose">
        <p>
            L'agent és un programa que instal·les a la teva instància (p. ex. una VM d'AWS Learner Lab
            o una WSL de Windows) perquè HeimdEye hi pugui comprovar la teva feina i executar-hi
            accions, encara que la màquina sigui efímera i canviï d'adreça.
        </p>
        <p>
            Funciona «cap enfora»: és l'agent qui pregunta a HeimdEye si té alguna comprovació o acció
            a fer i l'executa a la teva instància; HeimdEye no es connecta mai cap a tu, així que no has
            d'obrir cap port ni donar cap contrasenya. S'instal·la com a servei, o sigui que es torna a
            engegar sol. Copia aquesta comanda i enganxa-la a la terminal de la teva instància:
        </p>
    </div>

    @if ($cmd)
        <div
            x-data="{
                copied: false,
                copy() {
                    try {
                        navigator.clipboard.writeText(this.$refs.cmd.textContent);
                        this.copied = true;
                        setTimeout(() => this.copied = false, 1500);
                    } catch (e) {}
                },
            }"
        >
            <div class="he-agent-head">
                <span>Comanda d'instal·lació</span>
                <button type="button" class="he-agent-copy" @click="copy()">
                    <span x-show="! copied">Copia</span>
                    <span x-show="copied" x-cloak>Copiat!</span>
                </button>
            </div>
            <pre x-ref="cmd" class="he-agent-code">{{ $cmd }}</pre>
        </div>

        <div class="he-agent-note">
            <p style="font-weight: 600; opacity: 1;">Els dos paràmetres que pots canviar:</p>
            <ul style="margin: .2rem 0 .6rem 1.1rem; padding: 0;">
                <li>
                    <code>--hostname</code>: el nom que identifica aquesta instància (per defecte
                    <code>web</code>). Posa-hi el que vulguis (web, bd, dns…); si tens més d'una
                    instància, dona-li un nom diferent a cadascuna.
                </li>
                <li>
                    <code>--user</code>: sota quin usuari corre el servei. <strong>Si no el poses</strong>,
                    corre amb l'usuari que llança el <code>sudo</code> (el del <code>whoami</code>), que
                    és el que té configurat l'AWS CLI en una WSL. Casos en què l'has de posar:
                    <ul style="margin: .2rem 0 0 1.1rem;">
                        <li>A una EC2 per <strong>AWS SSM</strong> hi entres com a <code>ssm-user</code>, que no té les credencials (venen del rol de la instància, accessible des de root): posa <code>--user root</code>.</li>
                        <li>Per fer-lo córrer amb un altre usuari del sistema: <code>--user NOM</code>.</li>
                    </ul>
                </li>
            </ul>
            <p>
                El token identifica el teu usuari: no el comparteixis. El genera i el revoca el
                professor; si deixa de funcionar, demana-li que te'n generi un de nou.
            </p>
        </div>
    @else
        <div class="he-agent-note">
            <p>Encara no tens cap token d'agent. Demana al professor que te'n generi un.</p>
        </div>
    @endif

    @if ($this->instances->isNotEmpty())
        <div style="margin-top: 1.5rem;">
            <div class="he-agent-head"><span>Les teves instàncies</span></div>
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
        </div>
    @endif
</x-filament-panels::page>
