# Demo pública a la VM HeimdEyeDemo (192.168.20.22)

Passos per posar la demo en marxa a la VM 192.168.20.22 (Ubuntu Server, clon enllaçat al Proxmox
del professor). El perquè de cada pas és al document de seguretat de la demo: la VM no té cap
secret real, no arriba a la LAN ni a Internet, i el Proxmox la restaura cada nit.

## 1. Instal·lació (amb Internet, una sola vegada)

A la VM, com a usuari amb sudo:

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
| `APP_URL` | l'adreça pública de la demo, p. ex. `https://demo-heimdeye.julioantonio.cat` |
| `DB_PASSWORD` | `openssl rand -hex 24` (només fa servir lletres i xifres) |
| `HEIMDEYE_TRUSTED_PROXIES` | `192.168.20.7` (el cloudflared del pve) |
| `HEIMDEYE_ADMIN_NAME`, `HEIMDEYE_ADMIN_EMAIL` | el teu nom i correu (l'administrador no entra per la web de la demo) |
| `SESSION_SECURE_COOKIE` | `true` (afegeix-la) |

`HEIMDEYE_DEMO`, `HEIMDEYE_SEED` i el seeder de la demo ja els posa `compose.demo.yaml`.

```bash
docker compose -f compose.yaml -f compose.demo.yaml up -d --build
docker compose -f compose.yaml -f compose.demo.yaml ps     # tot "running", keys "exited (0)"
curl -s localhost:8080/up                                   # 200
```

Prova-ho des d'un navegador de l'aula a `http://192.168.20.22:8080`: botó **Entrar a la demo**,
**Checks → Llista**, verifica *El servidor de l'alumne respon* per a tots els alumnes (ha de
sortir en verd) i executa l'acció *Publicar la web* (TUT2).

## 2. Tallar la xarxa (tallafocs del Proxmox)

Al Proxmox, a la NIC de la VM marca **Firewall** (`firewall=1`). Abans d'activar el tallafocs
del datacenter, comprova que hi ha una regla que et deixa entrar al pve pel 8006 i el 22 des
de la teva xarxa d'administració, o et quedaràs fora.

`/etc/pve/firewall/<vmid>.fw`:

```
[OPTIONS]
enable: 1
policy_in: DROP
policy_out: DROP

[RULES]
IN ACCEPT -source 192.168.20.7 -p tcp -dport 8080 -log nolog # cloudflared del pve
IN ACCEPT -source <IP del teu equip> -p tcp -dport 22 -log nolog # administració
```

Les respostes de les connexions acceptades passen soles (el tallafocs té estat). Per actualitzar
la demo amb Internet, posa temporalment `policy_out: ACCEPT` i torna-la a `DROP` en acabar.

Comprova-ho des de la VM: `curl -m5 https://example.com` i `ping -c1 192.168.20.12` han de fallar.

## 3. Publicar-la amb el túnel del pve

Al tauler de Zero Trust, al túnel que ja tens (el del cloudflared del pve), afegeix un *public
hostname*: `demo-heimdeye.julioantonio.cat` → `http://192.168.20.22:8080`. **Sense** aplicació
d'Access: la demo és oberta.

Al tauler de Cloudflare, a la zona `julioantonio.cat` → Security → WAF:

- **Custom rule** «Demo: repte a l'entrada»: `(http.host eq "demo-heimdeye.julioantonio.cat" and starts_with(http.request.uri.path, "/admin/login"))` → **Managed Challenge**.
- **Rate limiting rule** «Demo: límit per IP»: `http.host eq "demo-heimdeye.julioantonio.cat"`, 100 peticions cada 10 s per IP → Block 10 s.

## 4. Instantània neta i restauració cada nit

Amb la demo acabada d'instal·lar (sense visitants), al pve:

```bash
qm snapshot <vmid> net --description "Demo neta" --vmstate 0
```

`crontab -e` de root al pve:

```
# Cada nit a les 4: la VM torna a l'estat net (BBDD inclosa) i s'engega.
0 4 * * * qm rollback <vmid> net && qm start <vmid>
# Cada 10 minuts: si la demo no respon dues vegades, es restaura.
*/10 * * * * curl -sfm5 http://192.168.20.22:8080/up >/dev/null || (sleep 30; curl -sfm5 http://192.168.20.22:8080/up >/dev/null) || (qm rollback <vmid> net && qm start <vmid>)
```

Els contenidors tenen `restart: unless-stopped` i arrenquen sols amb la VM.

## 5. Actualitzar la demo (de moment, a mà)

```bash
# policy_out: ACCEPT al tallafocs de la VM
cd ~/heimdeye && git pull
cd docker && docker compose -f compose.yaml -f compose.demo.yaml down -v
docker compose -f compose.yaml -f compose.demo.yaml up -d --build
# policy_out: DROP, comprova la demo i, al pve:
qm delsnapshot <vmid> net && qm snapshot <vmid> net --vmstate 0
```

Més endavant ho farà l'acció *Actualitzar demo* des de l'app de producció (fase 5).
