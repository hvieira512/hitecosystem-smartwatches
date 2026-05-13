#!/bin/sh
set -e

DIR="$(dirname "$0")/../config/ssl"
mkdir -p "$DIR"

if [ -f "$DIR/fullchain.pem" ] && [ -f "$DIR/privkey.pem" ]; then
    echo "Certificados ja existem em $DIR"
    echo "Para regenerar, remova-os primeiro: rm $DIR/fullchain.pem $DIR/privkey.pem"
    exit 0
fi

openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout "$DIR/privkey.pem" \
    -out "$DIR/fullchain.pem" \
    -subj "/C=PT/O=Health Smartwatches/CN=localhost"

echo "Certificados SSL auto-assinados criados em $DIR/"
echo ""
echo "Para ativar TLS:"
echo "  1. Monte nginx-tls.conf em conf.d:"
echo "     volumes:"
echo "       - ./config/nginx-tls.conf:/etc/nginx/conf.d/tls.conf"
echo "  2. Reinicie: docker compose restart nginx"
echo ""
echo "AVISO: Certificados auto-assinados sao para DEV apenas."
echo "Para producao, substitua por certificados reais."
