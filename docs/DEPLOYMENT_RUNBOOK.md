---
type: source
created: 2026-08-05
updated: 2026-08-05
sources: ["[[DEPLOYMENT-RUNBOOK]"]
tags: [deploy,runbook]
aliases: ["Ранбук деплоя", "Deployment Runbook"]
title: "Deployment Runbook"
---

# AUTOMETRIA ERP / LASTIK — Deployment Runbook

Полное руководство по развёртыванию ядра на чистом Linux-сервере заказчика (self-hosted / production) **без участия вендора**.

Связанные материалы: [ARCHITECTURE_OVERVIEW.md](./ARCHITECTURE_OVERVIEW.md), [production-deploy.md](./production-deploy.md), [licensing-ops.md](./licensing-ops.md), [ACCEPTANCE_CHECKLIST.md](./ACCEPTANCE_CHECKLIST.md).

---

## 1. Требования к серверу

| Ресурс | Минимум (1 точка / до ~10 касс) | Рекомендуется |
|--------|----------------------------------|---------------|
| CPU | 2 vCPU | 4 vCPU |
| RAM | 4 GB | 8 GB |
| Disk | 40 GB SSD | 80+ GB SSD |
| OS | **Ubuntu 22.04 LTS** или **24.04 LTS** (x86_64) | то же |
| Сеть | публичный IP или VPN; открыты **80/443** | — |

### ПО на хосте

| Компонент | Версия |
|-----------|--------|
| Docker Engine | **24+** (`docker --version`) |
| Docker Compose | **v2** плагин (`docker compose version`) |
| Git / rsync / curl | актуальные из apt |
| Certbot (опц.) | для Let’s Encrypt на хосте или в sidecar |

Проверка:

```bash
docker --version
docker compose version
# Docker version 24+ / Compose v2.x
```

Установка Docker на Ubuntu (кратко):

```bash
sudo apt-get update
sudo apt-get install -y ca-certificates curl gnupg
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
  | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
sudo usermod -aG docker "$USER"   # перелогиниться
```

---

## 2. Архитектура runtime (Compose)

Файл: `docker-compose.prod.yml`.

| Сервис | Контейнер | Назначение |
|--------|-----------|------------|
| `app` | `autometria_app` | PHP-FPM **8.4** (SourceGuardian), Laravel |
| `webserver` | `autometria_webserver` | Nginx 1.27 → FastCGI `app:9000` |
| `postgres` | `autometria_postgres` | PostgreSQL **16** + RLS; init: `pg_trgm`, `uuid-ossp` |
| `redis` | `autometria_redis` | Cache / session / queue (с паролем) |
| `queue` | `autometria_worker` | `php artisan queue:work redis --queue=high,default,imports` |
| `scheduler` | `autometria_scheduler` | `php artisan schedule:work` (отдельный процесс, **host cron не нужен**) |

Порты наружу: только `${HTTP_PORT:-80}` у Nginx. Postgres/Redis **не** публикуются.

Локальная разработка: `docker-compose.yml` (код bind-mount, порт `${APP_PORT:-8000}`).

---

## 3. Подготовка пакета на стороне вендора

1. Собрать encoded dist (см. [client-build-encoding.md](./client-build-encoding.md)):

```bash
ENCODER=sourceguardian PHP_VERSION=8.4 OUT_DIR=./dist ./scripts/build-encoded-dist.sh
```

2. Выпустить лицензию `autometria.lic` + положить `public.pem` (см. [licensing-ops.md](./licensing-ops.md)). **`private.pem` на сервер заказчика не передаётся.**

3. Передать на сервер:

```bash
rsync -avz \
  ./dist \
  ./docker \
  ./docker-compose.prod.yml \
  ./.env.production.example \
  ./.env.prod.example \
  ./scripts/deploy \
  ./docs \
  ./Makefile \
  user@client-server:/opt/autometria/
# отдельно: licensing/autometria.lic + licensing/public.pem по защищённому каналу
```

---

## 4. Переменные окружения (`.env.prod` / `.env.production`)

На сервере:

```bash
cd /opt/autometria
cp .env.production.example .env.prod
# либо: cp .env.prod.example .env.prod
```

Обязательные поля:

| Переменная | Описание |
|------------|----------|
| `APP_KEY` | `base64:…` Laravel key |
| `APP_URL` | публичный URL, напр. `https://erp.client.example` |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | Postgres |
| `REDIS_PASSWORD` | пароль Redis |
| `REDIS_CLIENT` | `predis` (по умолчанию; `phpredis` — если собрано расширение) |
| `HTTP_PORT` | хост-порт Nginx (по умолчанию `80`) |

Генерация `APP_KEY` (на любой машине с PHP или openssl):

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"
# или
openssl rand -base64 32 | sed 's/^/base64:/'
```

Compose подхватывает файл так:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod config
```

---

## 5. Первичный запуск (с нуля)

### 5.1. Лицензии

```bash
mkdir -p licensing
# скопировать выданные файлы:
#   licensing/autometria.lic
#   licensing/public.pem
ls -la licensing/
```

### 5.2. Автоматический bootstrap (рекомендуется)

```bash
chmod +x scripts/deploy/prod-setup.sh
./scripts/deploy/prod-setup.sh --mode=prod --seed
# или через Makefile:
make prod-setup SEED=1
```

Скрипт: проверяет Docker / `.env.prod` / `dist/` / лицензии → `compose up -d --build` → `migrate --force` → опционально `AcceptanceSeeder` → `config|route|view:cache` → `storage:link`.

### 5.3. Ручные команды (эквивалент)

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build

docker compose -f docker-compose.prod.yml --env-file .env.prod exec app \
  php artisan migrate --force

# Опционально: демо-данные приёмки (admin@lastik.local / password)
docker compose -f docker-compose.prod.yml --env-file .env.prod exec app \
  php artisan db:seed --class=Database\\Seeders\\AcceptanceSeeder --force

docker compose -f docker-compose.prod.yml --env-file .env.prod exec app \
  php artisan config:cache && \
docker compose -f docker-compose.prod.yml --env-file .env.prod exec app \
  php artisan route:cache && \
docker compose -f docker-compose.prod.yml --env-file .env.prod exec app \
  php artisan view:cache && \
docker compose -f docker-compose.prod.yml --env-file .env.prod exec app \
  php artisan storage:link
```

Миграции включают **FORCE ROW LEVEL SECURITY** на tenant-таблицах (см. Architecture Overview).

### 5.4. Проверка здоровья

```bash
docker compose -f docker-compose.prod.yml ps
curl -fsS "http://127.0.0.1:${HTTP_PORT:-80}/up" || curl -I "http://127.0.0.1:${HTTP_PORT:-80}/"
docker compose -f docker-compose.prod.yml --env-file .env.prod exec app php -m | grep -i sourceguardian
docker compose -f docker-compose.prod.yml --env-file .env.prod exec postgres \
  psql -U "$DB_USERNAME" -d "$DB_DATABASE" -c "\\dx"
# ожидаются расширения: pg_trgm, uuid-ossp
```

---

## 6. SSL / Certbot

Стек Compose слушает **HTTP :80**. TLS рекомендуется терминировать на хосте или reverse-proxy.

### Вариант A — Certbot (Nginx на хосте проксирует в Docker)

```bash
sudo apt-get install -y certbot
# Пример: остановить публикацию 80 из Compose на время выпуска, либо использовать DNS-01
sudo certbot certonly --standalone -d erp.client.example
```

Затем внешний Nginx/Caddy с `proxy_pass http://127.0.0.1:8080` (если `HTTP_PORT=8080`) и сертификатами из `/etc/letsencrypt/live/...`.

### Вариант B — Caddy / Traefik перед Compose

- Compose: `HTTP_PORT=8080` (только localhost).
- Proxy слушает 443, проксирует на `127.0.0.1:8080`.
- `APP_URL=https://erp.client.example`.

После смены URL:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d
docker compose -f docker-compose.prod.yml --env-file .env.prod exec app php artisan config:cache
```

---

## 7. Фоновые процессы (Queue + Scheduler)

В production **не требуется** Supervisor на хосте: worker и scheduler — отдельные контейнеры с `restart: always`.

| Процесс | Команда в контейнере |
|---------|----------------------|
| Queue | `php artisan queue:work redis --queue=high,default,imports --sleep=1 --tries=3 --timeout=90` |
| Scheduler | `php artisan schedule:work` |

Обслуживание:

```bash
# логи
docker compose -f docker-compose.prod.yml logs -f queue scheduler

# рестарт после смены лицензии / .env
docker compose -f docker-compose.prod.yml restart app queue scheduler webserver

# failed jobs
docker compose -f docker-compose.prod.yml --env-file .env.prod exec app php artisan queue:failed
```

Если заказчик предпочитает host-cron вместо контейнера `scheduler`:

```cron
* * * * * cd /opt/autometria && docker compose -f docker-compose.prod.yml exec -T app php artisan schedule:run >> /var/log/autometria-schedule.log 2>&1
```

и отключить сервис `scheduler` в override-файле.

---

## 8. Операционные процедуры

### Обновление релиза

1. Остановить запись (опционально) / сделать backup Postgres.
2. Залить новый `dist/` + при необходимости обновлённые `docker/` и compose.
3. `docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build`
4. `php artisan migrate --force`
5. `php artisan config:cache` (+ route/view)
6. Smoke: `/up`, логин, открытие смены, POS.

### Backup PostgreSQL

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod exec -T postgres \
  pg_dump -U "$DB_USERNAME" "$DB_DATABASE" | gzip > "backup-$(date +%F).sql.gz"
```

### Ротация лицензии

```bash
cp /secure/new.autometria.lic licensing/autometria.lic
docker compose -f docker-compose.prod.yml restart app queue scheduler
```

### Расширения Postgres на уже существующем volume

Init-скрипты (`docker/postgres/init`) выполняются **только при первом создании** volume. На живой БД:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod exec postgres \
  psql -U "$DB_USERNAME" -d "$DB_DATABASE" \
  -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm; CREATE EXTENSION IF NOT EXISTS "uuid-ossp";'
```

---

## 9. Локальная / приёмочная среда

```bash
docker compose up -d --build
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --class=Database\\Seeders\\AcceptanceSeeder --force
# UI: http://localhost:8000  ·  admin@lastik.local / password
./scripts/deploy/prod-setup.sh --mode=dev --seed   # тот же сценарий для compose.yml
```

Тесты и фронт (на машине разработчика / CI):

```bash
docker compose exec app php artisan test
npm ci && npm run build
```

Чеклист приёмки: [ACCEPTANCE_CHECKLIST.md](./ACCEPTANCE_CHECKLIST.md).

---

## 10. Troubleshooting

| Симптом | Действие |
|---------|----------|
| 419 / CSRF на API | API исключён из CSRF; проверить, что клиент бьёт в `/api/v1/...` |
| License error | наличие `licensing/*.pem`/`*.lic`, домены в лицензии vs `APP_URL`, рестарт `app` |
| Пустые выборки после логина | GUC `app.current_tenant_id` / middleware `EnsureTenant`; см. Architecture |
| Queue не обрабатывает импорт 1С | `docker compose logs queue`; Redis password; очередь `imports` |
| Nginx 502 | `app` не healthy / FPM не слушает 9000; `docker compose logs app webserver` |
| Нет `pg_trgm` | §8 «Расширения Postgres» |

---

## 11. Быстрый чеклист Go-Live

- [ ] Ubuntu 22.04/24.04, Docker 24+, Compose v2
- [ ] `.env.prod` заполнен, сильные пароли, `APP_URL=https://…`
- [ ] `licensing/autometria.lic` + `public.pem`
- [ ] `dist/` на месте, `compose up -d --build` OK
- [ ] `migrate --force` без ошибок (RLS применён)
- [ ] TLS (Certbot / proxy), `APP_URL` совпадает с сертификатом
- [ ] `queue` + `scheduler` в `running`
- [ ] Backup Postgres настроен
- [ ] Smoke-тест кассы / склада / 1С (если используется)
