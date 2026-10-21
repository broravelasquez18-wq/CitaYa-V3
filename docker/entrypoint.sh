#!/bin/sh
set -e
cd /var/www/html

# 1) Genera config/local.php desde las variables de entorno de Dokploy.
php docker/configure.php

# 2) Asegura el almacenamiento (montado como volumen, se recrea en cada arranque).
mkdir -p storage/histories storage/outbox storage/sessions storage/wa-outbox storage/responses storage/support storage/upload-preview
chown -R www-data:www-data storage config/local.php
chmod -R 775 storage

# 3) Espera a la base de datos y prepara esquema + datos base (idempotente).
if [ "${RUN_SETUP:-1}" = "1" ]; then
  echo "Esperando a la base de datos..."
  for i in $(seq 1 30); do
    if php -r '$c=require "config/local.php"; try{new PDO(preg_replace("/;dbname=[^;]+/","",$c["db_dsn"]),$c["db_user"],$c["db_password"]);exit(0);}catch(Throwable $e){exit(1);}'; then
      echo "Base de datos accesible."; break
    fi
    sleep 2
  done
  php bin/setup.php || echo "Aviso: setup.php no completó (¿ya estaba preparada?)."
  # setup.php corre como root: lo que haya creado debe quedar escribible para Apache (www-data).
  chown -R www-data:www-data storage
  # Muestra las credenciales iniciales del administrador en los logs (solo la primera vez).
  if [ -f .runtime/first-login.txt ]; then
    echo "──────────── CREDENCIALES INICIALES ────────────"
    cat .runtime/first-login.txt
    echo "────────────────────────────────────────────────"
  fi
  # 4) Con Zernio, registra el webhook <PUBLIC_URL>/whatsapp.php (no lo duplica si ya existe).
  #    En segundo plano y con espera: la entrega de prueba necesita que Apache ya esté atendiendo.
  if [ "${WHATSAPP_TRANSPORT:-local}" = "zernio" ]; then
    (sleep 20; php bin/zernio.php webhook || echo "Aviso: no se registró el webhook de Zernio (revisa ZERNIO_* y PUBLIC_URL).") &
  fi
fi

exec "$@"
