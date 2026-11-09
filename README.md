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
- `bin/create-user.php`, `bin/unlock.php` — пользователи админки; `bin/studio-content.php` — перенос текстов
  сида в БД прода по манифесту (см. «Перенос текстов сида в БД прода»).
- `tests/` — `run.php` (assert; в конце подключает `studio-content.php`), `pw/*.js` (Playwright).
- `docs/` — `OWNER-ACTIONS.md` (что может сделать только владелец), `SEO-OWNER-GUIDE.md` (Вебмастер, Бизнес,
  Search Console, отзывы, чек-лист после деплоя), `owner-questionnaire.md` (анкета владельцу, ключи `fact_*`).
- `docs/studio/` — журнал студийной доводки (ветка `studio`): `task.md` (постановка), `plan.md` и `tasks.json`
  (задачи), `notes.md` (решения и грабли по кругам), `scores.md` (баллы панелей), `facts.md` (факты с источниками),
  `strategy.md`, `competitors.md`, `wordstat.md`, `content-manifest.json` (манифест для `bin/studio-content.php`),
  `copy/` (черновики с `[ПРОВЕРИТЬ: …]`, на сайт не попадают), `audit/`, `seo/`, `critic/`.
  Скрины, замеры и стенды кругов лежат в `var/studio/` (вне git).

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

## Посадочные страницы

Четыре страницы услуг, список и slug — константа `PAGES` в `app/bootstrap.php` (тексты — `db/seed-pages.sql`,
шаблон — `app/tpl/home.php` + `app/tpl/landing.php`). Маршрут решает `public/index.php`:

- `/{slug}/` — 200, если страница опубликована; иначе 404. Опубликована = непусты её title, description,
  H1 и обязательный ответ анкеты (`/asfaltirovanie/` — `fact_asphalt_works`, `/blagoustroystvo/` — `fact_blago_works`);
- `/{slug}` без слэша — 301 на `/{slug}/` (query отбрасывается); неизвестный slug, заглавные буквы, вложенные пути — 404;
- `/sitemap.xml` — главная и опубликованные посадочные.

Тексты: «Настройки» → «Посадочные» (title, description, H1, подводка; сверху — что опубликовано и чего
не хватает), ответы владельца — «Настройки» → «Анкета» (`docs/owner-questionnaire.md`), коды Вебмастера
и Search Console — «Метрика и вебмастера». Сохраняются сразу, без черновика.

## Тесты

```sh
RDS_DB=/tmp/x.sqlite php tests/run.php          # файл не должен существовать; свой стенд на :8081 — один run.php за раз
RDS_DB=/tmp/o.sqlite php -d upload_max_filesize=12M -d post_max_size=13M -S 127.0.0.1:8080 -t public public/router.php &
RDS_DB=/tmp/o.sqlite NODE_PATH=<каталог с playwright-core> node tests/pw/funnel.js
RDS_DB=/tmp/o.sqlite NODE_PATH=<каталог с playwright-core> ONLY=F node tests/pw/funnel.js   # одна секция воронки (A–H)
#   то же для a11y, brand, call, draft, fold, grid, launch, lead, metrika, overflow, price, reveal, rent, seo, tails, upload —
#   каждый на своей свежей БД и своём стенде (тесты пишут в БД стенда)
```

- `run.php` проверяет и маркер-гейт: в HTML `/`, посадочных, `privacy.php`, `consent.php` и 404 нет
  `[ПРОВЕРИТЬ`, `[УКАЗАТЬ`, `[NEED`, `TODO`, `lorem`, `XXX`. В конце он гоняет `tests/studio-content.php`: перенос
  по манифесту на свежей БД и конфликт на БД, где ключ правили вручную.
- `funnel.js` — воронка заявки на 360/390/768/1440 с главной и посадочных: CTA → форма, `tel:` или WhatsApp,
  отправка форм → «Спасибо» и строка в `leads`, цели Метрики; `ONLY=<буква>` запускает одну секцию.
- Chromium для Playwright: `PW_CHROME=<путь к chrome>` (по умолчанию — путь из `executablePath` в каждом тесте).
- Стенд для тестов — без `php -d zlib.output_compression=On`: с этим флагом `php -S` ломает статику
  (Chromium: `net::ERR_INVALID_HTTP_RESPONSE` на картинках и шрифтах).
- `tests/oracle.php` (сверка DOM главной с тегом `static-final`) удалён 2026-11-09: на ветке `studio` главная
  сознательно ушла от того эталона. Тег `static-final` оставлен как точка истории.

## Настройки, которые заполняет заказчик (админка)

- **Реквизиты** (ИНН, ОГРН, юр. адрес) и **срок хранения заявок** — в сиде стоят значения с прода
  (подтвердить может только владелец, `docs/OWNER-ACTIONS.md` §1). Пустое поле → на сайте заглушка `[УКАЗАТЬ …]`,
  в админке предупреждение.
- **`notify_email`** — почта для уведомлений о заявках. **Только почтовый ящик на хостинге
  в РФ (B3/B7).** Иностранные ящики (gmail и т.п.) запрещены — ПДн заявок уйдут за рубеж.
  Пусто — письма не отправляются, заявки видны только в админке. Проверить страну ящика
  программно нельзя — это ответственность заказчика.
- **ID Яндекс Метрики** (`metrika_id`, только владелец). Пусто — на сайте нет ни баннера,
  ни скрипта Метрики. С ID — cookie-баннер «Принять / Отклонить»; Метрика грузится только
  после «Принять», в политике появляется раздел о Метрике. Перед включением нужна
  отдельная юридическая проверка (см. `LEGAL-HOSTING-RESEARCH.md`).
- `base_url` — в сиде `https://regiondorstroy.com/`; при смене домена адрес sitemap в `public/robots.txt`
  правится вручную.

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
    location ~ ^/[a-z0-9-]+/?$ { try_files $uri $uri/ /index.php; }   # посадочные: /slug/, 301 и 404 — в PHP
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

www → без www на VPS — отдельным `server { listen 443 ssl; server_name www.<host>; return 301 https://<host>$request_uri; }`
(в `.htaccess` для Apache это уже есть).

Apache-хостинг: docroot → `public/` (там `.htaccess`: `ErrorDocument 404`, rewrite
`sitemap.xml` и посадочных, www → без www, `Options -Indexes`). Если docroot сместить нельзя — положить в `var/`
`.htaccess` с `Require all denied` и `chmod 0700 var`.

### Timeweb (виртуальный хостинг, развёрнуто 2026-10-27)

Аккаунт `co511959`, сервер `vh460.timeweb.ru` (СПб, RU), временный адрес `https://co511959.tw1.ru/`.
Apache за nginx, PHP через suEXEC; лимиты загрузки по умолчанию 5000M — менять не нужно.
`.htaccess` переводит http → https по `%{ENV:HTTPS}` / `X-Forwarded-Proto` (`%{HTTPS}` за прокси всегда off — цикл).

- Раскладка (с 2026-10-28): сайт в панели — директория `regiondorstroy`; код в `~/regiondorstroy/{app,bin,db}`,
  webroot — **обычная папка** `~/regiondorstroy/public_html` (содержимое `public/` из репозитория),
  БД `~/regiondorstroy/var/site.sqlite` (создаётся первым запросом). Без симлинков: при изоляции сайта
  доступ ограничен директорией сайта.
- **Права без записи для группы** (папки 755, файлы 644): suEXEC отказывается запускать PHP из
  group-writable файлов/папок и отдаёт пустой 500 на любой `.php` (так сайт лежал 2026-10-27…28).
  Локально umask 002 даёт 664/775, поэтому в rsync обязателен `--chmod`.
- PHP сайта выбирается в панели (Сайты → версия PHP): поставить **8.3**. В консоли — `/opt/php83/bin/php`
  (`php` по умолчанию 8.2).
- Обновление с машины разработчика (БД и загруженные фото не трогаются):
  ```
  K='ssh -i ~/.ssh/id_ed25519_timeweb'; H=co511959@vh460.timeweb.ru
  rsync -az --delete --chmod=D755,F644 -e "$K" app bin db $H:regiondorstroy/
  rsync -az --delete --chmod=D755,F644 --exclude 'assets/uploads/' -e "$K" public/ $H:regiondorstroy/public_html/
  ```
- Владелец админки: `ssh co511959@vh460.timeweb.ru`, затем
  `cd regiondorstroy && /opt/php83/bin/php bin/create-user.php <логин> owner`.
- Бэкап БД: Timeweb копирует всё ежедневно (30 дней); вручную — `~/regiondorstroy/var/site.sqlite`.

### Шаги M7: выкладка посадочных (человек, после «да»)

Перед rsync:

1. В админке на проде черновик **опубликован или отменён** (старый черновик хранит прежние ссылки
   `#fleet`/`#materials` и при публикации вернёт их в меню и услуги).
2. Сертификат покрывает `www.regiondorstroy.com` (иначе 301 с www не дойдёт до браузера).
3. «Настройки» → «SEO»: `base_url` = `https://regiondorstroy.com/` (иначе canonical и sitemap укажут на чужой адрес).

Новые ключи и ссылки меню БД прода получит сама при первом запросе (`app/db.php` → `db/seed-pages.sql`).
После выкладки:

```sh
curl -sI https://www.regiondorstroy.com/                      # 301 → https://regiondorstroy.com/
curl -sI https://regiondorstroy.com/arenda-spetstekhniki      # 301 → …/arenda-spetstekhniki/
curl -sI https://regiondorstroy.com/arenda-spetstekhniki/     # 200
curl -sI https://regiondorstroy.com/asfaltirovanie/           # 404 (до ответа в анкете)
curl -sI https://regiondorstroy.com/admin                     # 301 → /admin/
curl -s  https://regiondorstroy.com/sitemap.xml | grep -c '<loc>'   # 3
E=$(curl -sI https://regiondorstroy.com/ | tr -d '\r' | sed -n 's/^[Ee][Tt][Aa][Gg]: //p')   # ETag главной
curl -sI -H "If-None-Match: $E" https://regiondorstroy.com/ | head -1                      # HTTP/… 304
```

Дальше — `docs/SEO-OWNER-GUIDE.md` (Вебмастер, Search Console, sitemap).

### Перенос текстов сида в БД прода (`bin/studio-content.php`)

rsync тексты в БД прода не меняет: они живут в `settings` и списках, заказчик правит их в админке.
Скрипт берёт `docs/studio/content-manifest.json` (old = прежний сид, new = новый) и пишет значение, только
если на проде сейчас old. Если заказчик значение уже правил, скрипт отмечает конфликт и показывает оба
текста, а значение не трогает. Отсутствующие ключи добавляет. Без `--apply` ничего не пишет; вся запись идёт
одной транзакцией. Код выхода: 0 — конфликтов нет, 2 — есть конфликты, 1 — ошибка.
Перед запуском сделать бэкап БД (`VACUUM INTO`) и опубликовать или отменить черновик в админке: скрипт
предупредит, если черновик есть (его публикация затрёт правки списков).

Манифест rsync из §Timeweb не везёт (`docs/` не выкладывается) — скопировать отдельно, вне webroot:

```sh
K='ssh -i ~/.ssh/id_ed25519_timeweb'; H=co511959@vh460.timeweb.ru
rsync -azR --chmod=D755,F644 -e "$K" docs/studio/content-manifest.json $H:regiondorstroy/
ssh -i ~/.ssh/id_ed25519_timeweb $H
cd regiondorstroy
/opt/php83/bin/php bin/studio-content.php            # сухой прогон: применено / уже новое / конфликт / добавлено
/opt/php83/bin/php bin/studio-content.php --apply    # запись
```

`--db=<путь>` — другая БД (по умолчанию как в `app/db.php`: `RDS_DB` или `var/site.sqlite`),
`--manifest=<путь>` — другой манифест. Конфликты решаются вручную в админке.

## Источник дизайна

Дизайн ведётся в **Claude Design**, проект `РегионДорСтрой — дизайн сайта`
(id `de7e6e79-40b1-439a-ad97-b17122492ed1`). Разметка и стили главной перенесены 1:1 в
`app/tpl/home.php`; исходный перенос — тег `static-final` (с тех пор главная доработана, см. `docs/studio/`).
