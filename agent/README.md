# heimdeye-agent

Agent que l'alumne instal·la a la seva instància (p. ex. una VM d'AWS Learner Lab) perquè
HeimdEye hi pugui executar accions i checks encara que la instància sigui efímera i canviï d'IP.

## Com funciona

Model **pull**: l'agent surt cap a HeimdEye i pregunta si té feina (*long polling*). HeimdEye no
inicia mai cap connexió cap a la instància, de manera que no cal obrir cap port ni conèixer-ne la IP.

1. En arrencar, l'agent es **registra** amb el token de l'alumne i un *hostname* que identifica la
   instància (i fa de rol per a la capçalera `# hosts:` de les accions).
2. Manté una petició de **poll** oberta; quan el professor llança una acció o un check, HeimdEye la
   respon de seguida.
3. L'agent executa el codi (bash) **en memòria**, com a root, i en retorna el codi de sortida, la
   sortida i la durada. El resultat queda a HeimdEye. L'agent no distingeix accions de checks: cada
   feina porta un identificador opac que torna tal qual, i és HeimdEye qui l'encamina.

També pot obrir una **consola interactiva**: quan el `poll` retorna una sessió de consola, l'agent
obre un bash persistent i el connecta amb HeimdEye pel mateix canal de sortida (rep l'entrada que
escriu el professor i hi envia la sortida). Cap port a la instància; tot surt cap a HeimdEye i queda
registrat.

Tota la comunicació va per HTTPS. El token autentica l'alumne (capçalera `Authorization: Bearer`).

## Compilar

Un sol binari estàtic, sense dependències (només la biblioteca estàndard de Go):

```sh
cd agent
GOOS=linux GOARCH=amd64 go build -o heimdeye-agent .   # o arm64
```

El workflow `.github/workflows/release-agent.yml` compila l'agent (linux amd64 i arm64) i en
publica els binaris a la release **`latest`** cada cop que canvia `agent/**` a `main` (o a mà, amb
*Run workflow*). L'alumne no cal que el compili: se'l baixa d'allà.

## Fer-lo servir

La comanda exacta, amb l'URL i el token ja posats, surt a la fitxa de l'alumne a HeimdEye. Baixa el
binari de la release, el fa executable i l'instal·la com a servei:

```sh
ARCH=$(uname -m); case "$ARCH" in x86_64) A=amd64;; aarch64|arm64) A=arm64;; esac
curl -fsSL -o heimdeye-agent https://github.com/jaume-rsq/HeimdEye/releases/download/latest/heimdeye-agent-linux-$A
chmod +x heimdeye-agent

# Com a servei de systemd (arrenca sol; cal ser root):
sudo ./heimdeye-agent install --url https://heimdeye.exemple.cat --token XXXX --hostname web

# O una prova ràpida en primer pla, sense instal·lar res:
./heimdeye-agent run --url https://heimdeye.exemple.cat --token XXXX --hostname web
```

Si l'alumne té més d'una instància, cadascuna es registra amb el seu `--hostname` (web, bd, dns...),
que es correspon amb el rol de `# hosts:` de les accions.

### Sota quin usuari corre (WSL + AWS CLI)

Per defecte el servei corre sota **l'usuari que ha llançat el `sudo`** (el del `whoami`), de manera
que n'hereta l'entorn i les credencials. Això és útil, per exemple, en una **WSL** on l'alumne té
configurat l'**AWS CLI**: el codi que enviem pot cridar `aws ec2 ...`, `aws s3 ...`, etc. amb les
seves pròpies credencials (perfil del Learner Lab), sense que cap credencial d'AWS passi per HeimdEye.
Així la comanda copiada de la fitxa no s'ha d'editar.

- `--user <usuari>`: força un altre usuari del sistema.
- `--user root`: força que corri com a root (p. ex. en una instància on cal root per a les accions).

Les credencials del Learner Lab són temporals: l'agent ha de treballar mentre la sessió d'AWS és
activa; si han caducat, les crides `aws` fallen.

### Variables de l'alumne al codi

El codi (acció o check) rep les variables de l'alumne com a variables d'entorn `HE_*`: entre altres,
`HE_USUARI`, `HE_NOM_COMPLET`, `HE_NUM_TAULA` (dos dígits) i `HE_X`, a més de les variables de mòdul.
Serveixen per personalitzar l'script, p. ex. filtrar els recursos d'AWS per una etiqueta de taula:

```sh
aws ec2 describe-instances --filters "Name=tag:taula,Values=$HE_NUM_TAULA"
```

## Notes

- De moment només Linux. El codi és portable; es pot compilar per a altres sistemes sense canvis.
  WSL2 és Linux de debò, així que l'agent hi corre igual.
- L'agent és transparent: no amaga res ni esborra rastre. La feina que executa queda registrada a
  HeimdEye.
- Cap secret de HeimdEye no arriba mai a la instància: les accions i els checks d'agent no poden
  fer servir secrets en clar.
