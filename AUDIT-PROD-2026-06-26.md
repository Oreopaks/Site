# Аудит готовности к продакшену — РегионДорСтрой

**Дата:** 2026-06-26
**Метод:** multi-agent (8 измерений → adversarial verify каждого finding → completeness critic). 47 агентов, 1.06M токенов.
**Живой сайт:** https://oreopaks.github.io/Site/ (отдаёт 200 — уже задеплоен с плейсхолдерами).

## Вердикт: НЕ готов. Структура крепкая, но абсолютные URL, юр-реквизиты, валидация форм и доставка лидов сломаны/неполны.

Итого подтверждено: **4 critical · 18 high · 16 medium** + ~35 low/info. Ложных срабатываний: 0.

---

## Scorecard по измерениям

| Измерение | Оценка | Состояние |
|-----------|:---:|-----------|
| SEO / metadata | 4/10 ⛔ | Структура есть (title, desc, OG, Twitter, JSON-LD, sitemap, robots, 1×h1, alt у всех img), но **все абсолютные URL = `YOUR-DOMAIN`** + ловушка subpath `/Site/`. После фикса → 9. |
| Accessibility | 5/10 | alt/aria-label/consent есть; модалка не управляется с клавиатуры (нет focus-trap/Esc/role), контраст серых < 4.5:1, нет `<main>`/skip-link. |
| Performance | 6/10 | preconnect + `font-display:swap` + width/height у img есть; но logo 91KB рендерится в 84px, шрифты render-blocking (6 weights), карта Яндекс грузит сотни KB, `will-change` на 48 элементах. |
| Security | 5/10 | Worker НЕ светит токен и эскейпит HTML (хорошо); но endpoint открыт без rate-limit/лимита размера, CORS по умолчанию `*`, нет CSP, 2 `target=_blank` без noopener. |
| Functionality | 4/10 | honeypot/якоря/привязка модалки корректны; но `novalidate` + нет `checkValidity()` → пустые лиды; WhatsApp-фолбэк блокируется попап-блокером, а экран успеха показывается → **лид теряется молча**. |
| Production-readiness | 4/10 | Живой, но плейсхолдеры в проде, ловушка base-path `/Site/`, нет 404.html/.nojekyll/theme-color/apple-touch-icon. |
| HTML/CSS standards | 7/10 | В основном валидно; `frameborder` устарел, медиа-запросы на хрупких substring-селекторах, inline-style sprawl (316 шт). |
| Legal / 152-ФЗ | 3/10 ⛔ | Consent-чекбокс есть, но **не enforced**; ИНН/ОГРН/адрес = `[УКАЗАТЬ]`; нет раскрытия трансграничной передачи (Telegram/WhatsApp). |

---

## CRITICAL (4) — блокеры

1. **`YOUR-DOMAIN` × 8 живёт в проде** → canonical, og:url, og:image, JSON-LD url/image, robots Sitemap, sitemap `<loc>`×2 — все битые. Соц-превью без картинки, canonical невалиден, sitemap/robots не находятся.
   `index.html:9,14,15,23,24; robots.txt:5; sitemap.xml:5,10`
2. **Ловушка base-path `/Site/`.** Это project page → живой URL `https://oreopaks.github.io/Site/`. Даже если заменить `YOUR-DOMAIN` на голый хост — все URL укажут на apex (`/`), картинки 404. Нужен `/Site/` ВО ВСЕХ абсолютных URL (или custom domain + CNAME → apex).
3. **Consent не enforced + валидация мёртвая.** `novalidate` на обеих формах, нет `checkValidity()` → персональные данные уходят без согласия и без имени/телефона. 152-ФЗ нарушение + мусорные лиды.
   `index.html:395,476 (forms); 559,572 (handlers); 410,481 (чекбоксы)`
4. **Юр-оператор не идентифицируем.** `[УКАЗАТЬ ИНН/ОГРН/адрес]` в privacy.html:31-32 и футере index.html:433. Без реальных реквизитов privacy недействителен.

---

## HIGH (18) — до запуска

**SEO (7):** каждый из 8 `YOUR-DOMAIN` = отдельный битый тег (canonical, og:url, og:image, JSON-LD image/url, robots, sitemap home, sitemap privacy).
**A11y (3):** модалка без focus-trap/Esc/role=dialog; серый body-текст (#8a8a8a/#888/#9a9a9a/#6a6a6a) < 4.5:1; золотой eyebrow #b59200 на светлом < 4.5:1.
**Perf (1):** logo-opt.png 91KB → 84px (favicon.svg уже векторизует «Р», можно SVG <10KB, экономия ~85KB).
**Security (1):** Worker — открытый endpoint без rate-limit и лимита размера ввода (CORS не защита).
**Functionality (2):** мёртвая валидация (см. critical); WhatsApp-фолбэк попап-блокируется + done() на фейле → лид потерян молча.
**Prod (2):** ловушка base-path при наивной замене; 5 юр-плейсхолдеров.
**Legal (2):** оператор не идентифицируем; **не раскрыта трансграничная передача** в Telegram/WhatsApp (ст.12 152-ФЗ — нужно уведомление в Роскомнадзор).

---

## MEDIUM (16)

- privacy.html `noindex`, но в sitemap → конфликт сигналов (убрать из sitemap).
- meta description 166 симв → обрежется в выдаче (цель ~150-158).
- нет `<main>` и skip-link.
- бургер без `aria-expanded`/`aria-controls`.
- success-экран не анонсируется (`role=status aria-live`).
- шрифты render-blocking, 6 weights (часть не используется).
- `will-change` на 48 элементах, не сбрасывается.
- карта Яндекс = сотни KB сторонних → click-to-load фасад.
- Worker CORS по умолчанию `*` (fail-closed нужно).
- нет error-UI: любой путь ведёт на success-экран.
- нет 404.html (отдаётся англ. дефолт GitHub).
- `frameborder` устарел.
- медиа-запросы на substring inline-style селекторах (хрупко).
- нет cookie/обработка-уведомления при сторонних эмбедах (Яндекс.Карты, Google Fonts).

---

## Пробелы, которые нашёл completeness-critic (9) — никто из 8 не поймал

1. **Контент-блэкаут без JS/IntersectionObserver.** `els.forEach(hide)` (line 624) ставит opacity:0 на ~48 блоков ДО `new IntersectionObserver` без feature-check/try-catch. Нет IO (старый Safari/webview) или исключение раньше → весь контент (hero, услуги, контакты) навсегда невидим. **Fail-closed риск.**
2. **Лид-флоу без email + сломанный desktop UX.** README обещает email на Rdstroiy@yandex.ru, реально — только Telegram (если tgEndpoint) или wa.me. Сейчас tgEndpoint пуст → КАЖДАЯ заявка открывает wa.me. На десктопе без сессии WhatsApp Web → QR-стена → юзер уходит, а экран показывает «Заявка отправлена». Нет mailto/Formspree фолбэка.
3. **Не проверено, что внешние аккаунты живые.** Весь контакт идёт на wa.me/79807691111, t.me/regiondorstroy, тел 8 980 769-11-11. Если номер не в WhatsApp — дефолтный путь молча умирает для всех.
4. **Нет print/PDF стиля.** B2B/муниципальные клиенты печатают. Тёмные секции → либо чёрная заливка, либо (фон выкл) белый-на-белом текст исчезает, включая контакты.
5. **Worker не воспроизводимо деплоится + нет мониторинга.** Деплой копипастом, нет wrangler.toml/CI. Если Worker 502 → лид исчезает без алерта.
6. **Не проверена адаптивность <640px.** Минимальный media 640px, `overflow-x:hidden` маскирует обрезку. Длинные uppercase-заголовки («РЕГИОНДОРСТРОЙ», «Асфальтоукладчики») на 320px обрезаются невидимо.
7. **Несоответствие контактов в карточке «О компании».** index.html:338 показывает только `тел. 49-11-11` (городской, без `tel:`, без мобильного) — все остальные блоки ведут с моб 8 980 769-11-11.
8. **Нет mobile/PWA chrome.** Нет `theme-color`, apple-touch-icon, manifest. iOS «на экран Домой» → пустая иконка.
9. **Стейт меню + хардкод года.** Бургер не закрывается по resize/outside-click (>900px остаётся висеть). `© 2026` и «Редакция от 2026 г.» захардкожены.

---

## Что чинится механически (могу прямо сейчас, ~25 правок)

URL-замены (после решения по домену), enforce consent + валидация, focus-trap/Esc/scroll-lock модалки, контраст серых/золотого, logo→SVG, IntersectionObserver fail-open, 404.html + .nojekyll + theme-color + apple-touch-icon, sitemap noindex-конфликт, meta description, frameborder, scroll-margin-top, noopener, sandbox iframe, `<main>`+skip-link, aria-expanded бургера, error-UI форм, print-стиль, overflow-wrap заголовков, карточка «О компании», бургер resize-close, click-to-load карта.

## Что требует ТВОИХ данных/решений

- **Домен:** `oreopaks.github.io/Site` (subpath) ИЛИ custom domain + CNAME (apex, чище)?
- **ИНН / ОГРН / юр.адрес** ООО «РегионДорСтрой» — вписать в privacy + футер + JSON-LD.
- **Доставка лидов:** деплой Telegram Worker / добавить email-фолбэк (Formspree/Web3Forms) / оставить WhatsApp-only?
- **Подтвердить:** wa.me/79807691111 и t.me/regiondorstroy — реальные рабочие аккаунты? Тел отвечают?
- **Аналитика:** Yandex Metrika ставить?
- **Раскрытие трансграничной передачи** + уведомление Роскомнадзор (если включаешь Telegram/WhatsApp).
