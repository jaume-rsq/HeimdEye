# Demo SMIX2: un laboratori de xarxa amb HeimdEye

Una demo per a professors de cicles: HeimdEye aplicat a un mòdul de serveis de xarxa. Cada alumne
fictici té la xarxa d'una petita empresa i la va muntant bloc a bloc. Les **accions** munten cada
servei amb una configuració bàsica (la solució del professor) i els **checks** en comproven el
funcionament, sempre que es pot des del punt de vista d'un client.

Tot corre en **una sola VM**, en contenidors LXC (Incus): HeimdEye i, per a cada alumne, un router,
un client i un servidor. S'hi entra directament com a professor (mode `plantilla`), sense
contrasenya: és una còpia per a una sola persona, pensada per clonar-la (p. ex. a IsardVDI).

> **Estat: prototip.** Hi ha els blocs 1 a 3 i el mode plantilla. Falten els blocs 4 a 8, la
> plantilla d'IsardVDI amb escriptori i navegador, i la versió pública amb reserves.
> `lab/lab.sh` encara no s'ha provat de punta a punta en una VM.

## Topologia

```
                   WAN 203.0.113.0/24 (he-wan)
   HeimdEye .2 ─────────────┬──────────────────────── (sense Internet)
                            │ eth1 203.0.113.X
                    ┌───────┴────────┐
                    │  routerX       │  nftables, Kea (DHCP), squid
                    └──┬──────────┬──┘
              eth2 .1  │          │  eth3 .1
          LAN 10.X.1.0/24      DMZ 10.X.2.0/24
               │                     │
          clientX .100          dmzX .10   bind9, nginx

   Gestió 10.255.0.0/16 (he-gestio, eth0 de cada equip): HeimdEye .0.2, routerX .X.1,
   clientX .X.10, dmzX .X.20. Només per a HeimdEye.
```

- **X = 20 + número de l'alumne** (21, 22...), com a l'aula. Tot el pla d'adreces són variables del
  mòdul calculades a partir de `x` (Inventari → Variables).
- **Xarxa de gestió:** HeimdEye entra a cada equip per una xarxa a part que no forma part de la
  pràctica. Si l'alumne (o una acció) trenca la xarxa, HeimdEye pot continuar mirant què passa.
- **HeimdEye també és a la WAN** i fa de client extern: el check *Router tancat des de la WAN* es
  fa des d'allà.
- **Zona DNS per alumne:** `usuari.heimdeye.test` (p. ex. `radia.heimdeye.test`).
- El client té una MAC fixa (`00:16:3e:00:01:X`) per a la reserva de DHCP.

Els equips parteixen d'una imatge amb tots els paquets ja instal·lats i aturats (nftables, Kea,
bind9, nginx, squid), perquè la demo no té Internet: les accions configuren i engeguen, no instal·len.

## Blocs

| Bloc | Accions | Checks |
| --- | --- | --- |
| 1. Xarxa i encaminament | Router: adreces i reenviament · Router: NAT de sortida · Client i DMZ: adreces | HeimdEye entra als tres equips; adreces del router; reenviament; el client i la DMZ surten a la WAN (ping a HeimdEye: sense NAT no hi ha resposta); NAT (opcional) |
| 2. Tallafoc | Router: tallafoc (nftables, política de descartar) | Política del reenviament; la LAN arriba a la DMZ; la DMZ **no** arriba a la LAN; el router té l'SSH tancat a la WAN (des de HeimdEye) |
| 3. DHCP a la LAN | Router: servidor DHCP Kea · Client: adreça per DHCP | Kea en marxa; el client té adreça dinàmica, la seva reserva i la porta d'enllaç; concessió al servidor (opcional) |

Cada bloc de nftables desa les seves regles a `/etc/nftables.d/` i `/etc/nftables.conf` les inclou
totes: el tallafoc no esborra el NAT.

Pendents: 4 DNS (bind9 a la DMZ), 5 web (nginx i DNAT), 6 proxy (squid al router), 7 WordPress
(un contenidor més a la DMZ) i 8 HTTPS (CA de l'empresa).

**Estat inicial:** perquè el seguiment i el leaderboard tinguin vida, els alumnes comencen en punts
diferents: Radia té els blocs 1 i 2, Vint només les adreces, Hedy els tres blocs i Paul res (amb
`HEIMDEYE_LAB_ALUMNES` de 1 a 4; per defecte, 2).

## Muntar-la

Una VM **Ubuntu Server 24.04** amb Internet durant la instal·lació. Recursos: no estan mesurats
encara; per començar, 4 vCPU, 8 GB de RAM i 40 GB de disc.

```bash
git clone https://github.com/jaume-rsq/HeimdEye.git ~/heimdeye
sudo ~/heimdeye/lab/lab.sh crea                         # o: sudo HEIMDEYE_LAB_ALUMNES=4 ...
```

`lab.sh crea` (es pot tornar a executar):

1. Instal·la i inicialitza Incus, si cal.
2. Crea les xarxes `he-gestio`, `he-wan` i la LAN i la DMZ de cada alumne, sense NAT ni DHCP.
3. Prepara la imatge base dels equips (`heimdeye-lab-base`) amb els paquets i l'usuari `heimdeye`
   (`sudo` sense contrasenya).
4. Genera una clau SSH nova per a HeimdEye i crea el router, el client i el servidor de cada alumne.
5. Crea el contenidor `heimdeye` (amb niament: Docker dins LXC) i hi engega el Docker Compose de la
   demo amb `compose.plantilla.yaml`, amb un `.env` amb secrets nous (`APP_KEY`, contrasenya de la BBDD).
6. Treu Internet al contenidor de HeimdEye i publica l'app a `http://localhost:8080` de la VM
   (dispositiu *proxy* d'Incus): només s'hi pot entrar des de la mateixa VM.
7. Porta els alumnes a l'estat inicial amb les accions, executa els checks i fa una instantània
   `inicial` de cada equip.

**Per què HeimdEye en LXC i no Docker a la VM:** Docker i Incus a la mateixa màquina xoquen al
tallafoc (Docker posa la política de `FORWARD` a `DROP` i talla els ponts d'Incus). Dins el seu
contenidor, Docker no toca el tallafoc de la VM.

Altres ordres:

```bash
sudo lab/lab.sh restaura     # els equips dels alumnes tornen a l'estat inicial
sudo lab/lab.sh estat        # contenidors i adreces
sudo lab/lab.sh actualitza   # HeimdEye amb el codi de la carpeta (torna a obrir Internet un moment)
sudo lab/lab.sh esborra      # contenidors i xarxes (la imatge base es queda)
incus exec router21 -- bash  # entrar a un equip, com faria l'alumne
```

## Mode plantilla

`HEIMDEYE_AUTH=plantilla`: qui obre l'app entra com l'administrador de la configuració
(`HEIMDEYE_ADMIN_EMAIL`, a la demo `professor@heimdeye.test`), sense contrasenya i sense els límits
de la demo pública. El codi s'executa directament al worker (no cal el sandbox: hi ha un sol usuari).

És segur només perquè l'app no és accessible des de fora de la VM. No el facis servir en una
instància publicada.

## Seguretat

- La demo no té Internet ni camí a la xarxa del centre: les xarxes del laboratori no tenen NAT.
- Els equips del laboratori són d'usar i llençar (`lab.sh restaura`). La xarxa de gestió és comuna:
  en aquesta demo d'un sol professor no importa; a la versió pública caldrà aïllar-la.
- La clau del laboratori és nova a cada instal·lació i no surt de la VM.
