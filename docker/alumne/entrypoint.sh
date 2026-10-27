#!/bin/sh
# Espera la clau dels alumnes (la genera el servei "keys") i arrenca sshd.
set -e
until [ -s /keys/students/id_ed25519.pub ]; do
    echo "Esperant la clau..."
    sleep 1
done
ssh-keygen -A >/dev/null
exec /usr/sbin/sshd -D -e
