# Учёт работы специалистов

Веб-приложение для управления проверкой досье кандидатов: распределение списков между
специалистами, учёт выработки и зарплаты, выборочный контроль качества, чат и журнал действий.

## Роли

| Роль | Возможности |
|------|-------------|
| **Специалист** (`employee`) | Личный кабинет с расчётным листом, явка (открыть/закрыть день), проверка назначенных досье (с причинами доработки), ввод операций (визы), рейтинг, чат, уведомления |
| **Контролёр** (`controller`) | Выборочная проверка анкет (8% за прошлый день), типы ошибок, рейтинг, чат |
| **Менеджер проекта** (`manager`) | Загрузка списков (docx/xlsx/csv), распределение/перераспределение досье (пачками, dual-list), отчёт с прогрессом + Excel, должности, страны, табель, контроль, журнал |
| **Администратор** (`admin`) | Всё вышеперечисленное + сотрудники, тарифы, операции, доплаты, причины доработок, настройки |

## Модель зарплаты

```
Гарантия  = (оклад должности × ставка + надбавка) × (отработано / норма дней)
Заработок = сделка (анкеты по тарифу страны + операции) + фикс-подработки (пропорц. времени)
К выплате = max(Гарантия, Заработок) − штрафы, но НЕ НИЖЕ Гарантии
```

- Тарифы стран: простые 50 ₽ / остальные 70 ₽ (по умолчанию) / сложные 90 ₽.
- Анкеты к оплате — только назначенные менеджером и отмеченные проверенными.
- Штраф за повторную ошибку одного типа: ×1 → ×1.5 → ×2 (плато), счётчик сбрасывается помесячно.
- Явка (открытые дни) автоматически попадает в табель.

## Структура проекта

```
app/
  Core/         Router, Database (PDO: SQLite/MySQL), Auth (сессии+CSRF), View, helpers
  Controllers/  обработчики запросов по разделам
  Services/     бизнес-логика: PayrollService (ЗП), SamplingService (выборка 8%),
                PenaltyService (эскалация штрафов), ListParser (разбор списков),
                Audit (журнал), Xlsx (выгрузка Excel), Tariff, RatingService, …
  Views/        шаблоны; partials/ — переиспользуемые (picker причин, чат-виджет)
  routes.php    таблица маршрутов
  bootstrap.php автозагрузка, конфиг, сессия
config/         config.php (драйвер БД и пр.)
database/       migrate.php (схема), seed.php (+seed_comments.php) — начальные данные
public/         index.php (точка входа), assets/style.css, .htaccess
storage/        database.sqlite, uploads/ (вложения чата) — вне public
tools/php/      портативный PHP 8.3 для Windows (для start.bat)
```

## Запуск (Windows)

```
start.bat            ← создаст БД при первом запуске и откроет http://localhost:8000
```

Вручную: `tools\php\php.exe -S localhost:8000 -t public router.php`
(перед первым запуском: `tools\php\php.exe database\migrate.php && tools\php\php.exe database\seed.php`).

### Демо-учётки

| Роль | Логин | Пароль |
|------|-------|--------|
| Администратор | `admin` | `admin123` |
| Менеджер | `manager` | `manager123` |
| Контролёр | `control` | `control123` |
| Специалист | `petrov` | `petrov123` |
| Специалист (Call-центр 2/2) | `sidorova` | `sidorova123` |

## Развёртывание в интернете

1. Сервер с PHP 8 и СУБД (рекомендуется **PostgreSQL** для прод; поддерживаются также MySQL/MariaDB и SQLite); document root → `public/`.
2. В `config/config.php` переключите `driver` на `pgsql` (или `mysql`), либо задайте переменные окружения
   `DB_DRIVER=pgsql DB_HOST=… DB_PORT=5432 DB_NAME=uchet DB_USER=uchet DB_PASS=…`.
3. `php database/migrate.php && php database/seed.php` (миграция идемпотентна; на проде `seed.php` — только на пустой БД).
4. Включите HTTPS. `storage/` и `config/` лежат вне `public/` и недоступны по URL.

### PDF «как в Word» (визы) на сервере

Выгрузка ходатайств в PDF конвертирует заполненный DOCX-бланк целиком, поэтому вид
совпадает с Word. Нужен один из движков (определяется автоматически):

- **Microsoft Word** — если сервер на Windows и Office установлен;
- **LibreOffice** (бесплатно, для Linux) + склейщик PDF и шрифты:

```bash
# Debian/Ubuntu
sudo apt install -y libreoffice-writer poppler-utils   # poppler-utils = pdfunite (склейка в один PDF)
sudo apt install -y ttf-mscorefonts-installer          # Times New Roman и др. (важно для вида бланка)
fc-cache -f
```

Если нет ни Word, ни LibreOffice — кнопка PDF откатывается на HTML-печать из браузера.
Если LibreOffice есть, а склейщика (pdfunite/gs/qpdf) нет — вместо одного PDF выгрузится
ZIP с отдельными PDF по каждому ходатайству.

### PostgreSQL (рекомендуется для продакшена)

```sql
CREATE DATABASE uchet ENCODING 'UTF8';
CREATE USER uchet WITH PASSWORD '…';
GRANT ALL PRIVILEGES ON DATABASE uchet TO uchet;
```
```bash
DB_DRIVER=pgsql DB_HOST=127.0.0.1 DB_NAME=uchet DB_USER=uchet DB_PASS=… php database/migrate.php
DB_DRIVER=pgsql … php database/seed.php
```
Драйвер выбирается одной настройкой; код единый (слой `App\Core\Database` сам подбирает синтаксис:
`SERIAL`/`RETURNING id`, `string_agg`, `TIMESTAMP`, приведение дат к тексту для `substr`).
SQLite остаётся дефолтом для локального запуска/демо (включён режим WAL — параллельные записи).
Расширение `pdo_pgsql` уже включено в `tools/php/php.ini`; на сервере убедитесь, что в вашем PHP оно есть.

## Ключевые механики

- **Списки на проверку**: менеджер загружает docx/xlsx — парсер вытаскивает рег. номера
  `КОД-НОМЕР/ГОД` (например `VNM-12538/26`); код страны определяет тариф.
- **Чат**: личные и групповые (группы создаёт админ), вложения до 10 МБ; на десктопе —
  всплывающий виджет в правом нижнем углу с вкладкой уведомлений и тостами о новом.
- **Журнал** (`/audit`): автоматическая запись всех действий (кто, что, когда, IP) + Excel.
