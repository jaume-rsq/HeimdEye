<?php

/*
 * Info per a l'alumne (camp checks.info, Markdown): objectiu del test i justificació.
 * Indexat per número de bloc i nom del check. Ho fan servir els seeders dels blocs i la
 * migració que omple els checks que ja hi ha a la base de dades.
 */

$info = fn (string $objectiu, string $justificacio): string => "**Objectiu del test:** {$objectiu}\n\n**Justificació:** {$justificacio}\n";

return [
    5 => [
        'HeimdEye entra al router (Linux o MikroTik)' => $info(
            'comprovar que HeimdEye pot entrar al router per SSH amb la clau del professor i saber quin dels dos routers (Linux o MikroTik) està engegat ara.',
            'tots els altres checks del bloc es fan des d\'aquí. Administrar un equip de xarxa amb clau, sense contrasenya i sense obrir-lo a tothom és la manera habitual de treballar en un entorn real. Només pot haver-hi un router engegat (tots dos fan servir la mateixa IP), i per això els checks específics de l\'altre sistema surten com a omesos, no com a error.',
        ),
        'Política per defecte DROP al forward' => $info(
            'validar que el router descarta tot el trànsit que travessa entre xarxes si cap regla no el permet explícitament.',
            'és el principi de «tot el que no està permès està prohibit». Amb una política ACCEPT, qualsevol servei que s\'engegui a la LAN o a la DMZ quedaria exposat sense que ningú ho decidís. Amb DROP, cada flux permès és una regla pensada i documentada.',
        ),
        'Filtratge amb estat (established, related)' => $info(
            'comprovar que el tallafocs deixa passar les respostes de les connexions que ja s\'han acceptat.',
            'amb DROP per defecte, sense aquesta regla les respostes no tornarien i caldria obrir els ports en tots dos sentits. El filtratge amb estat només obre el camí de tornada de connexions iniciades legítimament, i és més segur i més senzill de mantenir.',
        ),
        'La LAN surt a la WAN i a la DMZ' => $info(
            'validar des d\'un equip real de la LAN (ns2) que el tallafocs deixa sortir la LAN cap a la WAN i cap als serveis de la DMZ.',
            'un tallafocs massa tancat també és un error: els usuaris de la LAN han de poder fer servir Internet i els servidors de l\'empresa. Mirar les regles no n\'hi ha prou, i per això el test obre connexions de veritat, com faria un usuari.',
        ),
        'Servei de la DMZ publicat amb DNAT' => $info(
            'comprovar que el port 80 de l\'adreça WAN del router es redirigeix (DNAT) cap a un servidor de la DMZ i que s\'hi arriba des de fora.',
            'els serveis públics (web, correu...) es posen a la DMZ i es publiquen a través del router, que és l\'únic equip amb adreça pública. Així el servidor no queda exposat directament i, si el comprometen, no és a la LAN.',
        ),
        'Només el port publicat és obert des de la WAN' => $info(
            'comprovar que des de fora no responen ports d\'administració o de serveis interns (FTP, Telnet, SMB, RDP, Winbox) a l\'adreça del router.',
            'cada port obert és una porta d\'entrada. Molts atacs comencen escanejant serveis que han quedat oberts de fàbrica, com el Winbox del MikroTik. Des de la WAN només ha de respondre el que s\'ha publicat a propòsit, a més de l\'SSH que fa servir HeimdEye.',
        ),
        'Les regles es recuperen en arrencar' => $info(
            'validar que les regles d\'iptables es carreguen soles quan el router Linux arrenca.',
            'les regles d\'iptables viuen a la memòria i es perden en reiniciar. Un tallafocs que queda obert després d\'un tall de llum o d\'una actualització és un forat de seguretat que ningú no veu fins que és tard.',
        ),
        'Còpies de la configuració en binari i en text' => $info(
            'comprovar que el MikroTik té una còpia binària (.backup) i una exportació en text (.rsc) de la configuració.',
            'la còpia binària restaura el mateix equip tal com estava, i l\'exportació en text es pot llegir, comparar entre versions i aplicar a un altre router. Fer còpies abans de cada canvi important és el que permet tornar enrere quan alguna cosa falla.',
        ),
        'Llicència trial del CHR' => $info(
            'comprovar que el CHR no funciona amb la llicència gratuïta (free).',
            'la llicència free del CHR limita cada interfície a 1 Mbps, i la xarxa aniria molt lenta. Activar la trial de 60 dies és part de posar en marxa un equip virtual de manera correcta, com ho seria registrar-ne una de pagament en una empresa.',
        ),
        'RouterOS actualitzat' => $info(
            'validar que RouterOS és a la darrera versió del seu canal.',
            'el router és la frontera de la xarxa i un dels equips més atacats. Moltes vulnerabilitats de RouterOS s\'han aprofitat massivament en equips sense actualitzar. Mantenir-lo al dia és la mesura de seguretat més barata que hi ha.',
        ),
        'VLANs al MikroTik (router-on-a-stick)' => $info(
            'comprovar que el MikroTik té almenys dues VLAN, cadascuna amb una adreça de la LAN que fa de porta d\'enllaç.',
            'les VLAN separen xarxes (departaments, servidors, convidats) sobre el mateix cablejat, i el router les uneix i les filtra des d\'una sola interfície (router-on-a-stick). És com es segmenta una xarxa d\'empresa sense multiplicar commutadors ni cables.',
        ),
        'Bridge VLAN-aware a Proxmox amb dues VLANs' => $info(
            'validar que el Proxmox té un bridge amb VLAN i que les màquines fan servir almenys dues VLAN diferents.',
            'perquè les VLAN del router serveixin, el commutador virtual del Proxmox ha de transportar les etiquetes i posar cada màquina a la seva VLAN. És el mateix que configurar els ports d\'un commutador gestionable en una xarxa física.',
        ),
    ],
    6 => [
        'SSH a ns1' => $info(
            'comprovar que HeimdEye entra a ns1 per SSH com a jaume, amb clau i sudo sense contrasenya.',
            'la major part dels checks del bloc consulten el DNS des del mateix ns1, perquè el tallafocs (amb raó) no deixa HeimdEye arribar a la DMZ pel port 53. Sense aquest accés no es pot verificar res més de ns1.',
        ),
        'WinRM a ns2' => $info(
            'comprovar que HeimdEye entra a ns2 (Windows Server) per WinRM com a jaume.',
            'WinRM és l\'equivalent de l\'SSH a Windows i és el que fan servir eines com Ansible o PowerShell remot per administrar servidors sense entrar-hi per escriptori remot. Els checks de la LAN i del secundari es fan des de ns2.',
        ),
        'Bind en marxa a ns1' => $info(
            'validar que el servei Bind està actiu a ns1.',
            'és el requisit mínim: si el servei està aturat, cap nom de la zona es resol, i tots els equips que en depenen deixen de funcionar encara que la configuració sigui correcta.',
        ),
        'Configuració i zona vàlides' => $info(
            'comprovar que named-checkconf no troba errors i que la zona es carrega amb el seu serial.',
            'un error de sintaxi pot fer que Bind no arrenqui o que descarti la zona sense avisar gaire. Validar la configuració abans de recarregar el servei és una pràctica bàsica per no deixar la xarxa sense DNS.',
        ),
        'ns1 és autoritatiu de la zona' => $info(
            'validar que ns1 respon el SOA de la zona amb el flag aa (resposta autoritativa).',
            'un servidor autoritatiu és la font oficial dels noms d\'una zona. Si respon sense el flag aa, és que no té la zona carregada i només fa de memòria cau, i la resta d\'Internet no s\'hi podria refiar.',
        ),
        'Registres NS i A de ns1 i ns2' => $info(
            'comprovar que la zona declara ns1 com a servidor de noms i que ns1 i ns2 tenen el seu registre A amb l\'adreça correcta.',
            'els registres NS diuen quins servidors tenen la zona, i els A de ns1 i ns2 permeten trobar-los. Si no quadren amb la delegació del central, les consultes es perden o donen resultats incoherents.',
        ),
        'Àlies (CNAME)' => $info(
            'validar que www de la zona és un àlies (CNAME) d\'un nom real.',
            'els àlies permeten publicar un servei amb un nom estable (www) i canviar l\'equip que el dona tocant un sol registre. Saber quan fer servir un CNAME i quan un A és part de dissenyar bé una zona.',
        ),
        'Correu (MX)' => $info(
            'comprovar que la zona té un registre MX i que el servidor de correu té un registre A, no un CNAME.',
            'el registre MX diu on s\'ha de lliurar el correu del domini. L\'estàndard prohibeix que l\'MX apunti a un àlies, i molts servidors de correu rebutgen o retarden el correu si ho fa.',
        ),
        'El central delega la zona a ns1 amb glue' => $info(
            'validar que el DNS central (smix2) delega la vostra zona a ns1 i en dona l\'adreça (registre glue).',
            'la delegació és el que fa que la zona existeixi per a la resta del món: el DNS pare diu qui té la zona. Com que ns1 és dins de la mateixa zona, sense el glue ningú no podria trobar-ne l\'adreça.',
        ),
        'El central resol la zona fins a ns1' => $info(
            'comprovar que una consulta al central arriba fins al vostre ns1 i torna la resposta amb el SOA de la vostra zona.',
            'una delegació ben configurada no serveix de res si el tallafocs, el servei o allow-query no deixen arribar les consultes. Aquest test prova tot el camí, com ho faria un client qualsevol de l\'aula.',
        ),
        'ns1 resol noms externs per a la LAN' => $info(
            'validar que ns1 resol noms d\'Internet quan li pregunta un equip de la LAN.',
            'a més de ser autoritatiu de la zona, ns1 fa de DNS dels equips interns. Si no resol noms externs, la LAN no pot navegar encara que la xarxa funcioni.',
        ),
        'Recursió limitada a la LAN' => $info(
            'comprovar que allow-recursion inclou la LAN i no és any ni localnets.',
            'un DNS recursiu obert a tothom es fa servir en atacs d\'amplificació i facilita l\'enverinament de la memòria cau. La recursió només s\'ha d\'oferir als clients propis. localnets també inclou la DMZ, que no n\'ha de fer ús.',
        ),
        'Forwarders cap al central' => $info(
            'validar que ns1 reenvia les consultes externes al DNS central.',
            'reenviar al DNS de l\'organització aprofita la seva memòria cau i les seves polítiques, i és habitual quan la sortida a Internet està controlada. Les consultes van més de pressa i n\'hi ha menys cap a fora.',
        ),
        'Zona secundària a ns2 amb ns1 com a mestre' => $info(
            'comprovar que ns2 té la zona com a secundària i que el seu mestre és ns1.',
            'un sol servidor DNS és un punt únic de fallada. Amb un secundari que copia la zona del mestre, els noms continuen resolent-se si ns1 cau, i els canvis només es fan en un lloc.',
        ),
        'ns2 té el mateix serial que ns1' => $info(
            'validar que ns2 respon com a autoritatiu i que el serial del SOA és el mateix als dos servidors.',
            'el serial és el que fa saber al secundari que hi ha canvis. Si no augmenta o la transferència falla, ns2 dona dades antigues i els clients reben respostes diferents segons el servidor que consultin.',
        ),
        'Transferència i NOTIFY només cap a ns2' => $info(
            'comprovar que només ns2 pot demanar la zona sencera (AXFR) i que rep els avisos de canvi (NOTIFY).',
            'una transferència oberta permet a qualsevol descarregar tota la zona i conèixer tots els equips de la xarxa, que és una informació molt útil per preparar un atac. NOTIFY fa que el secundari s\'actualitzi de seguida.',
        ),
        'El central delega també a ns2' => $info(
            'validar que la delegació del central inclou ns2 amb la seva adreça.',
            'el secundari només aporta redundància si els clients en saben l\'existència. Si el pare només delega a ns1, quan ns1 cau la zona desapareix encara que ns2 estigui bé.',
        ),
        'El DNS de la DMZ no és accessible des de fora' => $info(
            'comprovar que ns1 funciona però que només el DNS central hi arriba des de fora, no qualsevol equip.',
            'exposar el DNS només als equips que l\'han de fer servir redueix la superfície d\'atac. És l\'aplicació pràctica del principi de mínim privilegi al tallafocs del Bloc 5.',
        ),
        'Política DROP al forward del router' => $info(
            'validar que la darrera regla del forward del router descarta tot el que no s\'ha permès abans.',
            'amb un tallafocs pla, qualsevol servei nou de la DMZ queda exposat. Afegir el DNS a un tallafocs amb DROP per defecte obliga a decidir quins fluxos calen, que és com es treballa en una xarxa real.',
        ),
    ],
];
