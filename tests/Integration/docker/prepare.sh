#!/bin/sh
#
# Приводит произвольный образ PHP + Swoole к виду, в котором запускается интеграционный набор.
#
# Используется дважды: запекается в локальный образ (docker/Dockerfile) и запускается напрямую
# в CI-джобе, которая стартует из базового образа.
set -eu

log() { printf '[prepare] %s\n' "$*"; }

# composer нужны git и unzip; базовые php:*-cli их не содержат.
if command -v apt-get >/dev/null 2>&1 && ! command -v git >/dev/null 2>&1; then
    log 'installing git/unzip for composer'
    apt-get update >/dev/null
    apt-get install -y --no-install-recommends git unzip >/dev/null
    rm -rf /var/lib/apt/lists/*
fi

# Debian/Ubuntu-сборки PHP держат расширения выключенными; swoole не грузится без pdo.
if command -v phpenmod >/dev/null 2>&1; then
    log 'phpenmod present — enabling the module set'
    phpenmod pdo pdo_pgsql pgsql sockets posix iconv mbstring curl dom tokenizer simplexml xmlwriter xml
    phpenmod swoole
fi

# opcache, настроенный под долгоживущий сервер, только замедляет короткий прогон.
CONF_DIR="$(php -i | sed -n 's/^Scan this dir for additional .ini files => //p' | head -1)"
if [ -n "${CONF_DIR}" ] && [ -d "${CONF_DIR}" ]; then
    printf 'opcache.enable_cli=0\n' > "${CONF_DIR}/zz-integration.ini"
fi

# Падаем здесь, а не загадочной ошибкой в тестах.
php -r 'exit(extension_loaded("swoole") ? 0 : 1);' || {
    log 'FATAL: ext-swoole is not loadable in this image'
    exit 1
}
php -r 'exit(version_compare(swoole_version(), "6.2.0", ">=") ? 0 : 1);' || {
    log "FATAL: swoole $(php -r 'echo swoole_version();') is older than 6.2.0"
    exit 1
}
php -r 'exit(defined("SWOOLE_HOOK_PDO_PGSQL") ? 0 : 1);' || {
    log 'FATAL: SWOOLE_HOOK_PDO_PGSQL is undefined — Swoole was built without --enable-swoole-pgsql'
    exit 1
}
php -r 'exit(in_array("pgsql", PDO::getAvailableDrivers(), true) ? 0 : 1);' || {
    log 'FATAL: PDO pgsql driver is unavailable'
    exit 1
}
php -r 'exit((Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL) && (Swoole\Runtime::getHookFlags() & SWOOLE_HOOK_PDO_PGSQL)) ? 0 : 1);' || {
    log 'FATAL: SWOOLE_HOOK_PDO_PGSQL cannot be enabled at runtime'
    exit 1
}

php -r 'printf("[prepare] ready: PHP %s / Swoole %s / ZTS %s%s", PHP_VERSION, swoole_version(), PHP_ZTS ? "yes" : "no", PHP_EOL);'
