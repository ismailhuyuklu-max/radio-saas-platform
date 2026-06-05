#!/usr/bin/env sh
set -eu

mkdir -p /var/media/news /var/media/ads
chown -R liquidsoap:liquidsoap /var/media

exec su -s /bin/sh liquidsoap -c 'exec liquidsoap /opt/liquidsoap/script.liq'
