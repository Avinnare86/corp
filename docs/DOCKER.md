# Развёртывание в Docker (Linux, php-fpm + nginx, за Traefik)

Образ: **PHP-FPM 8.3 + nginx** в одном контейнере (наружу HTTP `:80` — за обратным прокси Traefik).
Внутри установлен **LibreOffice** (+ шрифты) для конвертации Word/Excel → PDF (визы, описи, гарантийные письма,
предпросмотр документов СЭД). БД — **PostgreSQL** (контейнер рядом или ваш внешний сервер).

Образ **собирается автоматически в GitHub Actions** при пуше в `main` и публикуется в **GHCR**:
`ghcr.io/avinnare86/corp:latest`. В `docker-compose.yml` он подключается напрямую.

## Доступ к образу (GHCR)
Пакет GHCR по умолчанию **приватный**. Варианты:
- сделать пакет публичным: GitHub → репозиторий → Packages → `corp` → Package settings → Change visibility → Public (тогда `docker pull` без авторизации);
- или логиниться токеном с правом `read:packages`:
  ```bash
  echo <GH_TOKEN> | docker login ghcr.io -u <github_login> --password-stdin
  ```

## Запуск (приложение + PostgreSQL в Docker)
```bash
git clone https://github.com/Avinnare86/corp.git
cd corp
cp .env.example .env        # задайте DB_PASS, CORP_HOST (домен), при необходимости TZ
docker compose pull         # подтянуть образ из GHCR (или соберите локально: docker compose build)
docker compose up -d
```
Приложение слушает `:80` внутри сети; наружу его публикует **Traefik** по домену `CORP_HOST`.
Первый старт создаёт схему БД и (если `SEED_DEMO=1`) демо-данные — вход **admin / admin123**.

> ⚠️ Сразу смените пароль `admin`. Для прод-старта без демо-данных задайте `SEED_DEMO=0`.

## Traefik
Метки на сервисе `app` — **заготовка**, поправьте под свою инсталляцию:
- `CORP_HOST` (в `.env`) — домен;
- имя внешней сети Traefik (`traefik.docker.network=proxy` и блок `networks.proxy.external`);
- `entrypoints` (`websecure`) и `certresolver` (`letsencrypt`) — как у вас называются.

Сеть Traefik должна существовать (например `docker network create proxy`) и быть подключена к самому Traefik.
Порты приложения наружу **не публикуются** — весь трафик идёт через Traefik (TLS терминируется на нём).

## Свой внешний PostgreSQL (без контейнера db)
1. В `docker-compose.yml` удалите сервис `db`, блок `depends_on` и сеть `internal` у `app`.
2. Пропишите параметры вашей БД: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` (требования — UTF-8, пользователь-владелец; см. `Письмо-администратору.md`).
3. `docker compose up -d`.

## Тома и резервное копирование
- `pgdata` — данные PostgreSQL.
- `app_storage` — `secret.key` (ключ шифрования настроек) и загрузки. **Бэкапьте оба тома.**
  Потеря `secret.key` сделает нечитаемым сохранённый ключ OpenRouter (его можно ввести заново в Настройках).

## Обновление
Новый образ публикуется Actions при пуше в `main`:
```bash
docker compose pull && docker compose up -d   # миграция применится автоматически при старте
```

## Локальная сборка/проверка (без GHCR)
На машине с Docker:
```bash
docker compose up -d --build      # соберёт образ из исходников
```
Полезное: `docker compose logs -f app`, `docker compose exec app bash`, `docker compose exec app php database/migrate.php`.

## Заметки
- Конвертация в PDF идёт силами LibreOffice **внутри контейнера** — на хост ничего ставить не нужно. `PdfPreview` на Linux также использует LibreOffice (Windows-COM-путь к MS Office не задействуется).
- Сборка на GitHub Actions / `linux/amd64` — подходит для обычного x86_64-сервера.
