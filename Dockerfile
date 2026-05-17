# Stage 1: rootfs (Alpine packages -> stripped filesystem)
FROM alpine:3.21 AS rootfs

ARG ALPINE_MIRROR=dl-cdn.alpinelinux.org

RUN echo "https://${ALPINE_MIRROR}/alpine/v3.21/main" > /etc/apk/repositories \
    && echo "https://${ALPINE_MIRROR}/alpine/v3.21/community" >> /etc/apk/repositories \
    && apk update \
    && apk add --no-cache \
    php82 php82-fpm php82-curl php82-pdo_sqlite \
    php82-mbstring php82-opcache php82-session \
    php82-openssl php82-ctype php82-iconv php82-sodium \
    nginx dcron sqlite-libs tzdata ca-certificates su-exec \
    # Remove huge unused extension
    && rm -f /usr/lib/php82/modules/fileinfo.so \
    # Create user BEFORE copying passwd to rootfs
    && adduser -H -D -G www-data -s /sbin/nologin www-data 2>/dev/null || true

# Build stripped rootfs
RUN mkdir -p /rootfs/bin /rootfs/usr/bin /rootfs/usr/sbin \
    /rootfs/lib /rootfs/etc/ssl/certs /rootfs/etc/php82/php-fpm.d \
    /rootfs/etc/nginx/http.d /rootfs/etc/crontabs \
    /rootfs/var/www/html/data /rootfs/var/log/nginx /rootfs/var/log/php82 /rootfs/tmp /rootfs/run \
    /rootfs/var/lib/nginx/tmp/client_body /rootfs/var/lib/nginx/tmp/proxy \
    /rootfs/var/lib/nginx/tmp/fastcgi /rootfs/var/lib/nginx/logs \
    /rootfs/run/nginx \
    /rootfs/usr/lib/php82/modules /rootfs/usr/share/zoneinfo/Asia \
    /rootfs/dev \
    && ln -sf /run /rootfs/var/run \
    # --- Binaries ---
    && cp /usr/bin/php82 /rootfs/usr/bin/ \
    && cp /usr/sbin/php-fpm82 /rootfs/usr/sbin/ \
    && cp /usr/sbin/nginx /rootfs/usr/sbin/ \
    && cp /usr/sbin/crond /rootfs/usr/sbin/ \
    && cp /bin/busybox /rootfs/bin/ \
    # Busybox symlinks
    && for a in sh sed chown echo sleep mkdir touch cat ls grep rm cp; do \
        ln -sf /bin/busybox /rootfs/bin/$a; \
    done \
    # --- Shared libs (auto-discover via ldd, include ext .so deps) ---
    && for bin in /usr/bin/php82 /usr/sbin/php-fpm82 /usr/sbin/nginx /usr/sbin/crond /bin/busybox /sbin/su-exec /usr/lib/php82/modules/*.so; do \
        ldd $bin 2>/dev/null; \
    done | awk '/=> \// {print $3}' | sort -u > /tmp/needed-libs.txt \
    && while IFS= read -r lib; do \
        dir=$(dirname "$lib"); \
        mkdir -p "/rootfs$dir"; \
        cp "$lib" "/rootfs$dir/"; \
    done < /tmp/needed-libs.txt \
    && cp /lib/ld-musl-* /rootfs/lib/ \
    # --- PHP extensions (only what we use) ---
    && for mod in curl mbstring opcache pdo pdo_sqlite session openssl ctype iconv sodium; do \
        cp /usr/lib/php82/modules/$mod.so /rootfs/usr/lib/php82/modules/ 2>/dev/null || true; \
    done \
    # --- Config files ---
    && cp -r /etc/php82 /rootfs/etc/ \
    && find /rootfs/etc/php82/conf.d -name '*.ini' ! -name '*curl*' ! -name '*mbstring*' \
        ! -name '*opcache*' ! -name '*pdo*' ! -name '*session*' ! -name '*openssl*' \
        ! -name '*ctype*' ! -name '*iconv*' ! -name '*sodium*' -delete \
    && cp -r /etc/nginx /rootfs/etc/ \
    && cp -r /etc/ssl/certs /rootfs/etc/ssl/ \
    && cp /etc/passwd /rootfs/etc/ \
    && cp /etc/group /rootfs/etc/ \
    && cp /etc/nsswitch.conf /rootfs/etc/ \
    && cp /etc/hosts /rootfs/etc/ \
    && cp /usr/share/zoneinfo/Asia/Shanghai /rootfs/etc/localtime \
    && echo "Asia/Shanghai" > /rootfs/etc/timezone \
    && cp /usr/share/zoneinfo/Asia/Shanghai /rootfs/usr/share/zoneinfo/Asia/ \
    # --- Cleanup ---
    && chmod 1777 /rootfs/tmp \
    && rm -f /rootfs/etc/nginx/http.d/default.conf \
    && sed -i 's/;date.timezone =.*/date.timezone = Asia\/Shanghai/' /rootfs/etc/php82/php.ini

# -----------------------------------------------------------------------------
# Stage 2: app builder (composer + vendor)
FROM php:8.2-fpm-alpine AS app

WORKDIR /app

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer \
    && composer --version

COPY composer.json ./

RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs --no-interaction --no-scripts \
    && sed -i 's/return md5(\$salt . uniqid(md5(microtime(true)), true)) . microtime();/return md5(\$salt . uniqid(md5(microtime(true)), true)) . str_replace(" ", "", microtime());/' /app/vendor/alibabacloud/client/src/Support/Sign.php \
    && find vendor -type d -name tests -exec rm -rf {} + 2>/dev/null || true \
    && find vendor -type d -name test -exec rm -rf {} + 2>/dev/null || true \
    && find vendor -type d -name docs -exec rm -rf {} + 2>/dev/null || true \
    && find vendor -type d -name examples -exec rm -rf {} + 2>/dev/null || true \
    && find vendor -name '*.md' -delete 2>/dev/null || true \
    && find vendor -name '.gitignore' -delete 2>/dev/null || true \
    && composer clear-cache

COPY . .
RUN composer dump-autoload -o

# -----------------------------------------------------------------------------
# Stage 3: final
FROM scratch

LABEL maintainer="ECS-Controller-Docker"

ARG PORT=43210
ENV PORT=${PORT} TZ=Asia/Shanghai

# Root filesystem
COPY --from=rootfs /rootfs /

# Project configs
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/php-fpm-www.conf /etc/php82/php-fpm.d/www.conf

# Entrypoint
COPY docker/entrypoint.sh /entrypoint.sh

# App code
COPY --from=app /app /var/www/html

# Cron + permissions
RUN ["/bin/sh", "-c", "echo '* * * * * cd /var/www/html && /usr/bin/php82 /var/www/html/monitor.php >> /var/log/cron-monitor.log 2>&1' >> /etc/crontabs/root && touch /var/log/cron-monitor.log"]
RUN ["/bin/chown", "-R", "www-data:www-data", "/var/www/html"]

VOLUME ["/var/www/html/data"]
EXPOSE ${PORT}
ENTRYPOINT ["/entrypoint.sh"]
