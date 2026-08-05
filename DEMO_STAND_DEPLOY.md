---
type: source
created: 2026-08-05
updated: 2026-08-05
sources: ["[[DEMO-STAND-DEPLOY]]"]
tags: [deploy,demo,runbook]
aliases: ["Демо-стенд деплой", "Demo Stand Deploy"]
title: "Demo Stand Deploy (demo.lastik.ru)"
---

# AUTOMETRIA ERP — Demo Stand Deploy (demo.lastik.ru)

Runbook поднятия публичного демо-стенда продукта на выделенном VPS.
Цель: инвесторы (Telderi) и клиенты открывают `https://demo.lastik.ru` и тестируют
кассу, склад, аналитику — без установки. Лендинг (витрина) живёт отдельно на Vercel
(`ahmed11551/auto`), кнопка «Открыть demo.lastik.ru» ведёт сюда.

> ⚠️ Это НЕ код ядра. Ядро `v1.1.0-STABLE` запечатано. Здесь — только инфраструктура
> демо-стенда поверх существующего `docker-compose.prod.yml`.

## 0. Что уже готово в репозитории
- `docker-compose.prod.yml` — полный стек (nginx → php-fpm → postgres16 RLS → redis → queue → scheduler).
- `docker/production/php/Dockerfile` — prod-образ PHP 8.4.
- `docker/nginx/prod.conf` — nginx конфиг.
- `scripts/deploy/prod-setup.sh` — авто-настройка.
- `.env.prod.example` — шаблон переменных.

## 1. Поднять VPS (founder-action)
- VPS: 2 vCPU / 4 GB RAM / 40 GB SSD (минимум для демо).
- ОС: Ubuntu 24.04 LTS.
- Открыть порты: 22 (SSH), 80, 443 (для demo.lastik.ru).
- Установить: `curl -fsSL https://get.docker.com | sh` + `docker compose plugin`.

## 2. Купить и привязать домен (founder-action)
- Купить `demo.lastik.ru` (или делегировать от lastik.ru).
- A-запись → IP VPS.
- На VPS выпустить TLS: `certbot --nginx -d demo.lastik.ru` (после поднятия контейнеров).

## 3. Заливка кода на VPS
```bash
# На VPS:
git clone https://github.com/ahmed11551/Lastik.git autometria-demo
cd autometria-demo
git checkout v1.1.0-STABLE   # запечатанный тег

# Собрать encoded dist (если требуется SourceGuardian/IonCube) ИЛИ использовать открытый код для демо:
# Для демо допустимо поднять открытый код (APP_ENV=production, без энкодера):
cp .env.prod.example .env.prod
nano .env.prod   # задать APP_URL=https://demo.lastik.ru, сильные пароли, APP_KEY

# Лицензия demo (из licensing/autometria.lic.example — уже в репозитории):
mkdir -p licensing && cp storage/framework/licensing/autometria.lic.example licensing/autometria.lic
cp storage/framework/licensing/public.pem licensing/public.pem

docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --class=DemoSeeder
```

## 4. TLS и домен
```bash
# nginx уже слушает :80. Certbot:
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d demo.lastik.ru
# Certbot сам допишет 443 + редирект в docker/nginx/prod.conf (или host-nginx).
```

## 5. Проверка
```bash
curl -sI https://demo.lastik.ru | head -1   # HTTP/2 200
docker compose -f docker-compose.prod.yml ps   # все 6 сервисов up
docker compose -f docker-compose.prod.yml exec app php artisan about
```

## 6. Demo-данные
- `DemoSeeder` создаёт СТО, ячейки, 8 заказов, демо-кассу (wizard-логин в 1 клик).
- Демо-роут: `/demo/login` (без изменения UI) → вход под demo-тенантом.

## 7. Безопасность демо
- Demo-тенант изолирован RLS (как и боевой).
- Рекомендуется: rate-limit на nginx, запрет экспорта реальных данных.
- Регулярный сброс БД к DemoSeeder (cron `php artisan db:seed --class=DemoSeeder --force`).

## 8. Связь с лендингом
- Vercel-лендинг (`ahmed11551/auto`) → кнопка `https://demo.lastik.ru`.
- Лендинг = витрина, бэкенд = этот демо-стенд.

> ⚠️ Founder-action: VPS + домен + Certbot + реальные пароли APP_KEY ставит фаундер.
> Я (Hermes) подготовил все конфиги и runbook; сам сервер не поднимаю (нет инфры/оплаты).
