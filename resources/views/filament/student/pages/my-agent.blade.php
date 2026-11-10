{{-- La pàgina «El meu agent» de l'alumne: la seva comanda d'instal·lació (amb pestanyes Servei/
     Executable i hostname editables), les seves instàncies i una ajuda desplegable.
     Estils en línia: el tema de Filament no compila classes noves. --}}
<x-filament-panels::page>
    <style>
        .he-agent-prose { font-size: .9rem; line-height: 1.6; }
        .he-agent-prose code { background: rgba(128,128,128,.14); padding: .05rem .3rem; border-radius: .3rem; font-family: ui-monospace, monospace; }
        .he-agent-prose ul { margin: .3rem 0 .6rem 1.1rem; padding: 0; }
        .he-agent-head { display: flex; justify-content: space-between; align-items: center; gap: .5rem; margin-bottom: .4rem; flex-wrap: wrap; }
        .he-agent-tabs { display: inline-flex; border: 1px solid rgba(128,128,128,.4); border-radius: .5rem; overflow: hidden; }
        .he-agent-tab { padding: .3rem .8rem; font-size: .8rem; font-weight: 600; cursor: pointer; background: transparent; border: 0; border-right: 1px solid rgba(128,128,128,.4); color: inherit; }
        .he-agent-tab:last-child { border-right: 0; }
        .he-agent-tab-on { background: #2563eb; color: #fff; }
        .he-agent-host { display: flex; align-items: center; gap: .4rem; font-size: .8rem; font-weight: 600; }
        .he-agent-host input { font-size: .8rem; padding: .25rem .5rem; border-radius: .4rem; border: 1px solid rgba(128,128,128,.45); background: rgba(128,128,128,.06); color: inherit; width: 8rem; font-family: ui-monospace, monospace; }
        .he-agent-code { background: #0b0f1a; color: #d6deeb; border-radius: .6rem; padding: .9rem 1rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8rem; line-height: 1.55; overflow-x: auto; white-space: pre; margin: 0; }
        .he-agent-btn { padding: .3rem .7rem; border-radius: .5rem; border: 1px solid rgba(128,128,128,.4); background: rgba(128,128,128,.08); font-size: .8rem; cursor: pointer; color: inherit; }
        .he-agent-help { border: 1px solid rgba(128,128,128,.25); border-radius: .6rem; padding: .9rem 1rem; margin-top: .6rem; background: rgba(128,128,128,.04); }
        .he-agent-note { font-size: .82rem; opacity: .85; margin-top: .6rem; }
        .he-agent-inst { width: 100%; border-collapse: collapse; font-size: .85rem; margin-top: .4rem; }
        .he-agent-inst th, .he-agent-inst td { text-align: left; padding: .4rem .6rem; border-bottom: 1px solid rgba(128,128,128,.2); }
        .he-agent-dot { display: inline-block; width: .55rem; height: .55rem; border-radius: 50%; margin-right: .35rem; vertical-align: middle; }
        .he-agent-empty { font-size: .85rem; opacity: .7; margin-top: .4rem; }
        .he-agent-sec { margin-top: 1.5rem; }
    </style>

    @php($ctx = $this->commandContext)

    {{-- 1) La comanda d'instal·lació, en primer pla: pestanyes de mode, hostname i comanda copiable. --}}
    @if ($ctx)
        <div x-data="heAgentCmd(@js($ctx))">
            <div class="he-agent-head">
                <div class="he-agent-tabs" role="tablist">
                    <button type="button" class="he-agent-tab" :class="mode === 'servei' && 'he-agent-tab-on'" @click="mode = 'servei'">Servei</button>
                    <button type="button" class="he-agent-tab" :class="mode === 'executable' && 'he-agent-tab-on'" @click="mode = 'executable'">Executable</button>
                </div>
                <label class="he-agent-host">
                    Hostname
                    <input type="text" x-model="host" placeholder="web" spellcheck="false" autocapitalize="off" autocomplete="off">
                </label>
                <button type="button" class="he-agent-btn" @click="copy()">
                    <span x-show="! copied">Copia</span>
                    <span x-show="copied" x-cloak>Copiat!</span>
                </button>
            </div>

            <pre class="he-agent-code" x-text="command">{{ $this->installCommand }}</pre>

            <p class="he-agent-note" x-show="mode === 'servei'">
                <strong>Servei:</strong> instal·la l'agent com a servei de systemd, que s'engega sol i corre
                en segon pla (sota el teu usuari). És el mode normal per deixar la instància monitoritzada.
            </p>
            <p class="he-agent-note" x-show="mode === 'executable'" x-cloak>
                <strong>Executable:</strong> arrenca l'agent de manera interactiva a la terminal actual
                (sense sudo, com el teu usuari). Va bé per fer proves des de la teva WSL; s'atura quan
                tanques la sessió o prems Ctrl+C.
            </p>
            <p class="he-agent-note">
                El token identifica el teu usuari: no el comparteixis. El genera i el revoca el professor;
                si deixa de funcionar, demana-li que te'n generi un de nou.
            </p>
        </div>

        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('heAgentCmd', (cfg) => ({
                    mode: 'servei',
                    host: 'web',
                    copied: false,
                    base: cfg.base,
                    url: cfg.url,
                    token: cfg.token,
                    get hostValue() {
                        return (this.host || '').trim() || 'web';
                    },
                    get download() {
                        return 'ARCH=$(uname -m); case "$ARCH" in x86_64) A=amd64;; aarch64|arm64) A=arm64;; *) echo "arquitectura no suportada: $ARCH"; exit 1;; esac\n'
                            + 'curl -fsSL -o heimdeye-agent ' + this.base + '/heimdeye-agent-linux-$A\n'
                            + 'chmod +x heimdeye-agent';
                    },
                    get action() {
                        if (this.mode === 'executable') {
                            return './heimdeye-agent run --url ' + this.url + ' --token ' + this.token + ' --hostname ' + this.hostValue;
                        }
                        return 'sudo ./heimdeye-agent install --url ' + this.url + ' --token ' + this.token + ' --hostname ' + this.hostValue;
                    },
                    get command() {
                        return this.download + '\n' + this.action;
                    },
                    copy() {
                        try {
                            navigator.clipboard.writeText(this.command);
                            this.copied = true;
                            setTimeout(() => this.copied = false, 1500);
                        } catch (e) {}
                    },
                }));
            });
        </script>
    @else
        <div class="he-agent-note">
            <p>Encara no tens cap token d'agent. Demana al professor que te'n generi un.</p>
        </div>
    @endif

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
                    <code>--user</code> (només al mode <strong>Servei</strong>): sota quin usuari corre el
                    servei. <strong>Si no el poses</strong>, corre amb l'usuari que llança el
                    <code>sudo</code> (el del <code>whoami</code>), que és el que té configurat l'AWS CLI
                    en una WSL. L'has d'afegir a mà en aquests casos:
                    <ul>
                        <li>A una EC2 per <strong>AWS SSM</strong> hi entres com a <code>ssm-user</code>, que no té les credencials (venen del rol de la instància, accessible des de root): afegeix <code>--user root</code>.</li>
                        <li>Per fer-lo córrer amb un altre usuari del sistema: <code>--user NOM</code>.</li>
                    </ul>
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
