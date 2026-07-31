# Client licensing directory

Put here before `docker compose -f docker-compose.prod.yml up`:

1. `autometria.lic` — signed license from AUTOMETRIA licensing vault  
2. `public.pem` — RSA public key (same as `storage/framework/licensing/public.pem`)

Never place `private.pem` on the client server.
