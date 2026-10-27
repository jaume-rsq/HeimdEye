# Muntar una demo pública de HeimdEye

Com muntar una instància de demostració oberta a tothom: el visitant entra amb un botó, fa el
tutorial i pot crear i executar checks i accions contra uns alumnes ficticis. El tutorial que s'hi
carrega és a [demo-tutorial.md](demo-tutorial.md).

Una demo pública vol dir que **qualsevol persona d'Internet pot executar codi** al servidor. Tot
el disseny parteix d'aquí: la demo ha de ser una màquina que no importi perdre.

## Recomanacions sobre l'entorn

| Recomanació | Per què |
| --- | --- |
| **Una màquina només per a la demo** (VM, o VPS al núvol), mai la de producció | Si algú se'n surt del contenidor, només troba la demo. |
| **Sense cap secret real**: ni claus de producció, ni contrasenyes, ni dades d'alumnes | El que hi hagi, un visitant ho pot acabar llegint. |
| **Sense camí a la xarxa del centre**: la màquina no ha d'arribar a la LAN, a les xarxes dels alumnes ni al servidor de producció | Que la demo no serveixi de pont. |
| **Sense sortida a Internet** (excepte quan l'actualitzes) | Que no es pugui fer servir per atacar tercers ni per baixar eines. |
| **Entrada només des del túnel** | Cap port obert; el WAF de Cloudflare filtra robots i abusos. |
| **Restauració automàtica**: una instantània neta i un retorn cada nit | El que es trenqui o s'hi deixi, desapareix. |
| **Recursos limitats**: 2 vCPU, 4 GB de RAM, 30 GB de disc | Els límits dels contenidors fan la resta. |

On posar-la:

- **Hipervisor propi (Proxmox, VMware, VirtualBox...)**: una VM Ubuntu Server, millor clonada d'una
  plantilla. El tallafoc de l'hipervisor (a la targeta de xarxa de la VM) és el lloc ideal per tallar
  el trànsit, perquè des de dins de la VM no es pot desactivar.
- **VPS al núvol**: ja és fora de la xarxa del centre. Tanca tots els ports d'entrada al tallafoc del
  proveïdor (*security group*) i limita la sortida si el proveïdor ho permet.
- **No** en un contenidor LXC ni a la mateixa màquina que producció: la separació és molt més feble.

## Què porta

La demo és el Docker Compose de HeimdEye (`docker/compose.yaml`) amb `docker/compose.demo.yaml`:

- **Mode demo** (`HEIMDEYE_DEMO=true`): el login és un botó que crea un professor visitant, que veu
  l'estructura en només lectura, només edita el que ha creat ell i té límits d'execució
  (30 s per execució, 60 execucions per hora). Els visitants inactius s'esborren cada hora.
- **Contenidor de proves** (`sandbox`): hi corre tot el codi dels checks i les accions, sense accés
  a la BBDD ni al `.env`, amb el sistema de fitxers de només lectura i sense privilegis.
- **Alumnes ficticis** (`alumne01`…`alumne04`): contenidors Ubuntu amb SSH, en una xarxa interna.
- **Tutorial**: el curs *Tutorial* (`DemoSeeder`) es carrega sol el primer cop.

Els detalls són al README, secció *Mode demo*.

## 1. Instal·lació

Amb Internet, una sola vegada:

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER        # i torna a entrar
git clone https://github.com/jaume-rsq/HeimdEye.git ~/heimdeye
cd ~/heimdeye/docker
cp env.example .env
```

A `docker/.env`:

| Variable | Valor |
| --- | --- |
| `APP_KEY` | `base64:` + `openssl rand -base64 32` |
| `APP_URL` | L'adreça pública de la demo, p. ex. `https://demo-heimdeye.exemple.cat` |
| `DB_PASSWORD` | `openssl rand -hex 24` (només lletres i xifres) |
| `SESSION_SECURE_COOKIE` | `true` |
| `HEIMDEYE_TRUSTED_PROXIES` | L'adreça del `cloudflared`. Si corre dins el Compose (perfil `tunnel`), el rang de les xarxes de Docker: `172.16.0.0/12` |
| `HEIMDEYE_ADMIN_NAME`, `HEIMDEYE_ADMIN_EMAIL` | Les teves dades (l'administrador no entra per la web de la demo) |
| `TUNNEL_TOKEN` | El token del túnel, si `cloudflared` corre dins el Compose |

`HEIMDEYE_DEMO`, `HEIMDEYE_SEED` i el seeder de la demo ja els posa `compose.demo.yaml`.

```bash
docker compose -f compose.yaml -f compose.demo.yaml up -d --build
docker compose -f compose.yaml -f compose.demo.yaml ps     # tot "running", keys "exited (0)"
curl -s localhost:8080/up                                   # respon
```

**Per provar-la abans de publicar-la**, des de la xarxa local per `http://<IP>:8080`, posa
temporalment `SESSION_SECURE_COOKIE=false` i `APP_URL=http://<IP>:8080`. Amb `true`, el navegador no
desa la cookie sense HTTPS i el login dona «Page expired» (419). Torna-les a deixar com a la
taula abans de publicar-la i recrea els contenidors.

Prova: botó **Entrar a la demo**, **Checks → Llista**, verifica *El servidor de l'alumne respon*
per a tots els alumnes (ha de sortir en verd) i executa l'acció *Publicar la web* (TUT2).

## 2. Tallar la xarxa

Al tallafoc de l'hipervisor o del proveïdor:

- **Entrada**: res, excepte el port 8080 des del `cloudflared` (si és en una altra màquina) i el 22
  des del teu equip d'administració.
- **Sortida**: res. Les respostes de les connexions acceptades passen soles (el tallafoc té estat).
  Si `cloudflared` és dins la VM, necessita sortida cap a Cloudflare: el port 7844 (TCP i UDP) i
  el DNS per resoldre `region1.v2.argotunnel.com` i `region2.v2.argotunnel.com`
  ([Tunnel with firewall](https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/configure-tunnels/tunnel-with-firewall/)).
  Obre només això. Per això és més fàcil tenir el `cloudflared` fora de la VM de la demo.

Exemple amb el tallafoc de Proxmox (`/etc/pve/firewall/<vmid>.fw`), amb `firewall=1` a la targeta
de la VM i el tallafoc del datacenter activat (abans, comprova que tens una regla que et deixa
entrar al Proxmox pels ports 8006 i 22, o et quedaràs fora):

```
[OPTIONS]
enable: 1
policy_in: DROP
policy_out: DROP

[RULES]
IN ACCEPT -source <IP del cloudflared> -p tcp -dport 8080 -log nolog
IN ACCEPT -source <IP del teu equip> -p tcp -dport 22 -log nolog
```

Comprova-ho des de la VM: `curl -m5 https://example.com` i un `ping` a qualsevol equip de la
xarxa del centre han de fallar.

## 3. Publicar-la

Segueix [cloudflare.md](cloudflare.md): un túnel (millor un de propi per a la demo, no el de
producció) amb un *published application route* cap a HeimdEye, **sense** aplicació d'Access, i les
regles del WAF de l'apartat *Protegir la demo*.

## 4. Instantània neta i restauració cada nit

Amb la demo acabada d'instal·lar, sense visitants: apaga la VM i fes-ne una instantània (`net`).
Després, cada nit, torna-hi. Amb Proxmox, al host:

```bash
qm snapshot <vmid> net --description "Demo neta" --vmstate 0
```

`crontab -e` de root al host:

```
# Cada nit a les 4: la VM torna a l'estat net (BBDD inclosa) i s'engega.
0 4 * * * qm rollback <vmid> net && qm start <vmid>
# Cada 10 minuts: si la demo no respon dues vegades, es restaura.
*/10 * * * * curl -sfm5 http://<IP de la demo>:8080/up >/dev/null || (sleep 30; curl -sfm5 http://<IP de la demo>:8080/up >/dev/null) || (qm rollback <vmid> net && qm start <vmid>)
```

En un VPS, fes el mateix amb les instantànies del proveïdor (o amb `docker compose down -v` i
`up -d` cada nit, que torna la BBDD a l'estat inicial però no la màquina).

Els contenidors tenen `restart: unless-stopped` i arrenquen sols amb la màquina.

## 5. Actualitzar-la

```bash
# Obre temporalment la sortida a Internet
cd ~/heimdeye && git pull
cd docker && docker compose -f compose.yaml -f compose.demo.yaml down -v
docker compose -f compose.yaml -f compose.demo.yaml up -d --build
# Torna a tancar la sortida, comprova la demo i refés la instantània neta
```
