#!/bin/sh
set -eu

db_host="${CACTI_DB_HOST:-db}"
db_port="${CACTI_DB_PORT:-3306}"
db_name="${CACTI_DB_NAME:-cacti}"
db_user="${CACTI_DB_USER:-cacti}"
db_password="${CACTI_DB_PASSWORD:-cacti-dev-db}"
admin_password="${CACTI_ADMIN_PASSWORD:-cacti-dev}"

export MYSQL_PWD="$db_password"

attempt=0
until mariadb-admin \
	--host="$db_host" \
	--port="$db_port" \
	--user="$db_user" \
	--silent ping >/dev/null 2>&1; do
	attempt=$((attempt + 1))
	if [ "$attempt" -ge 60 ]; then
		echo "Cacti bootstrap: database did not become ready" >&2
		exit 1
	fi
	sleep 2
done

seed_directory() {
	source_dir="$1"
	destination_dir="$2"
	mkdir -p "$destination_dir"
	if [ -z "$(find "$destination_dir" -mindepth 1 -maxdepth 1 -print -quit)" ]; then
		cp -a "$source_dir"/. "$destination_dir"/
	fi
	chown -R www-data:www-data "$destination_dir"
}

seed_directory /plugin-source/configs /var/www/html/plugins/weathermap/configs
seed_directory /plugin-source/output /var/www/html/plugins/weathermap/output

mysql() {
	mariadb \
		--host="$db_host" \
		--port="$db_port" \
		--user="$db_user" \
		"$db_name" "$@"
}

if ! mysql --batch --skip-column-names \
	--execute="SHOW TABLES LIKE 'version'" | grep -qx version; then
	echo "Cacti bootstrap: importing the 1.2.31 base schema"
	mysql < /var/www/html/cacti.sql
fi

installed_version="$(mysql --batch --skip-column-names --execute='SELECT cacti FROM version LIMIT 1')"
if [ "$installed_version" != "1.2.31" ]; then
	echo "Cacti bootstrap: completing the Cacti 1.2.31 installation"
	php /var/www/html/cli/install_cacti.php --accept-eula --install --force
	installed_version="$(mysql --batch --skip-column-names --execute='SELECT cacti FROM version LIMIT 1')"
fi

if [ "$installed_version" != "1.2.31" ]; then
	echo "Cacti bootstrap: core install verification failed: $installed_version" >&2
	exit 1
fi

php -r '
$dsn = sprintf(
	"mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
	getenv("CACTI_DB_HOST"),
	getenv("CACTI_DB_PORT"),
	getenv("CACTI_DB_NAME")
);
$pdo = new PDO($dsn, getenv("CACTI_DB_USER"), getenv("CACTI_DB_PASSWORD"), [
	PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$statement = $pdo->prepare(
	"UPDATE user_auth
	 SET password = ?, must_change_password = ?, failed_attempts = 0, locked = ?
	 WHERE username = ?"
);
$statement->execute([
	password_hash(getenv("CACTI_ADMIN_PASSWORD"), PASSWORD_DEFAULT),
	"",
	"",
	"admin",
]);
'

php /var/www/html/cli/plugin_manage.php \
	--plugin=weathermap \
	--install \
	--enable \
	--allperms

php /var/www/html/cli/plugin_manage.php \
	--plugin=weathermap \
	--enable

plugin_state="$(mysql --batch --skip-column-names \
	--execute="SELECT CONCAT(directory, ':', status) FROM plugin_config WHERE directory = 'weathermap'")"
plugin_version="$(mysql --batch --skip-column-names \
	--execute="SELECT version FROM plugin_config WHERE directory = 'weathermap'")"

if [ "$plugin_state" != "weathermap:1" ] || [ -z "$plugin_version" ]; then
	echo "Cacti bootstrap: Weathermap install verification failed: $plugin_state:$plugin_version" >&2
	exit 1
fi

echo "Cacti bootstrap: ready (Cacti $installed_version, $plugin_state:$plugin_version)"
