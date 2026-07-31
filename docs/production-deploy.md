# AUTOMETRIA ERP — Production Deploy (Client)

## 0. Preconditions

- Docker Engine + Compose v2
- Encoded dist built on vendor machine (SourceGuardian / IonCube)
- Issued `autometria.lic` + `public.pem`

## 1. Build encoded dist (vendor machine)

```bash
ENCODER=sourceguardian PHP_VERSION=8.4 OUT_DIR=./dist ./scripts/build-encoded-dist.sh
```

Output: `./dist` (bytecode under `app/`, **без** `private.pem`).

## 2. Package & transfer to client

```bash
mkdir -p packaging/licensing
cp storage/framework/licensing/public.pem packaging/licensing/public.pem
cp /secure/outbox/autometria.lic packaging/licensing/autometria.lic

rsync -avz \
  ./dist \
  ./docker \
  ./docker-compose.prod.yml \
  ./.env.prod.example \
  ./packaging/licensing \
  user@client-server:/opt/autometria/
```

On client:

```bash
cd /opt/autometria
mv packaging/licensing licensing 2>/dev/null || true
cp .env.prod.example .env.prod
# заполнить APP_KEY, DB_PASSWORD, REDIS_PASSWORD
php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"  # → APP_KEY
```

## 3. Start stack

```bash
cd /opt/autometria
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
```

## 4. First boot

```bash
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --class=Database\\Seeders\\AcceptanceSeeder  # optional demo
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
docker compose -f docker-compose.prod.yml exec app php -m | grep -i sourceguardian
```

## 5. License rotation

Replace files and restart app/worker (без rebuild):

```bash
cp new.autometria.lic licensing/autometria.lic
docker compose -f docker-compose.prod.yml restart app queue scheduler
```

## Architecture

| Service | Role |
|---------|------|
| `app` | PHP-FPM 8.4 + SourceGuardian loader |
| `postgres` | PostgreSQL 16 + RLS |
| `redis` | cache/queue (password required) |
| `queue` | `queue:work` imports/high/default |
| `scheduler` | `schedule:work` |

See also: [licensing-ops.md](./licensing-ops.md), [client-build-encoding.md](./client-build-encoding.md).
