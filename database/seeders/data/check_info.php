<?php

/*
 * Info per a l'alumne (camp checks.info, Markdown): objectiu del test i justificació.
 * Indexat per número de bloc i nom del check. Ho fan servir els seeders dels blocs i la
 * migració que omple els checks que ja hi ha a la base de dades.
 */

$info = fn (string $objectiu, string $justificacio): string => "**Objectiu del test:** {$objectiu}\n\n**Justificació:** {$justificacio}\n";

return [

    2 => [
        'SSH al servidor Proxmox' => $info(
            'comprovar que HeimdEye pot entrar al teu Proxmox per SSH com a jaume, amb la seva clau.',
            'administrar un servidor per SSH amb clau, sense contrasenya, és la manera habitual de treballar en un entorn real. Tots els altres checks del bloc consulten el Proxmox des d\'aquí: si aquest falla, no es pot verificar res més.',
        ),
        'SSH amb bones pràctiques al Proxmox' => $info(
            'validar que el servei SSH no admet l\'autenticació per contrasenya i que root no pot entrar amb contrasenya.',
            'un SSH exposat amb contrasenyes és l\'objectiu de milers d\'intents automàtics de força bruta cada dia. Amb només claus, un atacant necessita la clau privada, no endevinar una paraula. I root amb contrasenya és el compte que tothom prova primer.',
        ),
        'Hi ha alguna VM definida' => $info(
            'comprovar que el Proxmox té almenys una màquina virtual creada.',
            'el Proxmox no serveix de res sense màquines virtuals: és el mínim per dir que el datacenter ha començat a funcionar. Els checks següents (SPICE, còpies, snapshots) treballen sobre aquestes VM.',
        ),
        'Els bridges vmbr0, vmbr1 i vmbr2 existeixen' => $info(
            'validar que els tres commutadors virtuals (bridges) que fan servir les VM existeixen al host.',
            'els bridges són el «commutador» al qual es connecten les VM. Saber quines xarxes hi ha disponibles (aula, LAN, DMZ) és el primer que cal conèixer d\'un hipervisor, i és el que permet decidir a quina xarxa va cada VM. No es demana que estiguin UP: un bridge sense VM en marxa no té portadora.',
        ),
        'Alguna VM configurada per a SPICE' => $info(
            'comprovar que almenys una VM té l\'adaptador de pantalla qxl, necessari per fer servir SPICE.',
            'SPICE ofereix millor rendiment gràfic, portapapers compartit i redirecció d\'USB, però no funciona amb l\'adaptador per defecte. Configurar-lo és el que permet comparar-lo amb VNC i triar el mètode adequat a cada situació.',
        ),
        'PBS connectat com a emmagatzematge' => $info(
            'validar que el Proxmox té un emmagatzematge de tipus Proxmox Backup Server i que està actiu.',
            'les còpies de seguretat s\'han de guardar fora del servidor que protegeixen: si el disc del Proxmox mor, les còpies hi moririen també. El PBS és un servidor a part, amb deduplicació i verificació, pensat per a això. Un storage inactiu vol dir que el PBS no respon i que les còpies no es poden fer.',
        ),
        'Tasca de còpia programada cap al PBS' => $info(
            'comprovar que hi ha una tasca de còpia activa, amb horari, que desa al PBS.',
            'una còpia que depèn que algú se\'n recordi acaba no fent-se. Una política de còpies és, sobretot, automatitzar-les amb una freqüència pensada per a quantes dades pots permetre\'t perdre (RPO). Per això es demana una tasca amb horari, no còpies manuals.',
        ),
        'Retenció definida per a les còpies' => $info(
            'validar que la tasca o el storage defineixen quantes còpies es guarden (prune-backups).',
            'sense retenció, les còpies creixen fins que s\'omple el disc del PBS i totes les còpies futures fallen. Decidir quantes còpies diàries, setmanals i mensuals es conserven és trobar l\'equilibri entre poder tornar enrere i l\'espai que costa.',
        ),
        'Hi ha còpies de VM al PBS' => $info(
            'comprovar que el PBS conté realment còpies de les teves VM.',
            'una tasca ben configurada no garanteix res fins que s\'ha executat. Una còpia que no existeix no es pot restaurar, i s\'acostuma a descobrir quan ja és massa tard. Mirar què hi ha desat és la primera verificació d\'una política de còpies.',
        ),
        'Còpies també al PBS d\'un company' => $info(
            'validar que tens còpies desades a dos servidors PBS diferents: el de l\'aula i el d\'un company.',
            'la regla 3-2-1 demana almenys una còpia fora de l\'ubicació principal. Un incendi, un robatori o un error d\'administració que afecti un servidor no ha d\'afectar l\'altre. El servidor d\'un company fa de còpia off-site per a tenir còpies disponibles en cas de desastre.',
        ),
        'Alguna VM té snapshots' => $info(
            'comprovar que almenys una VM té un snapshot fet.',
            'els snapshots permeten tornar una VM a un punt anterior en segons, i són ideals abans d\'una actualització o d\'un canvi arriscat. Però viuen al mateix emmagatzematge que la VM: no protegeixen d\'un disc que falla. Per això són un complement de les còpies, no un substitut.',
        ),
    ],

    3 => [
        'HeimdEye entra al router Linux' => $info(
            'comprovar que HeimdEye pot entrar al teu router Linux per SSH com a jaume, amb clau i amb sudo sense contrasenya.',
            'tots els altres checks del bloc consulten el router des d\'aquí. Administrar un equip de xarxa per SSH amb clau, sense contrasenya, és el que es fa en un entorn real; el sudo sense contrasenya permet que les eines d\'administració (i HeimdEye) llegeixin la configuració de xarxa sense compartir cap contrasenya.',
        ),
        'El router té tres interfícies a les tres xarxes' => $info(
            'validar que el router Linux té una interfície a l\'aula, una a la LAN i una a la DMZ, cadascuna amb adreça de la seva xarxa.',
            'un router és, per definició, un equip amb una interfície a cada xarxa que uneix. Si falta una interfície o dues comparteixen xarxa, el router no pot separar ni connectar les zones. Tenir la LAN i la DMZ en interfícies diferents és el que després permet filtrar el trànsit entre elles.',
        ),
        'Ruta per defecte cap a l\'aula' => $info(
            'comprovar que el router té una ruta per defecte i que surt per la interfície de l\'aula.',
            'el router només coneix les xarxes on té una interfície. Per a tota la resta (Internet, altres xarxes de l\'aula) necessita una ruta per defecte cap a la seva passarel·la. Sense ella, els equips de la LAN i la DMZ arribarien al router, però el trànsit no passaria d\'allà.',
        ),
        'IP forwarding actiu' => $info(
            'validar que el kernel de Linux reenvia paquets d\'una interfície a una altra (net.ipv4.ip_forward = 1).',
            'per defecte, Linux descarta els paquets que no són per a ell: és un equip, no un router. Activar l\'IP forwarding és el que converteix una màquina amb tres targetes en una passarel·la. Sense això, tota la resta de configuració (adreces, rutes, NAT) no serveix de res.',
        ),
        'IP forwarding persistent' => $info(
            'comprovar que l\'IP forwarding queda configurat als fitxers de sysctl i no només activat a mà.',
            'escriure 1 a /proc/sys/net/ipv4/ip_forward funciona fins al primer reinici. Un router que deixa de reenviar després d\'un tall de llum o d\'una actualització és una avaria difícil de diagnosticar. La configuració ha de sobreviure en arrencar.',
        ),
        'El router encamina entre la LAN i la DMZ' => $info(
            'validar que el router sap encaminar un paquet de la LAN cap a la DMZ i viceversa, per interfícies diferents.',
            'és la prova que el router funciona com a tal: rep un paquet per una interfície i el treu per una altra segons la taula d\'encaminament. Es comprova preguntant al kernel pel camí que seguiria el paquet, que és el que diagnostica un administrador quan un ping entre xarxes no arriba.',
        ),
        'NAT de sortida (masquerade o SNAT)' => $info(
            'comprovar que el router té una regla de NAT de sortida que substitueix l\'adreça privada de l\'origen per la seva.',
            'les adreces privades de la LAN i la DMZ no són encaminables fora de la xarxa: la resta d\'equips no sabria tornar-hi la resposta. El NAT de sortida fa que tot el trànsit surti amb l\'adreça del router, que sí que és vàlida a l\'aula. És com surten a Internet totes les xarxes domèstiques i moltes d\'empresa.',
        ),
        'El NAT de sortida ha tractat trànsit' => $info(
            'comprovar que la regla de NAT de sortida ja ha traduït paquets, és a dir, que algú n\'ha fet servir de veritat.',
            'una regla escrita no demostra que funcioni. Mirar els comptadors és la manera ràpida de saber si el trànsit passa per ella, i el que fa un administrador quan no està segur que una regla s\'està aplicant. Només es pot comprovar amb iptables, que és on hi ha els comptadors.',
        ),
        'Regla DNAT cap a la xarxa privada' => $info(
            'validar que el router té una regla de NAT de destinació que reenvia un port seu cap a una màquina de la LAN o de la DMZ.',
            'els equips de la xarxa privada no són accessibles des de fora. Per publicar un servei (una web, un SSH) sense exposar la màquina, el router rep les connexions en un port seu i les redirigeix a l\'equip intern. És el port forwarding que es fa a qualsevol router d\'internet.',
        ),
        'El servei publicat respon des de l\'aula' => $info(
            'comprovar que, des de la xarxa de l\'aula, el port publicat amb DNAT respon de debò.',
            'tenir la regla no vol dir que el servei sigui accessible: pot faltar el servei, la ruta de tornada o el forwarding. Provar la connexió real des de fora, com ho faria un usuari, és l\'única verificació fiable d\'un servei publicat.',
        ),
    ],

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
        'Regles de la LAN cap a la WAN i la DMZ' => $info(
            'comprovar que, al FORWARD, hi ha regles explícites que deixen sortir la LAN cap a la xarxa de l\'aula i cap a la DMZ, a més de la regla de les respostes.',
            'amb la política per defecte a DROP, cada flux permès és una decisió escrita. Si la LAN no té regla de sortida, no podria arribar ni a Internet ni als servidors de la DMZ. Mirar la regla, i no només provar que un equip surt, permet verificar-ho sense dependre de tenir més màquines engegades.',
        ),
        'La DMZ no té regles obertes cap a la LAN' => $info(
            'validar que cap regla del FORWARD deixa iniciar connexions des de la DMZ cap a la LAN, i que el que no està permès es descarta.',
            'la DMZ allotja els serveis exposats a l\'exterior, que són els que més fàcilment es poden comprometre. Si un atacant guanya un servidor de la DMZ i aquesta pot iniciar connexions cap a la LAN, la segmentació no serveix de res. És la raó de ser d\'una DMZ: contenir el dany.',
        ),
        'La DMZ no arriba a la LAN (prova des de ns1)' => $info(
            'comprovar, amb una connexió real des de ns1 (a la DMZ), que els ports d\'administració de ns2 (a la LAN) no responen.',
            'llegir regles pot enganyar (una regla que ve abans, una excepció oblidada). La prova real és la que faria un atacant dins de la DMZ: provar d\'arribar a la LAN. Si la política és correcta, la connexió no ha d\'establir-se.',
        ),
        'Les regles del forward han tractat trànsit' => $info(
            'validar que alguna regla del FORWARD ja ha vist paquets, i mostrar quants n\'ha acceptat i quants n\'ha descartat.',
            'una regla escrita no demostra que s\'apliqui. Els comptadors són l\'evidència que el trànsit passa pel tallafocs i que la política es compleix en els dos sentits. És el que s\'ensenya en una auditoria i el que s\'utilitza per saber si una regla es pot esborrar.',
        ),
        'El seguiment de connexions (conntrack) és actiu' => $info(
            'comprovar que el kernel fa seguiment de connexions i en té alguna de registrada.',
            'el filtratge amb estat (ESTABLISHED,RELATED) i el NAT depenen de conntrack: és la taula on el kernel recorda quines connexions s\'han permès, perquè la resposta torni sense obrir el camí invers. Sense seguiment, caldria una regla per a cada sentit de cada servei.',
        ),
        'Les regles desades coincideixen amb les actives' => $info(
            'validar que el fitxer de regles desades té les mateixes regles que les que estan actives ara.',
            'les regles d\'iptables viuen a la memòria. Si el fitxer desat és d\'abans dels últims canvis, en reiniciar el router es perd part de la política, i es pot quedar sense DNAT o amb una DMZ oberta sense que ningú se n\'adoni. Desar després de cada canvi és part de fer-lo.',
        ),
    ],

    6 => [
        'SSH a ns1' => $info(
            'comprovar que HeimdEye entra a ns1 per SSH com a jaume, amb clau i sudo sense contrasenya.',
            'la major part dels checks del bloc consulten el DNS des del mateix ns1, perquè el tallafocs (amb raó) no deixa HeimdEye arribar a la DMZ pel port 53. Sense aquest accés no es pot verificar res més de ns1.',
        ),
        'WinRM a ns2' => $info(
            'comprovar que HeimdEye entra a ns2 (Windows Server) per WinRM amb l\'usuari del secret winrm_windows.',
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
    8 => [
        'VLANs i gateways al router' => $info(
            'comprovar que el router té les cinc VLAN de l\'empresa i que a cada una hi ha el gateway (.1/24).',
            'les VLAN separen servidors, usuaris, convidats i proves sobre la mateixa infraestructura, i el router és qui les uneix i les filtra. Sense una interfície amb adreça a cada VLAN, els equips no tenen sortida ni hi pot arribar cap relay DHCP.',
        ),
        'ns1 a la VLAN 200 arriba al gateway' => $info(
            'validar que ns1 té la seva adreça fixa /24 a la VLAN 200 i que arriba al seu gateway.',
            'els servidors tenen IP fixa perquè els altres equips els han de trobar sempre al mateix lloc, i ara ns1 també és el servidor DHCP: si no té xarxa, cap client de cap VLAN rep adreça.',
        ),
        'ns2 a la VLAN 100 arriba al gateway' => $info(
            'validar que ns2 té la seva adreça fixa /24 a la VLAN 100 i que arriba al seu gateway.',
            'en canviar de VLAN, un servidor amb la màscara o el gateway antics deixa de respondre fora de la seva xarxa. Comprovar el gateway és el primer pas de qualsevol diagnosi de xarxa.',
        ),
        'Etiquetes VLAN de les VM a Proxmox' => $info(
            'comprovar que les targetes de les VM de Proxmox porten l\'etiqueta (tag) de la seva VLAN.',
            'l\'etiqueta és el que posa cada màquina a la seva VLAN, com configurar el port d\'un commutador gestionable. Una VM sense etiqueta, o amb la que no toca, demana adreça a una xarxa equivocada.',
        ),
        'Servidors DHCP del MikroTik: rang i durada' => $info(
            'comprovar que el MikroTik té un servidor DHCP a cada VLAN de clients amb el rang .100-.200 i la durada de la taula.',
            'el rang deixa les adreces baixes per als equips amb reserva, i la durada s\'adapta a cada xarxa: llarga per als usuaris fixos, curta per als convidats que van i vénen i que poden esgotar el pool.',
        ),
        'Opcions de l\'empresa al MikroTik' => $info(
            'validar que el MikroTik reparteix el gateway, els DNS, el domini i el servidor NTP a cada VLAN.',
            'una adreça sense opcions no serveix: sense gateway no hi ha sortida, sense DNS no es resolen noms i sense NTP els rellotges es desajusten (i els certificats i els registres deixen de quadrar). Són els símptomes més habituals d\'un DHCP mal configurat.',
        ),
        'Leases estàtics al MikroTik' => $info(
            'comprovar que el MikroTik té els leases estàtics de la taula de reserves, validada abans d\'aplicar-la.',
            'impressores, servidors i PC d\'ús fix han de tenir sempre la mateixa adreça. Una reserva dins del rang dinàmic o amb una MAC repetida genera conflictes difícils de diagnosticar, i per això la taula es valida abans d\'aplicar-la.',
        ),
        'Cap servidor DHCP a la xarxa de l\'aula' => $info(
            'comprovar que cap servidor DHCP del MikroTik no reparteix adreces a la xarxa de l\'aula.',
            'un servidor DHCP en una xarxa que no és seva és un servidor no autoritzat: dona adreces i gateways falsos als equips dels altres i talla la xarxa de tot l\'institut. És un dels errors més greus que es poden fer amb DHCP.',
        ),
        'Kea en marxa amb la configuració validada' => $info(
            'validar que el servei Kea és en marxa i que la configuració no té errors.',
            'Kea no arrenca si el JSON té un error. Validar la sintaxi abans de reiniciar el servei evita deixar tota la xarxa sense DHCP per una coma.',
        ),
        'Subxarxes de Kea: rang i durada' => $info(
            'comprovar que Kea té les tres subxarxes amb el rang .100-.200 i la durada de la taula.',
            'Kea tria la subxarxa per l\'adreça del relay (giaddr): sense la subxarxa d\'una VLAN, els clients d\'aquella VLAN no reben res. El rang i la durada han de ser els mateixos que al MikroTik perquè el canvi de servidor sigui transparent.',
        ),
        'Opcions de l\'empresa a Kea' => $info(
            'validar que Kea reparteix el gateway, els DNS, el domini i el servidor NTP a cada subxarxa.',
            'a Kea, les opcions es poden posar a nivell global o a cada subxarxa, i saber-les combinar evita repetir configuració. El gateway sempre és propi de cada subxarxa.',
        ),
        'Reserves a Kea' => $info(
            'comprovar que Kea té les reserves de la taula, fora del pool i amb MACs úniques.',
            'les mateixes reserves als tres servidors fan que un canvi de servidor no canviï l\'adreça de cap equip. Kea permet reserves dins del pool, però llavors el mateix servidor pot haver donat aquella adreça a un altre client.',
        ),
        'Relays del MikroTik cap a Kea' => $info(
            'comprovar que el MikroTik reenvia les peticions DHCP de cada VLAN cap a Kea.',
            'el Discover és un broadcast i no surt de la seva VLAN. El relay el converteix en un missatge unicast cap al servidor i hi afegeix l\'adreça de la VLAN (giaddr). Així un sol servidor dona servei a totes les xarxes.',
        ),
        'El MikroTik ja no reparteix adreces' => $info(
            'validar que, amb Kea en marxa, el MikroTik ja no té cap servidor DHCP actiu a les VLAN de clients.',
            'dos servidors a la mateixa VLAN donen ofertes diferents i el client es queda la primera que arriba. El resultat són adreces, gateways o durades incoherents segons l\'equip, una situació molt difícil de diagnosticar.',
        ),
        'Kea dona adreces a les VLAN 101, 102 i 201' => $info(
            'comprovar que clients de les tres VLAN han rebut adreça de Kea a través del relay.',
            'és la prova funcional del bloc: la configuració pot ser correcta i el tallafocs, el relay o les subxarxes poden impedir que arribi cap adreça. Els leases de Kea demostren que tot el camí funciona.',
        ),
        'Un PC rep de Kea la seva IP reservada' => $info(
            'validar que un PC amb reserva ha rebut de Kea l\'adreça reservada, amb la MAC de la reserva.',
            'una reserva només s\'aplica quan el client renova, i una MAC mal escrita fa que el client rebi una adreça qualsevol del pool. Veure el lease és l\'única manera de saber que la reserva funciona.',
        ),
        'Àmbits a Windows Server: rang i durada' => $info(
            'comprovar que ns2 té els tres àmbits amb el rang efectiu .100-.200 i la durada de la taula.',
            'Windows Server organitza el mateix en àmbits, rangs i exclusions. Arribar al mateix rang efectiu amb un rang ajustat o amb un rang ampli i exclusions és un dels conceptes clau del bloc, i també el que cal saber explicar per comparar solucions.',
        ),
        'Opcions de l\'empresa a Windows Server' => $info(
            'validar que ns2 reparteix les opcions 3, 6, 15 i 42 a cada àmbit.',
            'a Windows, les opcions es poden definir al servidor o a l\'àmbit, i les de l\'àmbit manen. Conèixer els codis de les opcions permet configurar qualsevol servidor DHCP, no només una eina concreta.',
        ),
        'Reserves a Windows Server' => $info(
            'comprovar que ns2 té les reserves de la taula, a .10-.99 i amb MACs úniques.',
            'aplicar les mateixes reserves a les tres solucions permet comparar-les de manera justa i fer que el servidor es pugui canviar sense que cap equip fix canviï d\'adreça.',
        ),
    ],
];
