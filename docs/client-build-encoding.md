# AUTOMETRIA ERP — Encoded Client Dist (SourceGuardian / IonCube)

Цель: клиентский дистрибутив получает `Autometria\` как бинарный bytecode, без открытых исходников сервисов/middleware/лицензирования.

## Build agents

| Encoder | Версия | Бинарь (пример) |
|---------|--------|-----------------|
| SourceGuardian | 17 | `sgencoder` |
| IonCube | Encoder 15 | `ioncube_encoder.sh` |

PHP target: **8.4** (как в Docker/prod).

## Команда

```bash
chmod +x scripts/build-encoded-dist.sh

# SourceGuardian 17 → ./dist (для docker/production/php/Dockerfile)
ENCODER=sourceguardian PHP_VERSION=8.4 ./scripts/build-encoded-dist.sh

# IonCube Encoder 15
ENCODER=ioncube PHP_VERSION=8.4 ./scripts/build-encoded-dist.sh
```

Результат по умолчанию: `dist/` (override: `OUT_DIR=./dist-encoded`).

Скрипт:

1. rsync исходников без `.git` / `vendor` / `tests` / `.env`
2. **исключает** `private.pem` и `*.lic`
3. копирует только `public.pem` + `autometria.lic.example`
4. кодирует каталог `app/` выбранным энкодером
5. fail-fast, если `private.pem` обнаружен в dist

## После деплоя на клиенте

```bash
cd /var/www/autometria
composer install --no-dev -o
# положить выпущенный lic:
# storage/framework/licensing/autometria.lic
php artisan migrate --force
php artisan config:cache
```

Loader (SourceGuardian / IonCube) должен быть установлен в PHP на целевом хосте.

## CI note

Encode-шаг — **отдельный private job** на вашем build-агенте с коммерческой лицензией энкодера. Публичный GitHub Actions клиентов этот шаг не выполняет.
