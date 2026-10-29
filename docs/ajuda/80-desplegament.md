---
titol: Desplegament
ordre: 80
per: [professors]
actualitzat: 2026-10-29
---

# Desplegament

Com posar HeimdEye en marxa per a la teva aula. Les guies completes són al repositori:
[produccio.md](https://github.com/jaume-rsq/HeimdEye/blob/main/docs/produccio.md) (servidor amb
desplegament continu), [cloudflare.md](https://github.com/jaume-rsq/HeimdEye/blob/main/docs/cloudflare.md)
(publicar-lo amb login de Google) i [demo.md](https://github.com/jaume-rsq/HeimdEye/blob/main/docs/demo.md)
(una demo pública). Aquí hi ha el resum i el que cal decidir.

## On posar-lo

HeimdEye entra als equips dels alumnes amb una clau que hi té `sudo`. La màquina on corre és la
més delicada de l'aula.

- **Una VM pròpia**, o compartida només amb serveis de confiança (un Zabbix). Mai la d'un alumne.
- Ha d'**arribar a les xarxes dels alumnes** (ping, SSH al 22, WinRM al 5986) i a Internet (GitHub
  i Cloudflare).
- **Cap port obert des de fora**: s'hi entra pel túnel de Cloudflare.
- 2 vCPU, 4 GB de RAM i 20 GB de disc per a una o dues classes.

## Dues maneres

| | Servidor (systemd) | Docker Compose |
| --- | --- | --- |
| Què és | Paquets d'Ubuntu (PHP, Apache, PostgreSQL) i serveis de systemd. | Una imatge amb tot (web, workers, nocturna i PostgreSQL). |
| Actualitzacions | Soles: cada commit de `main` amb els tests en verd es desplega en 5 minuts. | `git pull` i `docker compose up -d --build`. |
| Per a | La instància de l'institut. | Provar-lo, o una màquina on només hi ha Docker. La demo. |

### Servidor

```bash
git clone https://github.com/jaume-rsq/HeimdEye.git /tmp/HeimdEye
sudo /tmp/HeimdEye/deploy/install.sh                     # paquets, usuari heimdeye, serveis
sudo -u heimdeye nano /var/www/heimdeye/shared/.env      # APP_KEY, BBDD, Cloudflare, administrador
sudo -u heimdeye -H env SKIP_CI=1 /tmp/HeimdEye/deploy/deploy.sh
sudo systemctl enable --now heimdeye-queue@{1..4} heimdeye-schedule.timer heimdeye-deploy.timer
```

El detall (base de dades, `.env`, Apache) és a produccio.md.

### Docker

```bash
cd docker
cp env.example .env       # APP_KEY, DB_PASSWORD, HEIMDEYE_ADMIN_*
docker compose up -d --build
```

## Què fa córrer HeimdEye

| Peça | Què fa |
| --- | --- |
| **Web** | El panell i el leaderboard. |
| **Workers de la cua** | Executen els checks i les accions, un alumne cada vegada. Sense workers, res no s'executa: el panell ho avisa a dalt. |
| **Ronda nocturna** | Cada nit (per defecte a les 02:00) posa tots els checks actius a la cua. |
| **Desplegament** | Només al servidor: cada 5 minuts mira si hi ha una versió nova de `main` amb els tests en verd. |

## Després d'instal·lar-lo

1. **Entrar.** L'administrador és el de `HEIMDEYE_ADMIN_EMAIL`. Amb Cloudflare, entra amb el seu
   compte de Google; crea els altres professors a **Administració → Usuaris i rols**.
2. **Curs, cicle, mòduls i blocs** a Administració.
3. **Alumnes**: Inventari → Alumnes → **Importar llista** (la llista de l'aula, enganxada).
4. **La clau de HeimdEye**: genera-la, desa-la en un secret i fes-la l'**accés per defecte** del
   mòdul. Vegeu [Preparar els equips](?doc=preparar-equips).
5. **Els equips dels alumnes**: cada alumne executa l'script de preparació amb la clau pública.
6. **Tipus d'equip i variables** a Inventari, i el botó ▶ per provar la connexió.
7. **El primer check**: un ping amb l'executor `local`. Vegeu [Executors i exemples](?doc=executors).

## Seguretat

- El `.env`, la clau privada i la llista d'alumnes només són al servidor. El repositori és públic.
- Els secrets (Inventari → Secrets) es xifren amb l'`APP_KEY`: sense ella no es poden llegir.
  Guarda'n una còpia fora del servidor.
- Els workers corren amb un usuari sense privilegis, amb el sistema de fitxers de només lectura.
- Fes còpia de la VM cada setmana. La base de dades ja es copia a cada desplegament.
