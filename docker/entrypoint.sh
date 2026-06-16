#!/usr/bin/env bash
# Точка входа контейнера: ждём БД (если PostgreSQL), применяем миграцию,
# при первом запуске грузим стартовые данные, чиним права на storage и стартуем Apache.
set -e
cd /var/www/html

mkdir -p storage/uploads/docs storage/tmp
# LibreOffice (headless) требует доступного для записи HOME
export HOME=/tmp

# Ждём PostgreSQL, если выбран этот драйвер
if [ "${DB_DRIVER:-sqlite}" = "pgsql" ]; then
  echo "Ожидание PostgreSQL ${DB_HOST:-db}:${DB_PORT:-5432}..."
  for i in $(seq 1 30); do
    if php -r '$h=getenv("DB_HOST")?:"db";$p=(int)(getenv("DB_PORT")?:5432);exit(@fsockopen($h,$p,$e,$s,2)?0:1);'; then
      echo "PostgreSQL доступен."; break
    fi
    sleep 2
    [ "$i" = "30" ] && echo "ВНИМАНИЕ: не дождались БД, пробую продолжить..."
  done
fi

echo "Применение миграции схемы..."
php database/migrate.php || echo "ВНИМАНИЕ: миграция завершилась с ошибкой."

# Стартовые данные — только при первом запуске (пустая таблица users) и если не отключено SEED_DEMO=0
if [ "${SEED_DEMO:-1}" != "0" ]; then
  USERS=$(php -r 'require "app/bootstrap.php"; use App\Core\Database; try { echo (int)Database::scalar("SELECT COUNT(*) FROM users"); } catch (\Throwable $e) { echo 0; }' 2>/dev/null || echo 0)
  if [ "${USERS:-0}" = "0" ]; then
    echo "Первый запуск: загрузка стартовых данных (демо-вход admin / admin123 — СМЕНИТЕ ПАРОЛЬ!)..."
    php database/seed.php || echo "ВНИМАНИЕ: загрузка стартовых данных завершилась с ошибкой."
  fi
fi

# Любые файлы, созданные при миграции/сидинге (ключ, sqlite), должны принадлежать Apache
chown -R www-data:www-data storage || true

exec "$@"
