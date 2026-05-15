FROM wordpress:php8.2-apache

RUN set -eux; \
	apt-get update; \
	apt-get install -y --no-install-recommends less default-mysql-client; \
	a2dismod mpm_event mpm_worker || true; \
	a2enmod mpm_prefork rewrite; \
	rm -rf /var/lib/apt/lists/*; \
	curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar; \
	chmod +x /usr/local/bin/wp

COPY . /usr/src/wordpress/wp-content/plugins/woo-logistics-plugin
COPY railway-entrypoint.sh /usr/local/bin/railway-entrypoint.sh

RUN chmod +x /usr/local/bin/railway-entrypoint.sh

ENTRYPOINT ["railway-entrypoint.sh"]
CMD ["apache2-foreground"]
