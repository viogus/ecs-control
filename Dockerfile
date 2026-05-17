# 第一阶段：构建依赖 (Builder Stage)
FROM php:8.2-fpm-alpine AS builder

WORKDIR /app

# 安装 Composer (从官方安装器)
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer \
    && composer --version

# 复制依赖定义文件
COPY composer.json ./

# 安装依赖 (排除开发依赖，优化自动加载)
# 注意：SDK Sign::uuid() 的 microtime() 返回带空格的字符串导致签名失败，需修复
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs --no-interaction --no-scripts \
    && sed -i 's/return md5(\$salt . uniqid(md5(microtime(true)), true)) . microtime();/return md5(\$salt . uniqid(md5(microtime(true)), true)) . str_replace(" ", "", microtime());/' /app/vendor/alibabacloud/client/src/Support/Sign.php \
    && find vendor -type d -name tests -exec rm -rf {} + 2>/dev/null || true \
    && find vendor -type d -name test -exec rm -rf {} + 2>/dev/null || true \
    && find vendor -type d -name docs -exec rm -rf {} + 2>/dev/null || true \
    && find vendor -type d -name doc -exec rm -rf {} + 2>/dev/null || true \
    && find vendor -type d -name examples -exec rm -rf {} + 2>/dev/null || true \
    && find vendor -name '*.md' -delete 2>/dev/null || true \
    && find vendor -name '.gitignore' -delete 2>/dev/null || true \
    && composer clear-cache

# 复制其余项目文件 (.dockerignore 排除 cf-worker, docs, tests 等)
COPY . .

# 重新生成 autoload classmap，包含 src/ 下的新 class
RUN composer dump-autoload -o

# -----------------------------------------------------------------------------

# 第二阶段：运行环境 (Final Stage)
# 使用 Alpine 原生 php82 包，比 Docker PHP 镜像小 ~40%
FROM alpine:3.21

LABEL maintainer="ECS-Controller-Docker"

ARG PORT=43210
ENV PORT=${PORT} \
    TZ=Asia/Shanghai

# Alpine 镜像源 (构建时可通过 --build-arg ALPINE_MIRROR=mirrors.aliyun.com 切换)
ARG ALPINE_MIRROR=dl-cdn.alpinelinux.org

RUN echo "https://${ALPINE_MIRROR}/alpine/v3.21/main" > /etc/apk/repositories \
    && echo "https://${ALPINE_MIRROR}/alpine/v3.21/community" >> /etc/apk/repositories \
    && apk update \
    && apk add --no-cache \
    php82 \
    php82-fpm \
    php82-curl \
    php82-pdo_sqlite \
    php82-mbstring \
    php82-opcache \
    php82-session \
    php82-fileinfo \
    php82-openssl \
    php82-ctype \
    php82-iconv \
    php82-sodium \
    nginx \
    dcron \
    sqlite-libs \
    tzdata \
    # 配置时区
    && cp /usr/share/zoneinfo/$TZ /etc/localtime \
    && echo $TZ > /etc/timezone \
    # 配置 PHP
    && sed -i 's/;date.timezone =.*/date.timezone = Asia\/Shanghai/' /etc/php82/php.ini \
    # 清理
    && rm -rf /var/cache/apk/* \
    # 预创建目录并修正权限 (nginx 使用 www-data)
    && mkdir -p /var/www/html/data \
    && adduser -H -D -G www-data -s /sbin/nologin www-data 2>/dev/null || true \
    && chown -R www-data:www-data /var/www/html \
    # 配置 Cron (每分钟执行 monitor.php)
    && echo "* * * * * cd /var/www/html && /usr/bin/php82 /var/www/html/monitor.php >> /var/log/cron-monitor.log 2>&1" >> /etc/crontabs/root \
    && touch /var/log/cron-monitor.log

WORKDIR /var/www/html

# 复制 Nginx/PHP-FPM 配置 (利用缓存，变更频率低)
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
RUN rm -f /etc/php82/php-fpm.d/www.conf
COPY docker/php-fpm-www.conf /etc/php82/php-fpm.d/www.conf

# 复制并配置启动脚本
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# 最后复制项目代码 (变更频率高，放在最后)
COPY --from=builder --chown=www-data:www-data /app /var/www/html

EXPOSE ${PORT}
ENTRYPOINT ["/entrypoint.sh"]
