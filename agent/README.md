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

El CI publica els binaris; l'alumne no cal que els compili.

## Fer-lo servir

La comanda exacta, amb l'URL i el token ja posats, surt a la pàgina de l'alumne a HeimdEye.

```sh
# Prova ràpida (en primer pla):
./heimdeye-agent run --url https://heimdeye.exemple.cat --token XXXX --hostname web

# Com a servei de systemd (arrenca sol; cal ser root):
sudo ./heimdeye-agent install --url https://heimdeye.exemple.cat --token XXXX --hostname web
```

Si l'alumne té més d'una instància, cadascuna es registra amb el seu `--hostname` (web, bd, dns...),
que es correspon amb el rol de `# hosts:` de les accions.

## Notes

- De moment només Linux. El codi és portable; es pot compilar per a altres sistemes sense canvis.
- L'agent és transparent: no amaga res ni esborra rastre. La feina que executa queda registrada a
  HeimdEye.
- Cap secret de HeimdEye no arriba mai a la instància: les accions i els checks d'agent no poden
  fer servir secrets en clar.
