#!/usr/bin/env bash
set -euo pipefail

if [ -z "${WORDPRESS_DB_HOST:-}" ]; then
	export WORDPRESS_DB_HOST="${MYSQLHOST:-}:${WORDPRESS_DB_PORT:-${MYSQLPORT:-3306}}"
fi
export WORDPRESS_DB_USER="${WORDPRESS_DB_USER:-${MYSQLUSER:-root}}"
export WORDPRESS_DB_PASSWORD="${WORDPRESS_DB_PASSWORD:-${MYSQLPASSWORD:-}}"
export WORDPRESS_DB_NAME="${WORDPRESS_DB_NAME:-${MYSQLDATABASE:-railway}}"

rm -f \
	/etc/apache2/mods-enabled/mpm_event.conf \
	/etc/apache2/mods-enabled/mpm_event.load \
	/etc/apache2/mods-enabled/mpm_worker.conf \
	/etc/apache2/mods-enabled/mpm_worker.load
ln -sf ../mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf
ln -sf ../mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load

setup_wordpress() {
	site_url="${WORDPRESS_SITE_URL:-https://woo-logistics-demo.northendtech.ca}"
	admin_user="${WORDPRESS_ADMIN_USER:-goodlife}"
	admin_password="${WORDPRESS_ADMIN_PASSWORD:-}"
	admin_email="${WORDPRESS_ADMIN_EMAIL:-goodlife@example.com}"

	if [ -z "$admin_password" ]; then
		echo "WORDPRESS_ADMIN_PASSWORD is required." >&2
		return 1
	fi

	echo "Waiting for WordPress database access..."
	for attempt in $(seq 1 60); do
		if timeout 8s php <<'PHP'
<?php
$host = getenv('WORDPRESS_DB_HOST');
$user = getenv('WORDPRESS_DB_USER');
$pass = getenv('WORDPRESS_DB_PASSWORD');
$name = getenv('WORDPRESS_DB_NAME');
$port = 3306;

if (strpos($host, ':') !== false) {
	$parts = explode(':', $host, 2);
	$host  = $parts[0];
	$port  = (int) $parts[1];
}

$mysqli = mysqli_init();
$mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
if (! $mysqli->real_connect($host, $user, $pass, $name, $port)) {
	fwrite(STDERR, 'Database connection unavailable: ' . mysqli_connect_error() . PHP_EOL);
	exit(1);
}
PHP
		then
			break
		fi
		sleep 2
		if [ "$attempt" = "60" ]; then
			echo "WordPress database did not become available." >&2
			return 1
		fi
	done

	echo "Waiting for WordPress config..."
	for attempt in $(seq 1 60); do
		if [ -f /var/www/html/wp-config.php ]; then
			break
		fi
		sleep 1
		if [ "$attempt" = "60" ]; then
			echo "WordPress config was not created." >&2
			return 1
		fi
	done

	if ! wp core is-installed --path=/var/www/html --allow-root >/dev/null 2>&1; then
		wp core install \
			--path=/var/www/html \
			--url="$site_url" \
			--title="Woo Logistics Demo" \
			--admin_user="$admin_user" \
			--admin_password="$admin_password" \
			--admin_email="$admin_email" \
			--skip-email \
			--allow-root
	fi

	wp option update home "$site_url" --path=/var/www/html --allow-root
	wp option update siteurl "$site_url" --path=/var/www/html --allow-root
	configure_wlp_settings

	if wp user get "$admin_user" --path=/var/www/html --allow-root >/dev/null 2>&1; then
		wp user update "$admin_user" --role=administrator --user_pass="$admin_password" --path=/var/www/html --allow-root
	else
		wp user create "$admin_user" "$admin_email" --role=administrator --user_pass="$admin_password" --path=/var/www/html --allow-root
	fi

	wp plugin install woocommerce --activate --path=/var/www/html --allow-root
	wp plugin activate woo-logistics-plugin --path=/var/www/html --allow-root
	wp eval-file /var/www/html/wp-content/plugins/woo-logistics-plugin/scripts/seed-local-wordpress.php --path=/var/www/html --allow-root || true
}

update_wp_option_from_env() {
	option_name="$1"
	shift

	for env_name in "$@"; do
		value="$(printenv "$env_name" || true)"
		if [ -n "$value" ]; then
			value="$(printf '%s' "$value" | sed 's/^\xEF\xBB\xBF//')"
			wp option update "$option_name" "$value" --path=/var/www/html --allow-root >/dev/null
			return 0
		fi
	done

	return 0
}

configure_wlp_settings() {
	update_wp_option_from_env wlp_cp_sandbox WLP_CP_SANDBOX CP_USE_SANDBOX
	update_wp_option_from_env wlp_cp_api_user WLP_CP_API_USER CP_DEVELOPMENT_USER CP_PROD_USER
	update_wp_option_from_env wlp_cp_api_password WLP_CP_API_PASSWORD CP_DEVELOPMENT_PASSWORD CP_PROD_PASSWORD
	update_wp_option_from_env wlp_cp_customer_number WLP_CP_CUSTOMER_NUMBER CP_CUSTOMER_NUMBER
	update_wp_option_from_env wlp_cp_origin_name WLP_CP_ORIGIN_NAME CP_ORIGIN_NAME
	update_wp_option_from_env wlp_cp_origin_company WLP_CP_ORIGIN_COMPANY CP_ORIGIN_COMPANY
	update_wp_option_from_env wlp_cp_origin_email WLP_CP_ORIGIN_EMAIL CP_ORIGIN_EMAIL
	update_wp_option_from_env wlp_cp_origin_phone WLP_CP_ORIGIN_PHONE CP_ORIGIN_PHONE_MEDUSA CP_ORIGIN_PHONE_WOO
	update_wp_option_from_env wlp_cp_origin_address_1 WLP_CP_ORIGIN_ADDRESS_1 CP_ORIGIN_ADDRESS
	update_wp_option_from_env wlp_cp_origin_address_2 WLP_CP_ORIGIN_ADDRESS_2 CP_ORIGIN_ADDRESS_2
	update_wp_option_from_env wlp_cp_origin_city WLP_CP_ORIGIN_CITY CP_ORIGIN_CITY
	update_wp_option_from_env wlp_cp_origin_province WLP_CP_ORIGIN_PROVINCE CP_ORIGIN_PROVINCE
	update_wp_option_from_env wlp_cp_origin_postal_code WLP_CP_ORIGIN_POSTAL_CODE CP_ORIGIN_POSTAL_CODE
	update_wp_option_from_env wlp_cp_customer_notifications WLP_CP_CUSTOMER_NOTIFICATIONS
	update_wp_option_from_env wlp_default_service_code WLP_DEFAULT_SERVICE_CODE
	update_wp_option_from_env wlp_hide_regular_parcel WLP_HIDE_REGULAR_PARCEL
	update_wp_option_from_env wlp_calculate_product_weight WLP_CALCULATE_PRODUCT_WEIGHT
	update_wp_option_from_env wlp_use_base_package_weight WLP_USE_BASE_PACKAGE_WEIGHT
	update_wp_option_from_env wlp_base_package_weight_kg WLP_BASE_PACKAGE_WEIGHT_KG
}

setup_wordpress &
exec docker-entrypoint.sh "$@"
