# РегионДорСтрой — сайт

Лендинг дорожно-строительной компании ООО «РегионДорСтрой» (г. Орёл) на PHP 8 + SQLite
с админкой. Только PHP stdlib и vanilla JS: без composer, npm и CDN.

## Раскладка

- `public/` — webroot: `index.php` (главная), `send.php` (приём заявок), `privacy.php`,
  `consent.php` (текст согласия; POST — счётчик выбора cookie-баннера), `404.php`,
  `sitemap.php` (`/sitemap.xml`), `admin/` (админка), `assets/`, `.htaccess` (Apache),
  `router.php` (только для `php -S`).
- `app/` — код и шаблоны (`app/tpl/home.php` — PHP-копия прежнего `index.html`).
- `db/schema.sql`, `db/seed.sql` — схема и стартовые данные (тексты и цены сайта).
- `var/` — БД `site.sqlite` и журнал `app.log`; создаётся сам, в git не попадает.
- `bin/create-user.php`, `bin/unlock.php` — пользователи админки.
- `tests/` — `run.php` (assert), `oracle.php` (DOM главной против тега `static-final`),
  `pw/*.js` (Playwright).

Рантайму нужны расширения `pdo_sqlite`, `json`, `session`, `gd` с WebP (загрузка фото; `exif` желательно —
поворот снимков с телефона). Проверка: `php -m`.

## Запуск локально

```sh
php -d upload_max_filesize=12M -d post_max_size=13M -S 127.0.0.1:8080 -t public public/router.php
```

БД `var/site.sqlite` создаётся из схемы и сида при первом запросе. Другую БД можно
указать через переменную окружения `RDS_DB=/путь/к/файлу.sqlite`.

## Пользователи админки

Админка: `/admin/`. Роли: `owner` (владелец), `manager` (менеджер), `editor` (редактор).

```sh
php bin/create-user.php owner owner      # пароль (≥ 12 символов) — первой строкой со STDIN
php bin/unlock.php owner                 # снять блокировку после 5 неверных паролей
```

Остальных пользователей владелец заводит в админке («Пользователи»).

## Черновик, публикация, корзина, фото

- Правки списков в «Контенте» (услуги, техника, материалы, меню, цифры и т.д.) попадают в **общий
  черновик**, а не на сайт. Плашка сверху (на телефоне — внизу экрана): число изменений, кто и когда
  правил, «Посмотреть страницу» (`?p=preview` — главная на данных черновика).
- «Опубликовать» и «Отменить всё» — только `owner` и `manager`; `editor` правит и ждёт публикации.
  После каждой правки — «Отменить» (один шаг назад); у изменённой строки — «Вернуть как было».
- Удалённое после публикации лежит в «Корзине» 30 дней; «Восстановить» возвращает строку
  в черновик (на сайт — после «Опубликовать»).
- Настройки (`?p=settings`) черновика не имеют — сохраняются сразу на сайт.
- Фото: JPG, PNG или WebP до 10 МБ, до 24 Мп. Сервер перекодирует в WebP (ширина ≤ 1200)
  в `public/assets/uploads/<случайное имя>.webp`, исходный файл не хранит.

## Тесты

```sh
RDS_DB=/tmp/x.sqlite php tests/run.php          # файл не должен существовать; свой стенд на :8081
RDS_DB=/tmp/o.sqlite php -d upload_max_filesize=12M -d post_max_size=13M -S 127.0.0.1:8080 -t public public/router.php &
php tests/oracle.php                             # на свежей БД → OK 0 diffs
RDS_DB=/tmp/o.sqlite NODE_PATH=<каталог с playwright-core> node tests/pw/lead.js
#   то же для price, metrika, overflow, grid, draft, upload — каждый на своей свежей БД
```

## Настройки, которые заполняет заказчик (админка)

- **Реквизиты** (ИНН, ОГРН, юр. адрес) и **срок хранения заявок** — пока пусты: на сайте
  стоят заглушки `[УКАЗАТЬ …]`, в админке висит предупреждение.
- **`notify_email`** — почта для уведомлений о заявках. **Только почтовый ящик на хостинге
  в РФ (B3/B7).** Иностранные ящики (gmail и т.п.) запрещены — ПДн заявок уйдут за рубеж.
  Пусто — письма не отправляются, заявки видны только в админке. Проверить страну ящика
  программно нельзя — это ответственность заказчика.
- **ID Яндекс Метрики** (`metrika_id`, только владелец). Пусто — на сайте нет ни баннера,
  ни скрипта Метрики. С ID — cookie-баннер «Принять / Отклонить»; Метрика грузится только
  после «Принять», в политике появляется раздел о Метрике. Перед включением нужна
  отдельная юридическая проверка (см. `LEGAL-HOSTING-RESEARCH.md`).
- `base_url` — после появления домена; в `public/robots.txt` адрес sitemap правится вручную.

## Блокеры запуска (вне кода)

Подробно — `LEGAL-HOSTING-RESEARCH.md`.

- **B1** — отозвать токен Telegram-бота в @BotFather (он остался в истории git).
- **B5** — ИНН, ОГРН, адрес места нахождения ООО (без них политика и подвал с заглушками).
- **B6** — срок хранения заявок.
- **B8** — уведомление РКН по ст. 22 152-ФЗ: без него принимать заявки нельзя.
- Также: B2 (хостинг), B3 (подтверждение, что сайт, БД и почта физически в РФ),
  B4 (домен), B7 (ящик-получатель на хостинге в РФ).

## Деплой (документация; выполняет человек после «да»)

Ничего из этого раздела не настроено. VPS: nginx + php-fpm, webroot — `public/`.

```nginx
server {
    listen 80; server_name _;
    client_max_body_size 13m;          # фото в админке до 10 МБ
    root /srv/rds/public;              # допущение: путь
    index index.php;
    location ~ /\. { deny all; }
    location / { try_files $uri $uri/ =404; }
    location = /sitemap.xml { rewrite ^ /sitemap.php last; }
    error_page 404 /404.php;
    location ~ ^/(index|send|privacy|consent|404|sitemap|admin/index)\.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ \.php$ { return 404; }
}
```

Шаги:

1. `sudo apt install php8.3-fpm php8.3-gd nginx` (php8.3-sqlite3 — если `pdo_sqlite` нет в `php -m`;
   `exif` обычно уже в php8.3-common).
2. Репозиторий в `/srv/rds`.
3. `sudo mkdir -p var && sudo chown www-data:www-data var && sudo chmod 0700 var`;
   `sudo mkdir -p public/assets/uploads && sudo chown www-data:www-data public/assets/uploads` (фото из админки).
   Лимиты загрузки (фото до 10 МБ): в пуле php-fpm (`/etc/php/8.3/fpm/pool.d/www.conf`)
   `php_admin_value[upload_max_filesize] = 12M`, `php_admin_value[post_max_size] = 13M`;
   в `server {}` nginx — `client_max_body_size 13m;` (по умолчанию 1m — фото не загрузятся).
4. `sudo -u www-data php bin/create-user.php owner owner` — БД создаётся от имени www-data,
   и php-fpm сможет писать в неё и в WAL.
5. В админке: `base_url` → домен; `robots.txt` — заменить адрес sitemap.
   `notify_email` — только ящик на хостинге в РФ.
6. HTTPS — после появления домена (B4). Проверка: `curl -I https://<host>/var/site.sqlite` → 404.
   Если TLS снимает прокси перед nginx — в `location` с php добавить `fastcgi_param HTTPS on;`,
   иначе cookie админки уйдёт без `Secure` (`X-Forwarded-Proto` код не читает: без доверенного прокси подделывается).

Apache-хостинг: docroot → `public/` (там `.htaccess`: `ErrorDocument 404`, rewrite
`sitemap.xml`, `Options -Indexes`). Если docroot сместить нельзя — положить в `var/`
`.htaccess` с `Require all denied` и `chmod 0700 var`.

## Источник дизайна

Дизайн ведётся в **Claude Design**, проект `РегионДорСтрой — дизайн сайта`
(id `de7e6e79-40b1-439a-ad97-b17122492ed1`). Разметка и стили главной перенесены 1:1 в
`app/tpl/home.php`; эталон — тег `static-final` (`tests/oracle.php` сверяет DOM).
