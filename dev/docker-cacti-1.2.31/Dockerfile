FROM php:8.3-apache-bookworm@sha256:73ec03963e2c1d20500a17326479f4d761843109c952aca44f89064924092d97

ARG CACTI_COMMIT=1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1
ARG CACTI_ARCHIVE_SHA256=fcc37e877e3e4c937a9cd245101784bc0961a0652f542660e8dce1e86c4e3a15

RUN apt-get update \
	&& DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
		ca-certificates \
		curl \
		default-mysql-client \
		fontconfig \
		fonts-dejavu-core \
		iputils-ping \
		libfreetype6-dev \
		libgmp-dev \
		libicu-dev \
		libjpeg62-turbo-dev \
		libldap2-dev \
		libonig-dev \
		libpng-dev \
		libsnmp-dev \
		libzip-dev \
		procps \
		rrdtool \
		snmp \
		tzdata \
	&& docker-php-ext-configure gd --with-freetype --with-jpeg \
	&& docker-php-ext-install -j"$(nproc)" \
		gd \
		gettext \
		gmp \
		intl \
		ldap \
		mbstring \
		mysqli \
		opcache \
		pcntl \
		pdo_mysql \
		snmp \
		sockets \
		zip \
	&& curl -fsSL --retry 3 \
		-o /tmp/cacti.tar.gz \
		"https://github.com/Cacti/cacti/archive/${CACTI_COMMIT}.tar.gz" \
	&& echo "${CACTI_ARCHIVE_SHA256}  /tmp/cacti.tar.gz" | sha256sum -c - \
	&& tar -xzf /tmp/cacti.tar.gz --strip-components=1 -C /var/www/html \
	&& rm -f /tmp/cacti.tar.gz \
	&& mkdir -p /var/lib/cacti /var/www/html/log /var/www/html/rra \
	&& chown -R www-data:www-data \
		/var/lib/cacti \
		/var/www/html/cache \
		/var/www/html/log \
		/var/www/html/resource \
		/var/www/html/rra \
		/var/www/html/scripts \
	&& a2enmod expires headers rewrite \
	&& rm -rf /var/lib/apt/lists/*

COPY config.php /var/www/html/include/config.php
COPY cacti-dev.ini /usr/local/etc/php/conf.d/cacti-dev.ini
COPY apache-cacti.conf /etc/apache2/conf-available/cacti-dev.conf
COPY cacti-entrypoint.sh /usr/local/bin/cacti-entrypoint
COPY bootstrap.sh /usr/local/bin/cacti-bootstrap

RUN chmod 0755 /usr/local/bin/cacti-entrypoint /usr/local/bin/cacti-bootstrap \
	&& a2enconf cacti-dev

WORKDIR /var/www/html

ENTRYPOINT ["cacti-entrypoint"]
CMD ["apache2-foreground"]

