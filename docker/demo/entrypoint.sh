#!/bin/sh
# Entrypoint of the demonstration image (docker/demo/Dockerfile): at each start, a new
# MariaDB database inside the container (local only), a configuration with random
# secrets, the demonstration data, then the regular entrypoint (migrations, PHP-FPM,
# nginx). The data is loaded again at each new day, so that it always ends with the
# current month. docker stop stops nginx, PHP-FPM and MariaDB cleanly.
# Everything is wiped at each start and each day: never use this image for real data.
# Runs read-only with few capabilities: see docker/demo/docker-compose.yml.
set -e

APP=/var/www/carbure
DATADIR=/var/lib/mysql
SOCKET=/run/mysqld/mysqld.sock
CONF=/data/conf/prod.ini

# A volume of a real instance mounted on /data by mistake is never wiped
if [ -f "$CONF" ] && ! grep -qx 'demo=true' "$CONF"; then
    echo "Carbure demo: /data holds a regular instance (no demo=true in $CONF): refusing to wipe it, use the image ltoinel/carbure instead" >&2
    exit 1
fi

# Random secret (hexadecimal)
random() { head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'; }

# --- MariaDB: new database, listening on 127.0.0.1 only ------------------------------
rm -rf "${DATADIR:?}"/* /data/conf /data/cache /data/logs
# Runtime directories of the image, empty when /run and /var/lib/nginx/tmp are tmpfs
# (read-only container, see docker-compose.yml)
mkdir -p /run/mysqld /run/nginx /var/lib/nginx/tmp /data/conf
chown mysql:mysql /run/mysqld "$DATADIR"
mariadb-install-db --user=mysql --datadir="$DATADIR" --skip-test-db \
    --auth-root-authentication-method=socket > /dev/null
mariadbd --user=mysql --datadir="$DATADIR" --socket="$SOCKET" --skip-networking=0 \
    --bind-address=127.0.0.1 --port=3306 --skip-name-resolve --skip-log-bin \
    --log-error=/run/mysqld/mariadb.log &
MARIADB_PID=$!

for i in $(seq 1 60); do
    mariadb-admin --socket="$SOCKET" ping > /dev/null 2>&1 && break
    if [ "$i" = 60 ] || ! kill -0 "$MARIADB_PID" 2>/dev/null; then
        echo "Carbure demo: MariaDB did not start" >&2
        cat /run/mysqld/mariadb.log >&2
        exit 1
    fi
    sleep 1
done

DB_PASSWORD=$(random 24)
mariadb --socket="$SOCKET" <<SQL
CREATE DATABASE carbure_demo CHARACTER SET utf8mb4;
CREATE USER 'carbure'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON carbure_demo.* TO 'carbure'@'127.0.0.1';
SQL

# --- Configuration: the one written by the installation wizard (random secrets) -----
DB_PASSWORD="$DB_PASSWORD" php -r '
    require "/var/www/carbure/src/lib/ClassLoader.php";
    Carbure\Lib\ClassLoader::register();
    [$ini] = Carbure\Lib\Installer::config("/var/www/carbure", [
        "db_hostname" => "127.0.0.1", "db_port" => "3306", "db_username" => "carbure",
        "db_password" => getenv("DB_PASSWORD"), "db_name" => "carbure_demo",
        "woob_path" => getenv("CARBURE_WOOB_PATH") ?: "woob", "woob_auto_update" => "false",
    ]);
    file_put_contents("/data/conf/prod.ini", Carbure\Lib\Installer::setIni($ini, "demo", "true"));'
chown -R www-data:www-data /data/conf
chmod 640 "$CONF"

# --- Demonstration data (the one of ./start.sh) ---------------------------------------
# Accounts of the demo: the public user demo / carbure (the user marie of the data set),
# the administrator admin with a random password, printed below (kept until the
# container stops)
DEMO_USER=demo
DEMO_PASSWORD=carbure
ADMIN_PASSWORD=$(random 8)

# Recreates the database with the demonstration data (the 12 months ending today), then
# sets the accounts of the demo and their language
load_data() {
    # Explicit failures: set -e does not apply when called in the condition of refresh
    su -s /bin/sh www-data -c "php $APP/tests/e2e/server/seed.php --demo" || return 1
    DB_PASSWORD="$DB_PASSWORD" DEMO_USER="$DEMO_USER" DEMO_PASSWORD="$DEMO_PASSWORD" \
    ADMIN_PASSWORD="$ADMIN_PASSWORD" php -r '
        $db = new mysqli("127.0.0.1", "carbure", getenv("DB_PASSWORD"), "carbure_demo", 3306);
        $user = getenv("DEMO_USER");
        $demo = $db->prepare("UPDATE users SET username = ?, email = ?, password = ? WHERE username = \"marie\"");
        $demo->execute([$user, "$user@example.com", password_hash(getenv("DEMO_PASSWORD"), PASSWORD_DEFAULT)]);
        $admin = $db->prepare("UPDATE users SET password = ? WHERE username = \"admin\"");
        $admin->execute([password_hash(getenv("ADMIN_PASSWORD"), PASSWORD_DEFAULT)]);' || return 1
    # Language of the demonstration users (fr by default)
    case "${LANGUAGE:-fr}" in
        fr|en|es|it|de|pt) mariadb --socket="$SOCKET" carbure_demo -e "UPDATE users SET language = '${LANGUAGE:-fr}'" ;;
    esac
}
load_data
LOADED=$(date +%F)

# The data stops at the day it was loaded: a container left running (restart:
# unless-stopped) would show an empty new month and a current month cut at its start.
# At each new day, the data is loaded again (changes of the visitors wiped, same admin
# password) and the migrations applied, as at start. DEMO_REFRESH_CHECK: seconds between
# two checks of the date.
refresh() {
    while sleep "${DEMO_REFRESH_CHECK:-60}"; do
        [ "$(date +%F)" != "$LOADED" ] || continue
        LOADED=$(date +%F)
        if load_data > /dev/null && su -s /bin/sh www-data -c "php $APP/tools/migrate.php" > /dev/null; then
            echo "Carbure demo: new day ($LOADED), demonstration data reloaded up to today"
        else
            echo "Carbure demo: reloading the demonstration data failed, retried tomorrow" >&2
        fi
    done
}

# --- Stop: nginx, PHP-FPM, then MariaDB ----------------------------------------------
CARBURE_PID=
REFRESH_PID=
stop() {
    echo "Carbure demo: stopping"
    [ -z "$REFRESH_PID" ] || kill -TERM "$REFRESH_PID" 2>/dev/null || true
    [ -z "$CARBURE_PID" ] || kill -TERM "$CARBURE_PID" 2>/dev/null || true
    pkill -QUIT php-fpm 2>/dev/null || true
    mariadb-admin --socket="$SOCKET" shutdown 2>/dev/null || kill -TERM "$MARIADB_PID" 2>/dev/null || true
    wait
    exit 0
}
# docker stop sends SIGQUIT (STOPSIGNAL of the PHP-FPM base image), Ctrl+C SIGINT
trap stop TERM INT QUIT

cat <<BANNER
==================================================================
 Carbure demo: http://localhost:8080/portal/ (or the port you published)
 User:          $DEMO_USER / $DEMO_PASSWORD
 Administrator: admin / $ADMIN_PASSWORD   (new password at each start)
 Demonstration data, wiped at each start and each day: never enter real data.
==================================================================
BANNER

# The regular entrypoint (migrations, PHP-FPM) then nginx, in the background: this shell
# stays the main process to stop MariaDB with them
"$APP/docker/entrypoint.sh" "$@" &
CARBURE_PID=$!
refresh &
REFRESH_PID=$!
wait "$CARBURE_PID" || true
# nginx stopped by itself: stop the rest
stop
