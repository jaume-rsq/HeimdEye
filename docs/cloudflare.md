# Publicar HeimdEye amb Cloudflare

Aquesta guia explica com publicar HeimdEye a Internet amb Cloudflare, des d'un compte buit, sense
obrir cap port del router ni del servidor. Serveix per a la instància de producció
([produccio.md](produccio.md)) i per a la demo pública ([demo.md](demo.md)).

> Els noms de menú són els de la documentació de Cloudflare a 27/10/2026. Cloudflare canvia el
> tauler sovint: si un nom no hi és, cerca'l a <https://developers.cloudflare.com> (hi ha un
> enllaç a la pàgina oficial a cada pas).

## Les peces

| Peça | Què fa | Cost |
| --- | --- | --- |
| **Domini a Cloudflare** | Cloudflare respon el DNS del teu domini i hi pot posar noms nous (`heimdeye.exemple.cat`). | Pla Free |
| **Túnel** (`cloudflared`) | Un programa al teu servidor (o al costat) obre una connexió de sortida cap a Cloudflare. Les visites hi arriben per aquesta connexió: no cal obrir ports ni tenir IP pública. | Gratuït |
| **Access** | Abans d'arribar a HeimdEye, l'usuari ha d'entrar amb Google. HeimdEye rep qui és en un token signat (JWT). | Zero Trust Free: fins a 50 usuaris |
| **WAF** | Regles davant del lloc: repte anti-bots, límit de peticions per IP. Per a la demo, que no té Access. | Pla Free (amb límits) |

## Nivells de protecció

HeimdEye executa codi contra els equips dels alumnes amb claus d'accés. Si algú hi entra sense
permís pot fer molt de mal, així que convé tenir diverses capes i que cap depengui de les altres.

| Nivell | On | Què decideix | Recomanació |
| --- | --- | --- | --- |
| 1. Xarxa | Servidor de HeimdEye | Qui pot parlar amb el port de l'app | Només el `cloudflared` (i la mateixa màquina). Sense ports oberts a Internet. |
| 2. Access | Cloudflare | Qui pot arribar a la pàgina | Una política *Allow* amb els correus o el domini de l'institut. Res d'*Everyone* a l'app sencera. |
| 3. Usuaris | HeimdEye (`HEIMDEYE_AUTH=cloudflare`) | Qui entra i amb quin rol | Només els usuaris actius de la taula `usuaris`: algú que passa Access però no hi és rep un 403. |
| 4. Rols | HeimdEye | Què pot fer cadascú | Professors: només els seus mòduls. Alumnes: només el seu panell. Pocs administradors. |

Què passa si en falla un:

- **Sense el nivell 1** (port obert), algú podria saltar-se Cloudflare. HeimdEye rebutjaria la
  petició perquè no porta un JWT vàlid, però és millor que ni hi arribi.
- **Sense el nivell 2** (Access obert a tothom), qualsevol amb un compte de Google veuria la
  pantalla d'error de HeimdEye i ocuparia una plaça dels 50 usuaris gratuïts.
- **Amb Access però sense `HEIMDEYE_AUTH=cloudflare`**, l'app no sabria qui és l'usuari. No
  ho facis mai a producció.

## 1. Posar el domini a Cloudflare

Si el domini ja és a Cloudflare, salta al pas 2.

1. Crea un compte a <https://dash.cloudflare.com/sign-up>.
2. Afegeix el domini i tria el pla **Free**.
3. Cloudflare et dona dos *nameservers*. Al registrador del domini, canvia els nameservers pels de
   Cloudflare. Pot trigar unes hores; el domini surt com a actiu quan s'ha fet.

Pots fer servir un subdomini que no tingui res més: `heimdeye.exemple.cat`, `demo-heimdeye.exemple.cat`.

## 2. Crear el túnel

Documentació: [Create a tunnel (dashboard)](https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/get-started/create-remote-tunnel/).

1. Al tauler de Cloudflare, ves a **Networking** → **Tunnels** (al tauler de Zero Trust és
   **Networks** → **Connectors**; tots dos gestionen els mateixos túnels).
2. **Create a tunnel**, posa-li un nom (`heimdeye`) i **Create Tunnel**.
3. Tria el sistema operatiu i executa l'ordre que surt a la màquina on correrà `cloudflared`.
   Amb Docker, l'ordre porta el **token** del túnel:

   ```bash
   docker run -d --restart unless-stopped cloudflare/cloudflared:latest tunnel --no-autoupdate run --token <TOKEN>
   ```

   Amb el Docker Compose de HeimdEye, posa el token a `docker/.env` (`TUNNEL_TOKEN=...`) i
   arrenca amb el perfil `tunnel`: `docker compose --profile tunnel up -d`.
   El token és un secret: qui el tingui pot fer passar el teu nom de domini per una altra màquina.
4. Quan el túnel surti com a connectat, **Continue**.
5. A la pestanya **Routes**, **Add route** → **Published application**: subdomini, domini i
   **Service URL**, l'adreça de HeimdEye **vista des de `cloudflared`**:
   - `cloudflared` a la mateixa màquina: `http://localhost:8080`.
   - `cloudflared` en una altra màquina de la xarxa: `http://<IP del servidor>:8080`.
   - Docker Compose amb el perfil `tunnel`: `http://app:8080`.
6. Comprova que a **Networking** → **Tunnels** el túnel surt **Healthy**.

Un sol túnel pot publicar diversos noms (producció i demo), però si la demo és en una altra màquina
és millor que tingui el seu túnel: si algú en pren el control, no té el token de producció.

**No obris la URL encara** si és la de producció: sense el pas 4, qualsevol podria veure la
pàgina d'entrada.

## 3. Crear l'organització de Zero Trust

Documentació: [Getting started FAQ](https://developers.cloudflare.com/cloudflare-one/faq/getting-started-faq/).

1. Al tauler, entra a **Zero Trust**. El primer cop demana un **team name** i un pla: tria el
   **Free** (fins a 50 usuaris).
2. El team name fa el **team domain**: `<team>.cloudflareaccess.com`. És el valor de
   `CF_ACCESS_TEAM_DOMAIN`. Es pot canviar a **Zero Trust** → **Settings** → **Team name and domain**.

Cada persona que entra per Access ocupa una plaça (*seat*) mentre està activa. Una classe de 30
alumnes i uns quants professors hi cap; si no, es poden alliberar places des del tauler.

## 4. Entrar amb Google

Hi ha dues opcions:

| Opció | Qui pot entrar | Grups | Requisits |
| --- | --- | --- | --- |
| **Google** | Qualsevol compte de Google (Gmail o de centre) | No | Un projecte a Google Cloud (gratuït) |
| **Google Workspace** | Només els comptes del teu domini Workspace | Sí (es poden fer polítiques per grups) | Ser administrador del Workspace |

Si no ets administrador del Workspace del centre, fes servir **Google** i filtra per domini de
correu a la política (pas 5): l'efecte és pràcticament el mateix.

### 4a. A Google Cloud

Documentació: [Google](https://developers.cloudflare.com/cloudflare-one/integrations/identity-providers/google/).

1. Entra a <https://console.cloud.google.com>, crea un projecte (`heimdeye-access`).
2. **APIs & Services** → **Credentials** → **Configure Consent Screen** → **Get started**.
3. Nom de l'app (`HeimdEye`) i correu de suport. **Audience**: **External** (qualsevol compte de
   Google; Access ja filtrarà qui entra). Correu de contacte, accepta i **Create**.
4. **Create OAuth client** → **Application type**: **Web application**.
5. **Authorized JavaScript origins**: `https://<team>.cloudflareaccess.com`
6. **Authorized redirect URIs**: `https://<team>.cloudflareaccess.com/cdn-cgi/access/callback`
7. Crea'l i copia el **Client ID** i el **Client secret** (el secret és una contrasenya: no el
   desis enlloc més).

Si el projecte queda en mode de proves (*Testing*), Google només deixa entrar els usuaris de
prova que hi hagis posat. Per a tothom, publica l'app (**Audience** → **Publish app**); per a
l'inici de sessió amb nom i correu no cal la verificació de Google.

### 4b. A Cloudflare

1. **Zero Trust** → **Integrations** → **Identity providers** → **Add new identity provider** → **Google**.
2. **App ID**: el Client ID. **Client Secret**: el secret. **Save**.
3. Al costat de Google, **Test**: ha d'acabar amb el teu correu.

Amb Google Workspace els passos són semblants, però cal activar l'API **Admin SDK**, l'audiència
és **Internal** i a la consola d'administració de Google cal confiar en les apps internes.
Vegeu [Google Workspace](https://developers.cloudflare.com/cloudflare-one/integrations/identity-providers/google-workspace/).

## 5. Protegir HeimdEye amb Access (producció)

Documentació: [Publish a self-hosted application](https://developers.cloudflare.com/cloudflare-one/access-controls/applications/http-apps/self-hosted-public-app/)
i [Access policies](https://developers.cloudflare.com/cloudflare-one/access-controls/policies/).

1. **Zero Trust** → **Access controls** → **Applications** → **Create new application** →
   **Self-hosted and private**.
2. **Add public hostname**: el mateix subdomini i domini del túnel.
3. **Access policies**: crea'n una de nova:
   - Nom: `Institut`. Acció: **Allow**.
   - Include: **Emails ending in** `@institut.cat` (el domini dels correus de professors i
     alumnes), o **Emails** amb la llista de correus si no tothom té el mateix domini.
4. A l'autenticació, deixa només **Google** (i, si vols, **Apply instant authentication** perquè
   no surti la pantalla de triar mètode).
5. **Session Duration**: 24 hores és raonable.
6. **Create**.

### El leaderboard, públic

`/leaderboard` ha de ser visible sense entrar. Crea una **segona** aplicació igual, amb el mateix
hostname i el camí (*path*) `leaderboard`, i una política:

- Nom: `Públic`. Acció: **Bypass**. Include: **Everyone**.

Access aplica l'aplicació amb el camí més concret ([Application paths](https://developers.cloudflare.com/cloudflare-one/access-controls/policies/app-paths/)),
així que la resta de l'app continua protegida. La pàgina del leaderboard no carrega fitxers
d'altres camins, així que no cal obrir res més.
Bypass no fa cap control ni deixa registre: fes-lo servir només per a aquest camí.

### Les dades per a HeimdEye

A **Access controls** → **Applications** → la teva aplicació → **Configure** → **Additional
settings**, copia l'**Application Audience (AUD) Tag**. És `CF_ACCESS_AUD`.

```dotenv
HEIMDEYE_AUTH=cloudflare
CF_ACCESS_TEAM_DOMAIN=<team>.cloudflareaccess.com
CF_ACCESS_AUD=<AUD tag>
APP_URL=https://heimdeye.exemple.cat
SESSION_SECURE_COOKIE=true
HEIMDEYE_TRUSTED_PROXIES=<IP de cloudflared>   # 127.0.0.1 a la mateixa màquina; 172.16.0.0/12 dins Docker
```

HeimdEye valida el JWT de la capçalera `Cf-Access-Jwt-Assertion` amb les claus públiques de
`https://<team>.cloudflareaccess.com/cdn-cgi/access/certs` i comprova que l'audiència és la de
l'aplicació. Després busca l'usuari pel correu: **cal donar-lo d'alta a HeimdEye abans** (Usuaris i
rols, o la importació d'alumnes). L'administrador inicial és el de `HEIMDEYE_ADMIN_EMAIL`.

`HEIMDEYE_TRUSTED_PROXIES` fa que Laravel cregui que la connexió és HTTPS (capçalera
`X-Forwarded-Proto`) només si ve del `cloudflared`. Si no hi és, els enllaços surten amb `http://`.

## 6. Protegir la demo (sense Access)

La demo és oberta a tothom, així que no té Access. Les proteccions són a HeimdEye (mode demo:
visitants amb permisos limitats i límits d'execució) i al WAF de Cloudflare. Documentació:
[Create a custom rule](https://developers.cloudflare.com/waf/custom-rules/create-dashboard/) i
[Rate limiting rules](https://developers.cloudflare.com/waf/rate-limiting-rules/create-zone-dashboard/).

Al tauler, tria el domini → **Security rules** → **Create rule**:

1. **Custom rules** — «Demo: repte a l'entrada»:
   - When incoming requests match: **Hostname** equals `demo-heimdeye.exemple.cat` **and** **URI Path**
     starts with `/admin/login`.
   - Then take action: **Managed Challenge**. **Deploy**.

   Qui entra a la demo ha de passar un repte (sovint invisible) que atura els robots.
2. **Rate limiting rules** — «Demo: límit per IP»: al pla Free només n'hi ha una, compta per IP
   en períodes de 10 s, el bloqueig dura 10 s i l'expressió només pot fer servir el camí (*URI
   Path*). Si el domini també té producció, posa un camí que només tingui la demo o deixa-la per
   a tot el domini amb un llindar alt (100 peticions cada 10 s). Acció: **Block**.

Comprova a la pàgina **Analytics** del domini, pestanya **Events**, que les regles salten quan
toca ([Security Events](https://developers.cloudflare.com/waf/analytics/security-events/)).

## Comprovacions i problemes habituals

| Què veus | Causa probable |
| --- | --- |
| Error 1033 de Cloudflare | El túnel no està connectat: mira els registres de `cloudflared`. |
| Error 502 | El túnel funciona però no arriba a HeimdEye: revisa la **Service URL** i el tallafoc del servidor. |
| 401 «Cal entrar a través de Cloudflare Access» | La petició no porta JWT: falta l'aplicació d'Access o entres per IP. |
| 401 «El token de Cloudflare Access no és vàlid» | `CF_ACCESS_TEAM_DOMAIN` o `CF_ACCESS_AUD` no coincideixen amb l'aplicació. |
| 403 «L'usuari … no té accés» | Ha passat Access però no és a la taula d'usuaris de HeimdEye, o està desactivat. |
| «Page expired» (419) al login | `SESSION_SECURE_COOKIE=true` però entres per `http://`. |
| Google diu «Access blocked» | L'app de Google és en mode *Testing* i l'usuari no és de prova. |
