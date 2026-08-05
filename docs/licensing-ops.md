---
type: concept
created: 2026-08-05
updated: 2026-08-05
sources: ["[[licensing-ops]"]
tags: [licensing,ops]
aliases: ["Лицензирование", "Licensing Ops"]
title: "Licensing Ops"
---

# AUTOMETRIA ERP — Licensing Operations (Production Prep)

## Модель доверия

| Артефакт | Где хранится | В клиентский репозиторий / dist |
|----------|--------------|----------------------------------|
| `private.pem` | Закрытый vault сервера лицензирования | **НИКОГДА** |
| `public.pem` | Репозиторий / client dist | Да |
| `autometria.lic` | Только на инстансе клиента | Нет (выдаётся вручную/через portal) |
| `autometria.lic.example` | Репозиторий | Да (шаблон) |

Middleware `Autometria\Http\Middleware\EnforceAutometriaLicense` проверяет:

1. наличие `storage/framework/licensing/autometria.lic`
2. RSA-подпись payload через `public.pem` (`OPENSSL_ALGO_SHA256`)
3. `hardware_hash` = `HardwareFingerprint::generate()` (соль `SEBIEV_AHMED_AUTOMETRIA_`)
4. `allowed_domains` и `expires_at`

В `local` / `testing` middleware bypass’ится.

---

## 1. Генерация PEM-пары (только licensing vault)

```bash
chmod +x tools/licensing/generate-keypair.sh
./tools/licensing/generate-keypair.sh ~/.autometria-licensing/keys
```

Создаёт:

- `~/.autometria-licensing/keys/private.pem` (chmod 600) — **offline / HSM / vault**
- `~/.autometria-licensing/keys/public.pem` — копируется в клиентский tree:

```bash
cp ~/.autometria-licensing/keys/public.pem \
  storage/framework/licensing/public.pem
```

> Если `private.pem` когда-либо попадал на рабочую машину разработчика — **ротируйте пару** перед первым прод-релизом.

---

## 2. Выпуск `autometria.lic`

На сервере лицензирования (где есть private key):

```bash
# Hardware hash снять на целевом сервере клиента:
php -r 'require "vendor/autoload.php"; echo Autometria\Services\Licensing\HardwareFingerprint::generate(), PHP_EOL;'

php tools/licensing/issue-license.php \
  --private=$HOME/.autometria-licensing/keys/private.pem \
  --domains=erp.client.ru,www.erp.client.ru \
  --expires=2027-12-31 \
  --hardware-hash=HEX_FROM_CLIENT \
  --out=/secure/outbox/autometria.lic
```

Доставить файл на клиент:

```text
storage/framework/licensing/autometria.lic
```

---

## 3. Git / CI guards

`.gitignore` разрешает только:

- `storage/framework/licensing/public.pem`
- `storage/framework/licensing/autometria.lic.example`

Проверка перед релизом:

```bash
# должно быть пусто
git ls-files | grep -E 'private\.pem|\.lic$' || true
test ! -f dist-encoded/storage/framework/licensing/private.pem
```

---

## 4. Encode-сборка клиентского дистрибутива

См. [client-build-encoding.md](./client-build-encoding.md) и `scripts/build-encoded-dist.sh`.
