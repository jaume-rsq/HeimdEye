{{-- Caixa reutilitzable de la comanda d'instal·lació de l'agent: pestanyes Servei/Executable,
     camp Hostname i selector d'usuari, amb la comanda muntada en viu (Alpine). La fan servir la
     pàgina «El meu agent» de l'alumne i la fitxa de l'alumne al panell del professor.
     Variables:
       $ctx          array{base,url,token}|null — null si encara no hi ha token
       $initial      string|null                — comanda inicial renderitzada (mode servei, «web»)
       $emptyMessage string                       — què mostrar quan no hi ha token
     Els estils van en línia perquè el tema de Filament no compila classes noves; també serveixen
     les seccions d'ajuda i instàncies de la pàgina de l'alumne. --}}
@php($emptyMessage = $emptyMessage ?? 'Encara no hi ha cap token d\'agent.')
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
    .he-agent-host input, .he-agent-host select { font-size: .8rem; padding: .25rem .5rem; border-radius: .4rem; border: 1px solid rgba(128,128,128,.45); background: rgba(128,128,128,.06); color: inherit; width: 8rem; font-family: ui-monospace, monospace; }
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

@if ($ctx ?? null)
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
            <label class="he-agent-host">
                Usuari
                <select x-model="as" style="width: auto;">
                    <option value="usuari">Usuari actual</option>
                    <option value="root">root</option>
                </select>
            </label>
            <button type="button" class="he-agent-btn" @click="copy()">
                <span x-show="! copied">Copia</span>
                <span x-show="copied" x-cloak>Copiat!</span>
            </button>
        </div>

        <pre class="he-agent-code" x-text="command">{{ $initial ?? '' }}</pre>

        <p class="he-agent-note" x-show="mode === 'servei'">
            <strong>Servei:</strong> instal·la l'agent com a servei de systemd, que s'engega sol i corre
            en segon pla (sota l'usuari que llança el sudo). És el mode normal per deixar la instància monitoritzada.
        </p>
        <p class="he-agent-note" x-show="mode === 'executable'" x-cloak>
            <strong>Executable:</strong> arrenca l'agent de manera interactiva a la terminal actual
            (sense sudo, com l'usuari actual). Va bé per fer proves des d'una WSL; s'atura quan es
            tanca la sessió o es prem Ctrl+C.
        </p>
        <p class="he-agent-note">
            El token identifica l'alumne: no s'ha de compartir. El genera i el revoca el professor;
            si deixa de funcionar, cal generar-ne un de nou.
        </p>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            if (window.Alpine && Alpine.data && !Alpine.__heAgentCmd) {
                Alpine.__heAgentCmd = true;
                Alpine.data('heAgentCmd', (cfg) => ({
                    mode: 'servei',
                    host: 'web',
                    as: 'usuari',
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
                        var tail = ' --url ' + this.url + ' --token ' + this.token + ' --hostname ' + this.hostValue;
                        if (this.mode === 'executable') {
                            // Interactiu: com a root cal sudo; com l'usuari actual, res.
                            return (this.as === 'root' ? 'sudo ' : '') + './heimdeye-agent run' + tail;
                        }
                        // Servei: per defecte corre com l'usuari del sudo; --user root el força a root.
                        return 'sudo ./heimdeye-agent install' + tail + (this.as === 'root' ? ' --user root' : '');
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
            }
        });
    </script>
@else
    <div class="he-agent-note">
        <p>{{ $emptyMessage }}</p>
    </div>
@endif
