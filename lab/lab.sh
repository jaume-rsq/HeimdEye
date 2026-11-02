#!/usr/bin/env bash
# Demos de HeimdEye en contenidors LXC (Incus), tot dins una sola VM. Vegeu docs/demo.md i
# docs/demo-smix2.md.
#
#   sudo lab/lab.sh crea        # prepara Incus, els equips, HeimdEye i l'estat inicial
#   sudo lab/lab.sh restaura    # torna els equips dels alumnes a l'estat inicial
#   sudo lab/lab.sh estat       # llista dels contenidors
#   sudo lab/lab.sh actualitza  # HeimdEye amb el darrer commit d'aquesta carpeta (cal Internet)
#   sudo lab/lab.sh esborra     # esborra els contenidors i les xarxes (no les imatges)
#   sudo lab/lab.sh clon        # secrets nous i BBDD neta (ho fa sol el servei heimdeye-lab-clon)
#
# Modes (HEIMDEYE_LAB_MODE, en crear; després, el que es va crear):
#   plantilla  (per defecte) un sol professor: s'hi entra sense contrasenya des de la mateixa VM
#              (http://localhost:8080), amb el tutorial i el laboratori de xarxa SMIX2. Pensat per
#              clonar-lo: el servei heimdeye-lab-clon detecta el clon i hi posa secrets nous sol.
#   publica    demo oberta a tothom (HEIMDEYE_DEMO): el tutorial, i el codi dels checks s'executa al
#              contenidor "sandbox", no al de HeimdEye. HEIMDEYE_LAB_URL: l'adreça pública.
#
# Alumnes del laboratori SMIX2: HEIMDEYE_LAB_ALUMNES (1 a 4, per defecte 2). Crear-ho tot necessita
# Internet (paquets, imatges); en acabar, cap contenidor en té.
#
# Pla d'adreces (X = 20 + número d'alumne), el mateix que database/seeders/Smix2LabSeeder.php:
#   gestió  he-gestio 10.255.0.0/16  amfitrió .0.1, HeimdEye .0.2, sandbox .0.3,
#                                    tutorial alumne0N .1.N, router .X.1, client .X.10, DMZ .X.20
#   WAN     he-wan    203.0.113.0/24 amfitrió .1, HeimdEye .2, router .X
#   LAN     he-lanX   10.X.1.0/24    (sense adreça a l'amfitrió: la configura l'alumne)
#   DMZ     he-dmzX   10.X.2.0/24
set -Eeuo pipefail
# Si una ordre falla, que es vegi quina (si no, set -e surt sense dir res).
trap 'echo "ERROR: ha fallat (línia $LINENO): $BASH_COMMAND" >&2' ERR

REPO=$(cd "$(dirname "$0")/.." && pwd)
STATE=/var/lib/heimdeye-lab
N=${HEIMDEYE_LAB_ALUMNES:-$(cat "$STATE/alumnes" 2>/dev/null || echo 2)}
MODE=${HEIMDEYE_LAB_MODE:-$(cat "$STATE/mode" 2>/dev/null || echo plantilla)}
UBUNTU=images:ubuntu/24.04      # equips dels alumnes
UBUNTU_APP=images:ubuntu/26.04  # HeimdEye i el sandbox, com a producció
IMAGE=heimdeye-lab-base
HE=heimdeye
SANDBOX=sandbox
SRC=/opt/heimdeye-src           # còpia del repositori dins el contenidor de HeimdEye
CURRENT=/var/www/heimdeye/current
KEY=/var/lib/heimdeye/lab/id_ed25519  # clau dels equips, al contenidor que executa el codi
ROLES=(router client dmz)

log() { echo "== $*"; }
die() { echo "ERROR: $*" >&2; exit 1; }
exists() { incus info "$1" >/dev/null 2>&1; }
ready() { exists "$1" && [[ $(incus config get "$1" user.lab.llest) == 1 ]]; }
# Incus pot donar errors transitoris amb el disc lent ("context deadline exceeded"): 3 intents.
retry() {
    local i
    for i in 1 2 3; do
        "$@" && return 0
        echo "   Ha fallat (intent $i de 3), es torna a provar..."
        sleep 10
    done
    return 1
}
# Crea un contenidor sense xarxa (les targetes s'hi afegeixen després); si un intent anterior l'ha
# deixat a mig fer, primer l'esborra.
new_instance() {
    local image=$1 name=$2
    shift 2
    local out
    exists "$name" && incus delete --force "$name"
    out=$(incus init "$image" "$name" -p he-lab "$@" 2>&1) || { echo "$out" >&2; return 1; }
}
# Espera que systemd hagi acabat d'arrencar dins el contenidor (si no, les ordres hi fallen amb
# "Failed to connect to bus").
wait_boot() {
    local state
    for _ in $(seq 60); do
        state=$(incus exec "$1" -- systemctl is-system-running --wait 2>/dev/null) || true
        [[ $state == running || $state == degraded ]] && return 0
        sleep 2
    done
    die "$1 no acaba d'arrencar"
}
# Ordres dins el contenidor de HeimdEye: com a root, i artisan com a usuari heimdeye.
he() { incus exec "$HE" --cwd / -- "$@"; }
artisan() { incus exec "$HE" --cwd "$CURRENT" -- sudo -u heimdeye -H php artisan "$@"; }
nic() { incus config device add "$1" "$2" nic network="$3" name="$4" "${@:5}" >/dev/null; }
# Adreces fixes d'un contenidor encara aturat: un fitxer de netplan que s'aplica en arrencar.
# Res d'aplicar-lo amb el contenidor engegat: "netplan apply" i "networkctl reload" hi poden fallar
# (udevadm, systemd-networkd encara no a punt).
addresses() {
    local name=$1 file=$2 tmp
    shift 2
    tmp=$(mktemp)
    {
        printf 'network:\n  version: 2\n  ethernets:\n'
        while (($#)); do printf '    %s:\n      addresses: [%s]\n' "$1" "$2"; shift 2; done
    } > "$tmp"
    incus file push --mode 0600 "$tmp" "$name/etc/netplan/$file"
    rm -f "$tmp"
}
lab_instances() {
    [[ $MODE == plantilla ]] || return 0
    local n
    for ((n = 1; n <= N; n++)); do
        for role in "${ROLES[@]}"; do echo "$role$((20 + n))"; done
    done
}
tutorial_instances() { echo alumne01 alumne02 alumne03 alumne04; }
# Els contenidors d'usar i llençar, amb instantània "inicial".
student_instances() {
    lab_instances
    tutorial_instances
    [[ $MODE == publica ]] && echo "$SANDBOX"
    return 0
}

[[ $EUID -eq 0 ]] || die "cal executar-ho amb sudo"
[[ $N =~ ^[1-4]$ ]] || die "HEIMDEYE_LAB_ALUMNES ha de ser de 1 a 4"
[[ $MODE == plantilla || $MODE == publica ]] || die "HEIMDEYE_LAB_MODE ha de ser plantilla o publica"

# Espera que el contenidor tingui xarxa cap a Internet (per instal·lar paquets).
wait_internet() {
    for _ in $(seq 60); do
        incus exec "$1" -- getent hosts archive.ubuntu.com >/dev/null 2>&1 && return
        sleep 2
    done
    die "$1 no té Internet"
}

# Internet per a un contenidor (per instal·lar), per la xarxa per defecte d'Incus (eth0, DHCP de la
# imatge). La targeta s'afegeix amb el contenidor aturat (si està engegat, es reinicia): així la
# xarxa es configura en arrencar, sense haver d'aplicar res.
internet() {
    local name=$1
    if [[ $2 == on ]]; then
        local running=false
        incus info "$name" | grep -q '^Status: RUNNING' && running=true
        if ! incus config device show "$name" | grep -q '^inet:'; then
            nic "$name" inet incusbr0 eth0
            if $running; then
                incus restart "$name"
                wait_boot "$name"
            fi
        fi
        if $running; then wait_internet "$name"; fi
    else
        incus config device show "$name" | grep -q '^inet:' && incus config device remove "$name" inet >/dev/null
        return 0
    fi
}

prepare_incus() {
    if ! command -v incus >/dev/null; then
        log "Instal·lant Incus"
        apt-get update -q
        apt-get install -y -q incus
    fi
    if ! incus storage show default >/dev/null 2>&1; then
        log "Inicialitzant Incus"
        incus admin init --minimal
    fi
    # Amb una desena de contenidors, els límits d'inotify per defecte (128) s'acaben i systemd ja no
    # pot engegar serveis als contenidors nous (p. ex. systemd-networkd). Valors de la guia de
    # producció d'Incus.
    printf '%s\n' 'fs.inotify.max_user_instances = 1024' 'fs.inotify.max_user_watches = 1048576' \
        'fs.inotify.max_queued_events = 1048576' > /etc/sysctl.d/60-heimdeye-lab.conf
    sysctl -q -p /etc/sysctl.d/60-heimdeye-lab.conf
    incus profile show he-lab >/dev/null 2>&1 || {
        incus profile create he-lab
        incus profile device add he-lab root disk path=/ pool=default
    }
}

# Xarxa gestionada per Incus sense NAT ni DHCP (adreça "none": l'amfitrió no hi és).
network() {
    local name=$1 address=$2
    incus network show "$name" >/dev/null 2>&1 && return
    if [[ $address == none ]]; then
        incus network create "$name" ipv4.address=none ipv6.address=none
    else
        incus network create "$name" ipv4.address="$address" ipv4.nat=false ipv4.dhcp=false ipv6.address=none
    fi
}

# Imatge comuna dels equips dels alumnes: els paquets de tots els blocs, aturats, i l'usuari heimdeye.
base_image() {
    incus image info "$IMAGE" >/dev/null 2>&1 && return
    log "Imatge base dels equips (paquets dels serveis, aturats)"
    exists he-base || incus launch "$UBUNTU" he-base
    wait_internet he-base
    incus exec he-base -- bash -euc '
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -q
        apt-get install -y -q --no-install-recommends openssh-server sudo python3 nftables \
            kea-dhcp4-server bind9 bind9-dnsutils nginx squid \
            curl iputils-ping netcat-openbsd iproute2 ca-certificates nano less
        systemctl disable --now nftables kea-dhcp4-server named nginx squid 2>/dev/null || true
        id heimdeye >/dev/null 2>&1 || useradd --create-home --shell /bin/bash heimdeye
        echo "heimdeye ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/heimdeye
        install -d -o heimdeye -g heimdeye -m 700 /home/heimdeye/.ssh
        printf "PasswordAuthentication no\nPermitRootLogin no\n" > /etc/ssh/sshd_config.d/heimdeye.conf
        # Sense DHCP a eth0: lab.sh hi posa l adreça de gestió.
        rm -f /etc/netplan/*.yaml
        apt-get clean'
    incus stop he-base
    incus publish he-base --alias "$IMAGE"
    incus delete he-base
}

# Clau amb què HeimdEye entra als equips: nova a cada instal·lació (i a cada clon de la plantilla).
lab_key() {
    install -d -m 700 "$STATE"
    [[ -s $STATE/id_ed25519 ]] || ssh-keygen -q -t ed25519 -N '' -C heimdeye-lab -f "$STATE/id_ed25519"
}

# Un fitxer de l'amfitrió (stdin) dins un contenidor, amb propietari i mode 600.
put_file() {
    local name=$1 path=$2 owner=$3
    incus exec "$name" -- sh -c "install -d -o $owner -g $owner -m 700 \"\$(dirname $path)\" && umask 077 && cat > $path && chown $owner: $path"
}

# Laboratori SMIX2: router, client i servidor de la DMZ de l'alumne $1.
student_lab() {
    local x=$((20 + $1))
    network "he-lan$x" none
    network "he-dmz$x" none
    for role in "${ROLES[@]}"; do
        local name=$role$x host
        ready "$name" && continue
        log "Equip $name"
        retry new_instance "$IMAGE" "$name" -c limits.memory=256MiB -c limits.cpu=1 -c boot.autostart=true
        nic "$name" eth0 he-gestio eth0
        case $role in
            router)
                host=1
                nic "$name" eth1 he-wan eth1
                nic "$name" eth2 "he-lan$x" eth2
                nic "$name" eth3 "he-dmz$x" eth3
                ;;
            client)
                host=10
                # La MAC de la reserva de DHCP (variable mac_client del seeder).
                nic "$name" eth1 "he-lan$x" eth1 hwaddr="00:16:3e:00:01:$x"
                ;;
            dmz)
                host=20
                nic "$name" eth1 "he-dmz$x" eth1
                ;;
        esac
        addresses "$name" 50-gestio.yaml eth0 "10.255.$x.$host/16"
        retry incus start "$name"
        wait_boot "$name"
        incus config set "$name" user.lab.llest=1
    done
}

# Servidors dels alumnes del tutorial (alumne01..04): SSH amb l'usuari alumne (sudo) i nginx aturat.
tutorial_servers() {
    local i name
    for i in 1 2 3 4; do
        name=alumne0$i
        ready "$name" && continue
        log "Servidor del tutorial $name"
        retry new_instance "$IMAGE" "$name" -c limits.memory=256MiB -c limits.cpu=1 -c boot.autostart=true
        nic "$name" eth0 he-gestio eth0
        addresses "$name" 50-gestio.yaml eth0 "10.255.1.$i/16"
        retry incus start "$name"
        wait_boot "$name"
        incus exec "$name" -- sh -euc '
            id alumne >/dev/null 2>&1 || useradd --create-home --shell /bin/bash alumne
            echo "alumne ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/alumne'
        incus config set "$name" user.lab.llest=1
    done
}

# Noms dels servidors del tutorial (ip_servidor = alumne{{num_taula}}) i del sandbox.
hosts_file() {
    incus exec "$1" -- sh -c 'sed -i "/# heimdeye-lab$/d" /etc/hosts && cat >> /etc/hosts' <<'EOF'
10.255.1.1 alumne01 # heimdeye-lab
10.255.1.2 alumne02 # heimdeye-lab
10.255.1.3 alumne03 # heimdeye-lab
10.255.1.4 alumne04 # heimdeye-lab
10.255.0.3 sandbox # heimdeye-lab
EOF
}

# ping sense privilegis (sockets ICMP) per als checks locals, també dins els workers (NoNewPrivileges).
unprivileged_ping() {
    incus exec "$1" -- sh -c 'echo "net.ipv4.ping_group_range = 0 2147483647" > /etc/sysctl.d/60-heimdeye-ping.conf
        sysctl -q -p /etc/sysctl.d/60-heimdeye-ping.conf || true'
}

# Demo pública: on s'executa el codi dels checks i les accions, en lloc del contenidor de HeimdEye
# (que té el .env i la BBDD). Usuari heimdeye sense sudo, SSH sense reenviaments ni terminal.
sandbox_container() {
    ready "$SANDBOX" && return
    log "Contenidor de proves (sandbox)"
    retry new_instance "$UBUNTU_APP" "$SANDBOX" -c limits.memory=512MiB -c limits.cpu=1 \
        -c limits.processes=256 -c boot.autostart=true
    nic "$SANDBOX" gestio he-gestio eth1
    addresses "$SANDBOX" 50-lab.yaml eth1 10.255.0.3/16
    internet "$SANDBOX" on
    retry incus start "$SANDBOX"
    wait_boot "$SANDBOX"
    wait_internet "$SANDBOX"
    incus exec "$SANDBOX" -- bash -euc '
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -q
        apt-get install -y -q --no-install-recommends openssh-server openssh-client ansible-core \
            python3 iputils-ping netcat-openbsd bind9-dnsutils curl ca-certificates
        id heimdeye >/dev/null 2>&1 || useradd --home-dir /var/lib/heimdeye --create-home --shell /bin/bash heimdeye
        install -d -o heimdeye -g heimdeye -m 700 /var/lib/heimdeye/.ssh
        cat > /etc/ssh/sshd_config.d/heimdeye.conf <<EOF
AllowUsers heimdeye
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin no
AllowTcpForwarding no
AllowAgentForwarding no
AllowStreamLocalForwarding no
X11Forwarding no
PermitTunnel no
PermitTTY no
EOF
        cd / && sudo -u heimdeye -H ansible-galaxy collection install ansible.windows
        apt-get clean'
    internet "$SANDBOX" off
    unprivileged_ping "$SANDBOX"
    hosts_file "$SANDBOX"
    incus config set "$SANDBOX" user.lab.llest=1
}

# Valors del .env de HeimdEye (CLAU=valor...): substitueix la línia o l'afegeix.
app_env() {
    he bash -euc '
        f=/var/www/heimdeye/shared/.env
        for kv in "$@"; do
            { grep -v "^${kv%%=*}=" "$f" || true; printf "%s\n" "$kv"; } > "$f.new"
            cat "$f.new" > "$f"
            rm "$f.new"
        done' app_env "$@"
}

# APP_KEY i contrasenya de la BBDD noves.
app_secrets() {
    local password
    password=$(he openssl rand -hex 24)
    he sudo -u postgres psql -qc "ALTER ROLE heimdeye PASSWORD '$password'"
    app_env "APP_KEY=base64:$(he openssl rand -base64 32)" "DB_PASSWORD=$password"
}

app_config() {
    local url=${HEIMDEYE_LAB_URL:-http://localhost:8080} secure=false
    [[ $url == https://* ]] && secure=true
    app_env "APP_URL=$url" "SESSION_SECURE_COOKIE=$secure" HEIMDEYE_TRUSTED_PROXIES=127.0.0.1 \
        HEIMDEYE_ADMIN_NAME=Professor HEIMDEYE_ADMIN_EMAIL=professor@heimdeye.test \
        "HEIMDEYE_DEMO_STUDENT_KEY=$KEY" "HEIMDEYE_LAB_KEY=$KEY" "HEIMDEYE_LAB_ALUMNES=$N"
    if [[ $MODE == plantilla ]]; then
        app_env HEIMDEYE_AUTH=plantilla HEIMDEYE_DEMO=false HEIMDEYE_LAB=true HEIMDEYE_RUNNER_WRAPPER=
    else
        app_env HEIMDEYE_AUTH=local HEIMDEYE_DEMO=true HEIMDEYE_LAB=false \
            "HEIMDEYE_RUNNER_WRAPPER=$CURRENT/lab/heimdeye-sandbox"
    fi
}

# El darrer commit d'aquesta carpeta, a $SRC dins el contenidor (deploy.sh en clona els releases).
push_code() {
    git -c safe.directory="$REPO" -C "$REPO" bundle create -q "$STATE/heimdeye.bundle" HEAD
    incus file push "$STATE/heimdeye.bundle" "$HE/tmp/heimdeye.bundle"
    he bash -euc "rm -rf $SRC && git clone -q --no-checkout /tmp/heimdeye.bundle $SRC \
        && git -C $SRC checkout -q $(git -c safe.directory="$REPO" -C "$REPO" rev-parse HEAD) && rm /tmp/heimdeye.bundle"
    he chown -R heimdeye:heimdeye "$SRC" 2>/dev/null || true
}

# Desplegament com a producció (deploy/deploy.sh): release nou, composer install, migracions.
deploy() {
    local sha
    sha=$(git -c safe.directory="$REPO" -C "$REPO" rev-parse HEAD)
    he sudo -u heimdeye -H env SKIP_CI=1 SKIP_GUARD=1 HEIMDEYE_REPO="$SRC" "$SRC/deploy/deploy.sh" "$sha"
    [[ $(he cat "$CURRENT/REVISION") == "$sha" ]] || die "no s'ha desplegat ${sha:0:7}"
    # Seeders idempotents: el primer cop omplen la BBDD i després hi afegeixen el que sigui nou.
    artisan db:seed --class=DemoSeeder --force
}

# HeimdEye instal·lat com a producció (deploy/install.sh) dins un contenidor LXC: Apache, php-fpm,
# PostgreSQL i els serveis de systemd. L'app és a http://localhost:8080 de la VM (dispositiu proxy).
heimdeye_container() {
    if ! ready "$HE"; then
        log "Contenidor de HeimdEye (instal·lació, uns minuts)"
        # Niament: els serveis de systemd amb aïllament (ProtectSystem...) el necessiten.
        retry new_instance "$UBUNTU_APP" "$HE" -c security.nesting=true -c boot.autostart=true
        nic "$HE" gestio he-gestio eth1
        nic "$HE" wan he-wan eth2
        addresses "$HE" 50-lab.yaml eth1 10.255.0.2/16 eth2 203.0.113.2/24
        internet "$HE" on
        retry incus start "$HE"
        wait_boot "$HE"
        wait_internet "$HE"
        he bash -euc '
            export DEBIAN_FRONTEND=noninteractive
            apt-get update -q
            apt-get install -y -q php8.5-fpm php8.5-{pgsql,mbstring,xml,curl,zip,intl,bcmath} \
                apache2 postgresql composer git sudo openssh-client iputils-ping netcat-openbsd \
                bind9-dnsutils curl'
        push_code
        he "$SRC/deploy/install.sh" >/dev/null
        he chown -R heimdeye:heimdeye "$SRC"
        # Només hi entra la mateixa màquina: el dispositiu proxy d'Incus hi connecta des de 127.0.0.1.
        he sed -i 's/^\(\s*\)Require ip .*/\1Require ip 127.0.0.1 ::1/' /etc/apache2/sites-available/heimdeye.conf
        he systemctl reload apache2
        he bash -euc '
            cd /
            sudo -u postgres psql -tAc "select 1 from pg_roles where rolname = '\''heimdeye'\''" | grep -q 1 \
                || sudo -u postgres createuser heimdeye
            sudo -u postgres psql -tAc "select 1 from pg_database where datname = '\''heimdeye'\''" | grep -q 1 \
                || sudo -u postgres createdb -O heimdeye heimdeye'
        app_secrets
        app_config
        unprivileged_ping "$HE"
        hosts_file "$HE"
        deploy
        he systemctl enable --now heimdeye-queue@1 heimdeye-queue@2 heimdeye-schedule.timer
        internet "$HE" off
        incus config set "$HE" user.lab.llest=1
    fi
    local listen=127.0.0.1
    [[ $MODE == publica ]] && listen=0.0.0.0
    incus config device show "$HE" | grep -q '^web:' \
        || incus config device add "$HE" web proxy "listen=tcp:$listen:8080" connect=tcp:127.0.0.1:8080 >/dev/null
    wait_app
}

# Claus: la del laboratori als equips (i, privada, on s'executa el codi) i la del sandbox.
distribute_keys() {
    local name
    for name in $(lab_instances); do
        put_file "$name" /home/heimdeye/.ssh/authorized_keys heimdeye < "$STATE/id_ed25519.pub"
    done
    for name in $(tutorial_instances); do
        put_file "$name" /home/alumne/.ssh/authorized_keys alumne < "$STATE/id_ed25519.pub"
    done
    if [[ $MODE == plantilla ]]; then
        put_file "$HE" "$KEY" heimdeye < "$STATE/id_ed25519"
    else
        put_file "$SANDBOX" "$KEY" heimdeye < "$STATE/id_ed25519"
        he sudo -u heimdeye sh -c 'test -s ~/.ssh/sandbox_ed25519 || ssh-keygen -q -t ed25519 -N "" -C heimdeye-sandbox -f ~/.ssh/sandbox_ed25519'
        he cat /var/lib/heimdeye/.ssh/sandbox_ed25519.pub | put_file "$SANDBOX" /var/lib/heimdeye/.ssh/authorized_keys heimdeye
    fi
}

wait_app() {
    for _ in $(seq 60); do
        he curl -sf -o /dev/null http://127.0.0.1:8080/up && return
        sleep 5
    done
    die "HeimdEye no respon: incus exec $HE -- journalctl -u apache2 -u php8.5-fpm, i $CURRENT/storage/logs"
}

# Identificador de la màquina: canvia en clonar la VM (UUID del maquinari; si no n'hi ha, machine-id).
machine_id() {
    cat /sys/class/dmi/id/product_uuid 2>/dev/null || cat /etc/machine-id
}

# Servei que, en arrencar un clon de la plantilla, fa "lab.sh clon" sol.
clone_service() {
    cat > /etc/systemd/system/heimdeye-lab-clon.service <<EOF
[Unit]
Description=HeimdEye: secrets nous si aquesta VM és un clon de la plantilla
After=incus.service incus-startup.service network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=$REPO/lab/lab.sh clon-si-cal
TimeoutStartSec=30min

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    systemctl enable heimdeye-lab-clon.service >/dev/null
}

# Els alumnes comencen en punts diferents, perquè el seguiment i el leaderboard tinguin vida:
# 1 té els blocs 1 i 2, 2 només les adreces, 3 els tres blocs i 4 res.
initial_state() {
    [[ $MODE == plantilla ]] || return 0
    log "Estat inicial dels alumnes"
    run_action 'Router: adreces i reenviament' 1 2 3
    run_action 'Client i DMZ: adreces' 1 2 3
    run_action 'Router: NAT de sortida' 1 3
    run_action 'Router: tallafoc' 1 3
    run_action 'Router: servidor DHCP Kea' 3
    run_action 'Client: adreça per DHCP' 3
    run_checks
}

run_checks() {
    [[ $MODE == plantilla ]] || return 0
    artisan heimdeye:run --module=SMX2 >/dev/null || true
}

run_action() {
    local name=$1 args=() n
    shift
    for n in "$@"; do ((n <= N)) && args+=(--student="$n"); done
    ((${#args[@]})) || return 0
    artisan heimdeye:action "$name" "${args[@]}" >/dev/null || echo "   L'acció «$name» ha fallat (es pot repetir des de l'app)"
}

snapshot() {
    for name in $(student_instances); do
        incus snapshot delete "$name" inicial 2>/dev/null || true
        incus snapshot create "$name" inicial
    done
}

restore() {
    for name in $(student_instances); do
        incus stop --force "$name" 2>/dev/null || true
        incus snapshot restore "$name" inicial
        incus start "$name"
        wait_boot "$name"
    done
}

cmd_crea() {
    prepare_incus
    network he-gestio 10.255.0.1/16
    network he-wan 203.0.113.1/24
    base_image
    lab_key
    install -d -m 700 "$STATE"
    echo "$MODE" > "$STATE/mode"
    if [[ $MODE == plantilla ]]; then
        for ((n = 1; n <= N; n++)); do student_lab "$n"; done
    else
        sandbox_container
    fi
    tutorial_servers
    heimdeye_container
    distribute_keys
    initial_state
    snapshot
    echo "$N" > "$STATE/alumnes"
    if [[ $MODE == plantilla ]]; then
        machine_id > "$STATE/maquina"
        clone_service
        log "Fet. HeimdEye: http://localhost:8080 (en aquesta màquina)"
    else
        log "Fet. HeimdEye: port 8080 de la VM (publica-ho amb el túnel de Cloudflare, docs/demo.md)"
    fi
}

# Un clon de la plantilla: clau del laboratori i secrets de HeimdEye nous, i BBDD neta.
# No cal Internet.
cmd_clon() {
    ready "$HE" || die "primer: lab.sh crea"
    log "Clon: secrets nous"
    wait_boot "$HE"
    restore
    rm -f "$STATE/id_ed25519" "$STATE/id_ed25519.pub"
    lab_key
    [[ $MODE == publica ]] && he rm -f /var/lib/heimdeye/.ssh/sandbox_ed25519 /var/lib/heimdeye/.ssh/sandbox_ed25519.pub
    distribute_keys
    snapshot

    he systemctl stop heimdeye-schedule.timer 'heimdeye-queue@*'
    for _ in $(seq 30); do he pg_isready -q && break; sleep 2; done
    he bash -euc 'cd / && sudo -u postgres dropdb --force --if-exists heimdeye && sudo -u postgres createdb -O heimdeye heimdeye'
    app_secrets
    artisan optimize >/dev/null
    artisan migrate --force
    artisan db:seed --class=DemoSeeder --force
    he systemctl restart php8.5-fpm
    he systemctl start heimdeye-queue@1 heimdeye-queue@2 heimdeye-schedule.timer
    wait_app
    run_checks
    machine_id > "$STATE/maquina"
    log "Fet. HeimdEye: http://localhost:8080"
}

cmd_clon-si-cal() {
    [[ -s $STATE/maquina ]] || return 0
    [[ $(machine_id) == "$(cat "$STATE/maquina")" ]] && return 0
    cmd_clon
}

cmd_restaura() {
    log "Equips dels alumnes a l'estat inicial"
    restore
    run_checks
}

cmd_actualitza() {
    ready "$HE" || die "primer: lab.sh crea"
    wait_boot "$HE"
    internet "$HE" on
    push_code
    deploy
    internet "$HE" off
    log "Fet: $(he cat "$CURRENT/REVISION" | cut -c1-7)"
}

cmd_estat() {
    incus list -c ns4
}

cmd_esborra() {
    local name
    for name in router2{1..4} client2{1..4} dmz2{1..4} $(tutorial_instances) "$SANDBOX" "$HE"; do
        exists "$name" && incus delete --force "$name"
    done
    for ((n = 1; n <= 4; n++)); do
        incus network delete "he-lan$((20 + n))" 2>/dev/null || true
        incus network delete "he-dmz$((20 + n))" 2>/dev/null || true
    done
    incus network delete he-wan 2>/dev/null || true
    incus network delete he-gestio 2>/dev/null || true
    systemctl disable heimdeye-lab-clon.service 2>/dev/null || true
    unlink /etc/systemd/system/heimdeye-lab-clon.service 2>/dev/null || true
    rm -rf "$STATE"
}

case ${1:-} in
    crea | restaura | actualitza | estat | esborra | clon | clon-si-cal) "cmd_$1" ;;
    *) sed -n '2,20p' "$0"; exit 1 ;;
esac
