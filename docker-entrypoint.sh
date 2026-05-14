#!/bin/sh
set -e

# Garantir dependencias instaladas (importante quando vendor e volume mounted)
needs_composer_install=0

if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ] || [ ! -f "vendor/composer/installed.php" ]; then
    needs_composer_install=1
else
    php -r '
        $lock = json_decode(file_get_contents("composer.lock"), true);
        $installed = require "vendor/composer/installed.php";
        $installedPackages = $installed["versions"] ?? [];

        foreach (($lock["packages"] ?? []) as $package) {
            if (!isset($installedPackages[$package["name"]])) {
                fwrite(STDERR, "Dependencia Composer em falta: {$package["name"]}\n");
                exit(1);
            }
        }
    ' || needs_composer_install=1
fi

if [ "$needs_composer_install" -eq 1 ]; then
    echo "=== A instalar/atualizar dependencias (composer) ==="
    composer install --no-dev --optimize-autoloader --no-interaction
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
