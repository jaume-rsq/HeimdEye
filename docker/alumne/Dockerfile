# Alumne fictici de la demo: un servidor Ubuntu amb SSH, l'usuari "alumne" amb sudo i Python
# (per a Ansible). Només és a la xarxa interna "alumnes", sense sortida a Internet.
FROM ubuntu:24.04
RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
       openssh-server sudo python3 iproute2 iputils-ping curl ca-certificates nano \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --create-home --shell /bin/bash alumne \
    && echo 'alumne ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/alumne \
    && mkdir -p /run/sshd \
    && printf 'PasswordAuthentication no\nPermitRootLogin no\nStrictModes no\nAuthorizedKeysFile /keys/students/id_ed25519.pub\n' > /etc/ssh/sshd_config.d/heimdeye.conf
COPY entrypoint.sh /usr/local/bin/alumne-entrypoint
EXPOSE 22
ENTRYPOINT ["alumne-entrypoint"]
