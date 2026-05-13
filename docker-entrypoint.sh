#!/bin/sh
set -e

# Garantir dependencias instaladas (importante quando vendor e volume mounted)
if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]; then
    echo "=== A instalar dependencias (composer) ==="
    composer install --no-dev --optimize-autoloader
fi

# Se estamos em ambiente Docker (DB_HOST definido), esperar pelo MySQL
if [ -n "$DB_HOST" ]; then
    echo "=== Aguardar MySQL em $DB_HOST:${DB_PORT:-3306} ==="
    until php -r "
        try {
            new PDO('mysql:host=${DB_HOST};port=${DB_PORT:-3306}', '${DB_USER:-root}', '${DB_PASS:-}');
            echo \"ok\n\";
        } catch (PDOException \$e) {
            echo \$e->getMessage() . \"\n\";
            exit(1);
        }
    " 2>/dev/null; do
        echo "MySQL indisponivel - a aguardar 2s..."
        sleep 2
    done
    echo "MySQL pronto!"

    echo "=== A executar migracao ==="
    php bin/migrate.php --seed
fi

echo "=== A iniciar servidor ==="
exec "$@"
