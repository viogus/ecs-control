#!/bin/sh
set -e

echo "Starting ECS-Controller..."

# 1. 确保数据目录权限正确
# Docker 挂载卷时可能会导致权限归属为 root，这里强制修正为 www-data
if [ -d "/var/www/html/data" ]; then
    chown -R www-data:www-data /var/www/html/data
fi

# 2. 启动 Cron 服务 (后台运行)
# Alpine dcron：以 root 启动守护进程，任务按 /etc/crontabs/ 下文件名自动切到对应用户执行。
crond -b -l 8
echo "Cron daemon started."

# 3. 启动 Telegram 控制轮询 (后台运行，崩溃自动重启)
# 如果没有配置 Telegram，进程会保持低频等待；配置后按钮控制可秒级响应。
su -s /bin/sh www-data -c "
    while true; do
        php /var/www/html/telegram_worker.php >/dev/null 2>&1
        sleep 5
    done
" &
echo "Telegram control worker started."

# 4. 启动 PHP-FPM (后台运行)
# -D 表示 Daemonize (守护进程模式)
php-fpm -D
echo "PHP-FPM started."

# 5. 启动 Nginx (前台运行)
# 保持 Nginx 在前台运行，防止容器退出
echo "Nginx started."
nginx -g 'daemon off;'
