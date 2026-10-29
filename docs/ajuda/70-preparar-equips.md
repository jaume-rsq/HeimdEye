---
titol: Preparar els equips
ordre: 70
per: [professors]
actualitzat: 2026-10-29
---

# Preparar els equips

Perquè HeimdEye pugui entrar als equips dels alumnes, cada equip ha de tenir un usuari per a
HeimdEye amb la seva clau pública. Aquí l'usuari es diu `heimdeye` i la clau és la del secret
`ssh_heimdeye`. Pots fer servir els noms que vulguis, sempre que coincideixin amb l'accés de
l'Inventari.

## 1. Generar la clau (una vegada)

Fes una clau **només per a HeimdEye**, no la teva personal: si un dia l'has de revocar, només
afecta HeimdEye. Al servidor HeimdEye:

```bash
sudo -u heimdeye ssh-keygen -t ed25519 -C heimdeye -N '' -f /var/lib/heimdeye/.ssh/id_ed25519
cd /var/www/heimdeye/current
sudo -u heimdeye php artisan heimdeye:import-key ssh_heimdeye /var/lib/heimdeye/.ssh/id_ed25519 --ruta --usuari=heimdeye
cat /var/lib/heimdeye/.ssh/id_ed25519.pub     # la clau pública, per als scripts d'avall
```

- `ssh-keygen` crea la clau privada (`id_ed25519`) i la pública (`id_ed25519.pub`). `-N ''` vol
  dir sense contrasenya, perquè HeimdEye la fa servir sol a la nit.
- `heimdeye:import-key` crea el secret `ssh_heimdeye` de tipus clau SSH. Amb `--ruta`, la clau es
  queda al fitxer i el secret només en té la ruta.
- Amb Docker, o si prefereixes no fer servir cap fitxer, genera la clau on vulguis i enganxa-la a
  **Inventari → Secrets → Nou secret** (tipus clau SSH, usuari `heimdeye`).

Després, a **Inventari → Equips**, tria `ssh_heimdeye` com a **Accés per defecte** del mòdul.

La clau pública no és secreta: es pot donar als alumnes. La privada no ha de sortir mai del
servidor.

## 2. Linux

L'alumne executa aquest script com a root (`sudo -i`), amb la clau pública de HeimdEye a `PUBKEY`.
Crea l'usuari, li dona `sudo` sense contrasenya (HeimdEye fa `sudo -n`) i hi posa la clau.

```bash
# ===== VARIABLES =====
USUARI='heimdeye'
HOME_DIR=/home/$USUARI
PUBKEY='ssh-ed25519 AAAA...la-clau-publica... heimdeye'
# =====================

# Servidor SSH
apt update && apt install -y sudo openssh-server

# Usuari al grup sudo
id $USUARI &>/dev/null || useradd -m -d $HOME_DIR -s /bin/bash $USUARI
usermod -aG sudo $USUARI

# Clau pública a authorized_keys, amb els permisos bons
install -d -m 700 -o $USUARI -g $USUARI $HOME_DIR/.ssh
echo "$PUBKEY" | install -m 600 -o $USUARI -g $USUARI /dev/stdin $HOME_DIR/.ssh/authorized_keys

# sudo sense contrasenya (es valida abans d'activar-lo)
echo "$USUARI ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/$USUARI
chmod 440 /etc/sudoers.d/$USUARI
visudo -cf /etc/sudoers.d/$USUARI || rm -f /etc/sudoers.d/$USUARI

systemctl enable --now ssh
```

- L'usuari no té contrasenya: només s'hi entra amb la clau.
- `install` crea la carpeta o el fitxer amb el propietari i els permisos en una sola ordre.
- Les cometes simples (`'...'`) eviten que bash interpreti `$` i altres caràcters.
- Convé desactivar l'accés per contrasenya de l'SSH un cop la clau funciona.

Si un check només ha de llegir, pots donar un `sudo` més limitat en lloc de `NOPASSWD:ALL`, però
llavors les accions no podran canviar res.

## 3. MikroTik (RouterOS)

RouterOS no té bash ni Python: els checks hi entren amb `local` + `ssh` o amb `ansible.builtin.raw`.
Puja la clau pública al router (per exemple, `scp id_ed25519.pub admin@router:heimdeye.pub`) i, al
terminal del router:

```
/user add name=heimdeye group=full password="una-contrasenya-llarga"
/user ssh-keys import public-key-file=heimdeye.pub user=heimdeye
```

## 4. Windows

Amb Windows hi ha dues maneres d'entrar, i convé preparar-les totes dues: **SSH** amb la clau (per
a scripts) i **WinRM** amb usuari i contrasenya (per a Ansible). Executa els scripts en una
PowerShell com a administrador. Alguna instal·lació demana reiniciar abans de continuar.

### Usuari administrador i SSH

L'script crea l'usuari, el posa al grup d'administradors pel SID `S-1-5-32-544` (funciona igual
amb Windows en català, castellà o anglès), instal·la OpenSSH i hi posa la clau. Com que l'usuari és
administrador, Windows llegeix la clau de `administrators_authorized_keys`, no del perfil.

```powershell
# ===== VARIABLES =====
$usuari      = 'heimdeye'
$contrasenya = 'CanviaAixo-2026!'
$pubkey      = 'ssh-ed25519 AAAA...la-clau-publica... heimdeye'
# =====================

# Usuari al grup d'administradors
if (-not (Get-LocalUser -Name $usuari -ErrorAction SilentlyContinue)) {
    New-LocalUser -Name $usuari `
        -Password (ConvertTo-SecureString $contrasenya -AsPlainText -Force) `
        -PasswordNeverExpires -AccountNeverExpires
}
Add-LocalGroupMember -SID S-1-5-32-544 -Member $usuari -ErrorAction SilentlyContinue

# Servidor SSH i clau pública dels administradors
Add-WindowsCapability -Online -Name OpenSSH.Server~~~~0.0.1.0
Set-Service -Name sshd -StartupType Automatic
Start-Service sshd
if (-not (Get-NetFirewallRule -Name sshd -ErrorAction SilentlyContinue)) {
    New-NetFirewallRule -Name sshd -DisplayName 'OpenSSH Server (22)' `
        -Enabled True -Direction Inbound -Protocol TCP -Action Allow -LocalPort 22
}
$file = "$env:ProgramData\ssh\administrators_authorized_keys"
Set-Content -Path $file -Value $pubkey -Encoding ascii
icacls $file /inheritance:r /grant '*S-1-5-18:(F)' /grant '*S-1-5-32-544:(F)'
```

La contrasenya ha de complir la política de Windows (majúscules, minúscules, números i un símbol).
Si no, `New-LocalUser` dona error.

### WinRM per a Ansible

Posa la xarxa en perfil privat, activa WinRM i crea un listener HTTPS (port 5986) amb un
certificat autosignat, perquè les credencials viatgin xifrades.

```powershell
Get-NetConnectionProfile | Set-NetConnectionProfile -NetworkCategory Private
Enable-PSRemoting -Force -SkipNetworkProfileCheck
$cert = New-SelfSignedCertificate -DnsName $env:COMPUTERNAME `
    -CertStoreLocation Cert:\LocalMachine\My -NotAfter (Get-Date).AddYears(5)
Get-ChildItem WSMan:\localhost\Listener |
    Where-Object { $_.Keys -contains 'Transport=HTTPS' } |
    Remove-Item -Recurse -Force
New-Item -Path WSMan:\localhost\Listener -Transport HTTPS -Address * `
    -CertificateThumbPrint $cert.Thumbprint -Force
if (-not (Get-NetFirewallRule -Name 'WinRM-HTTPS' -ErrorAction SilentlyContinue)) {
    New-NetFirewallRule -Name 'WinRM-HTTPS' -DisplayName 'WinRM HTTPS 5986' `
        -Direction Inbound -Protocol TCP -LocalPort 5986 -Action Allow
}
# Deixa fer d'administrador als usuaris locals que entren de forma remota
New-ItemProperty -Path HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System `
    -Name LocalAccountTokenFilterPolicy -Value 1 -PropertyType DWord -Force
```

A HeimdEye:

1. **Inventari → Secrets**: un secret d'usuari i contrasenya (per exemple `winrm_windows`) amb
   l'usuari i la contrasenya de l'script.
2. **Inventari → Equips**: al tipus d'equip Windows, aquest secret com a accés i aquestes
   variables de l'equip:

   | Variable | Valor |
   | --- | --- |
   | `ansible_connection` | `winrm` |
   | `ansible_port` | `5986` (HTTPS) |
   | `ansible_winrm_transport` | `ntlm` |
   | `ansible_winrm_server_cert_validation` | `ignore` (el certificat és autosignat) |

3. Prova-ho amb el botó ▶ (fa un `win_ping`).

## 5. Comprovar-ho

A **Inventari → Alumnes**, el botó ▶ prova la connexió: d'un equip, d'una columna (un tipus d'equip
per a tots els alumnes) o de tots els equips d'un alumne. No desa res. Després, un check com el ping
de [Executors i exemples](?doc=executors) ho confirma cada nit.

| Si surt | Mira |
| --- | --- |
| `unavailable` | L'equip és encès? Hi ha ruta des del servidor HeimdEye? El port 22 (o 5986) és obert? |
| `Permission denied (publickey)` | La clau pública és a `authorized_keys` de l'usuari bo, amb permisos 600 i la carpeta 700? |
| `sudo: a password is required` | Falta el fitxer de `/etc/sudoers.d`. |
| `error` sense connectar | Falta l'accés al tipus d'equip o al mòdul, o una variable de l'adreça. |
