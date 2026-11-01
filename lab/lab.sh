#!/usr/bin/env bash
# Laboratori de la demo SMIX2: HeimdEye i la xarxa de cada alumne fictici en contenidors LXC (Incus),
# tot dins una sola VM. Vegeu docs/demo-smix2.md.
#
#   sudo lab/lab.sh crea        # prepara Incus, la imatge base, els equips, HeimdEye i l'estat inicial
#   sudo lab/lab.sh restaura    # torna els equips dels alumnes a l'estat inicial
#   sudo lab/lab.sh estat       # llista dels contenidors
#   sudo lab/lab.sh actualitza  # HeimdEye amb el codi d'aquesta carpeta (cal Internet)
#   sudo lab/lab.sh esborra     # esborra els contenidors i les xarxes (no la imatge base)
#
# Alumnes: HEIMDEYE_LAB_ALUMNES (1 a 4, per defecte 2). Crear-ho tot necessita Internet (paquets,
# imatges, Docker); en acabar, cap contenidor en té.
#
# Pla d'adreces (X = 20 + número d'alumne), el mateix que database/seeders/Smix2LabSeeder.php:
#   gestió  he-gestio 10.255.0.0/16  amfitrió .0.1, HeimdEye .0.2, router .X.1, client .X.10, DMZ .X.20
#   WAN     he-wan    203.0.113.0/24 amfitrió .1, HeimdEye .2, router .X
#   LAN     he-lanX   10.X.1.0/24    (sense adreça a l'amfitrió: la configura l'alumne)
#   DMZ     he-dmzX   10.X.2.0/24
set -euo pipefail

REPO=$(cd "$(dirname "$0")/.." && pwd)
N=${HEIMDEYE_LAB_ALUMNES:-2}
STATE=/var/lib/heimdeye-lab
UBUNTU=images:ubuntu/24.04
IMAGE=heimdeye-lab-base
HE=heimdeye
APP=/opt/heimdeye
COMPOSE=(docker compose -f compose.yaml -f compose.demo.yaml -f compose.plantilla.yaml)
ROLES=(router client dmz)

log() { echo "== $*"; }
die() { echo "ERROR: $*" >&2; exit 1; }
exists() { incus info "$1" >/dev/null 2>&1; }
# Ordres dins el contenidor de HeimdEye, a la carpeta del Compose.
he() { incus exec "$HE" --cwd "$APP/docker" -- "$@"; }
artisan() { he "${COMPOSE[@]}" exec -T app php artisan "$@"; }
lab_instances() {
    local n
    for ((n = 1; n <= N; n++)); do
        for role in "${ROLES[@]}"; do echo "$role$((20 + n))"; done
    done
}

[[ $EUID -eq 0 ]] || die "cal executar-ho amb sudo"
[[ $N =~ ^[1-4]$ ]] || die "HEIMDEYE_LAB_ALUMNES ha de ser de 1 a 4"

# Espera que el contenidor tingui xarxa cap a Internet (per instal·lar paquets).
wait_internet() {
    for _ in $(seq 60); do
        incus exec "$1" -- getent hosts archive.ubuntu.com >/dev/null 2>&1 && return
        sleep 2
    done
    die "$1 no té Internet"
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

# Imatge comuna dels equips del laboratori: els paquets de tots els blocs, aturats, i l'usuari heimdeye.
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

# Adreça de gestió i clau de HeimdEye en un equip ja engegat.
setup_access() {
    local name=$1 address=$2
    incus exec "$name" -- sh -c 'cat > /etc/netplan/50-gestio.yaml && chmod 600 /etc/netplan/50-gestio.yaml && netplan apply' <<EOF
network:
  version: 2
  ethernets:
    eth0:
      addresses: [$address/16]
EOF
    incus exec "$name" -- sh -c 'cat > /home/heimdeye/.ssh/authorized_keys && chown heimdeye: /home/heimdeye/.ssh/authorized_keys && chmod 600 /home/heimdeye/.ssh/authorized_keys' < "$STATE/id_ed25519.pub"
}

student_lab() {
    local x=$((20 + $1))
    network "he-lan$x" none
    network "he-dmz$x" none
    for role in "${ROLES[@]}"; do
        local name=$role$x host
        exists "$name" && continue
        log "Equip $name"
        incus init "$IMAGE" "$name" -p he-lab -c limits.memory=256MiB -c limits.cpu=1
        incus config device add "$name" eth0 nic network=he-gestio name=eth0 >/dev/null
        case $role in
            router)
                host=1
                incus config device add "$name" eth1 nic network=he-wan name=eth1 >/dev/null
                incus config device add "$name" eth2 nic network="he-lan$x" name=eth2 >/dev/null
                incus config device add "$name" eth3 nic network="he-dmz$x" name=eth3 >/dev/null
                ;;
            client)
                host=10
                # La MAC de la reserva de DHCP (variable mac_client del seeder).
                incus config device add "$name" eth1 nic network="he-lan$x" name=eth1 hwaddr="00:16:3e:00:01:$x" >/dev/null
                ;;
            dmz)
                host=20
                incus config device add "$name" eth1 nic network="he-dmz$x" name=eth1 >/dev/null
                ;;
        esac
        incus start "$name"
        setup_access "$name" "10.255.$x.$host"
    done
}

# HeimdEye: el Docker Compose de la demo dins un contenidor LXC amb niament (Docker dins LXC).
# Docker i Incus a la mateixa màquina xoquen al tallafoc; dins el contenidor, no.
heimdeye_container() {
    if ! exists "$HE"; then
        log "Contenidor de HeimdEye"
        incus init "$UBUNTU" "$HE" -p he-lab -c security.nesting=true
        incus config device add "$HE" gestio nic network=he-gestio name=eth1 >/dev/null
        incus config device add "$HE" wan nic network=he-wan name=eth2 >/dev/null
        incus start "$HE"
        incus exec "$HE" -- sh -c 'cat > /etc/netplan/50-lab.yaml && chmod 600 /etc/netplan/50-lab.yaml' <<'EOF'
network:
  version: 2
  ethernets:
    eth1:
      addresses: [10.255.0.2/16]
    eth2:
      addresses: [203.0.113.2/24]
EOF
    fi
    internet on
    incus exec "$HE" -- netplan apply
    wait_internet "$HE"
    incus exec "$HE" -- bash -euc 'command -v docker >/dev/null && command -v curl >/dev/null \
        || { apt-get update -q; DEBIAN_FRONTEND=noninteractive apt-get install -y -q docker.io docker-compose-v2 curl; }'
    copy_code

    # Secrets nous: APP_KEY, contrasenya de la BBDD i la clau del laboratori.
    incus exec "$HE" --cwd "$APP/docker" --env N="$N" -- bash -euc '
        if [ ! -s .env ]; then
            cp env.example .env
            sed -i -e "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" \
                   -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -hex 24)|" \
                   -e "s|^HEIMDEYE_ADMIN_NAME=.*|HEIMDEYE_ADMIN_NAME=Professor|" \
                   -e "s|^HEIMDEYE_ADMIN_EMAIL=.*|HEIMDEYE_ADMIN_EMAIL=professor@heimdeye.test|" .env
            echo "HEIMDEYE_LAB_ALUMNES=$N" >> .env
        fi
        install -d -o 1000 -g 1000 -m 700 lab-key'
    incus file push "$STATE/id_ed25519" "$HE$APP/docker/lab-key/id_ed25519" --uid 1000 --gid 1000 --mode 0600

    log "Construint i engegant HeimdEye (uns minuts)"
    he "${COMPOSE[@]}" up -d --build
    internet off

    incus config device show "$HE" | grep -q '^web:' \
        || incus config device add "$HE" web proxy listen=tcp:127.0.0.1:8080 connect=tcp:127.0.0.1:8080 >/dev/null
    for _ in $(seq 120); do
        he curl -sf -o /dev/null http://127.0.0.1:8080/up && return
        sleep 5
    done
    die "HeimdEye no respon: incus exec $HE --cwd $APP/docker -- ${COMPOSE[*]} logs app"
}

# El codi d'aquesta carpeta, sense vendor ni .git (la imatge fa el composer install).
copy_code() {
    incus exec "$HE" -- install -d "$APP"
    tar -C "$REPO" --exclude=./.git --exclude=./vendor --exclude=./node_modules --exclude=./docker/.env --exclude=./docker/lab-key -cf - . \
        | incus exec "$HE" -- tar -C "$APP" -xf -
}

# Internet per al contenidor de HeimdEye (per instal·lar i construir), per la xarxa per defecte d'Incus.
internet() {
    if [[ $1 == on ]]; then
        incus config device show "$HE" | grep -q '^inet:' \
            || incus config device add "$HE" inet nic network=incusbr0 name=eth0 >/dev/null
    else
        incus config device show "$HE" | grep -q '^inet:' && incus config device remove "$HE" inet >/dev/null
        return 0
    fi
}

# Els alumnes comencen en punts diferents, perquè el seguiment i el leaderboard tinguin vida:
# 1 té els blocs 1 i 2, 2 només les adreces, 3 els tres blocs i 4 res.
initial_state() {
    log "Estat inicial dels alumnes"
    run_action 'Router: adreces i reenviament' 1 2 3
    run_action 'Client i DMZ: adreces' 1 2 3
    run_action 'Router: NAT de sortida' 1 3
    run_action 'Router: tallafoc' 1 3
    run_action 'Router: servidor DHCP Kea' 3
    run_action 'Client: adreça per DHCP' 3
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
    for name in $(lab_instances); do
        incus snapshot delete "$name" inicial 2>/dev/null || true
        incus snapshot create "$name" inicial
    done
}

cmd_crea() {
    prepare_incus
    network he-gestio 10.255.0.1/16
    network he-wan 203.0.113.1/24
    base_image
    lab_key
    for ((n = 1; n <= N; n++)); do student_lab "$n"; done
    heimdeye_container
    initial_state
    snapshot
    log "Fet. HeimdEye: http://localhost:8080 (en aquesta màquina)"
}

cmd_restaura() {
    log "Equips dels alumnes a l'estat inicial"
    for name in $(lab_instances); do
        incus stop --force "$name"
        incus snapshot restore "$name" inicial
        incus start "$name"
    done
    artisan heimdeye:run --module=SMX2 >/dev/null || true
}

cmd_actualitza() {
    exists "$HE" || die "primer: lab.sh crea"
    internet on
    incus exec "$HE" -- netplan apply
    wait_internet "$HE"
    copy_code
    he "${COMPOSE[@]}" up -d --build
    internet off
}

cmd_estat() {
    incus list -c ns4
}

cmd_esborra() {
    for name in $(lab_instances) "$HE"; do exists "$name" && incus delete --force "$name"; done
    for ((n = 1; n <= 4; n++)); do
        incus network delete "he-lan$((20 + n))" 2>/dev/null || true
        incus network delete "he-dmz$((20 + n))" 2>/dev/null || true
    done
    incus network delete he-wan 2>/dev/null || true
    incus network delete he-gestio 2>/dev/null || true
    rm -rf "$STATE"
}

case ${1:-} in
    crea | restaura | actualitza | estat | esborra) "cmd_$1" ;;
    *) sed -n '2,10p' "$0"; exit 1 ;;
esac
