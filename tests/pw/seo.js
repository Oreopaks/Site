// Playwright: посадочные страницы (ADR-0009 M5) — 200, без переполнения, h1/крошки, картинки, шрифт, без JS-ошибок; ссылки с /, заявка, модалка, 404.
// Стенд: RDS_DB=<db> php -S 127.0.0.1:8080 -t public public/router.php
// Запуск: RDS_DB=<db> NODE_PATH=/home/oleg/traning/web/node_modules node tests/pw/seo.js
// Владельца не создаёт; на время теста заполняет fact_asphalt_works, fact_blago_works, fact_warranty (все 4 посадочные → 200), в finally — ''.
const { chromium } = require('playwright-core');
const { execFileSync } = require('child_process');

const BASE = process.env.BASE || 'http://127.0.0.1:8080/';
const DB = process.env.RDS_DB;
if (!DB) { console.error('нужен RDS_DB — та же БД, что у стенда'); process.exit(2); }
const php = (code, ...args) => execFileSync('php', ['-r', '$p=new PDO("sqlite:".$argv[1]);' + code, DB, ...args]).toString();
const FACTS = ['fact_asphalt_works', 'fact_blago_works', 'fact_warranty'];
const setFacts = (v) => FACTS.forEach((k) => php('$p->prepare("UPDATE settings SET value=? WHERE key=?")->execute([$argv[2], $argv[3]]);', v, k));
const PAGES = ['', 'arenda-spetstekhniki/', 'shcheben-pesok-grunt/', 'asfaltirovanie/', 'blagoustroystvo/'];

(async () => {
  let fail = 0;
  const check = (name, ok, why = '') => { console.log(name + (ok ? ' pass' : ' FAIL ' + why)); if (!ok) fail++; };
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || '/home/oleg/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome' });
  try {
    await fetch(BASE); // свежая БД создаётся стендом при первом запросе
    setFacts('PW: тестовый факт для seo.js');
    for (const w of [390, 1440]) {
      const page = await browser.newPage({ viewport: { width: w, height: 900 }, reducedMotion: 'reduce' });
      const errs = [];
      page.on('pageerror', (e) => errs.push(e.message));
      page.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
      for (const u of PAGES) {
        errs.length = 0;
        const r = await page.goto(BASE + u);
        // lazy-картинки грузятся только у вьюпорта — проходим страницу до низа
        await page.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 600) { scrollTo(0, y); await new Promise((f) => setTimeout(f, 50)); } });
        await page.waitForFunction(() => [...document.images].every((i) => i.complete), null, { timeout: 10000 }).catch(() => {});
        const v = await page.evaluate(async () => {
          await document.fonts.ready;
          return {
            sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth,
            bad: [...document.images].filter((i) => !(i.complete && i.naturalWidth > 0)).map((i) => i.getAttribute('src')),
            n: document.images.length,
            font: document.fonts.check('700 20px Oswald') && [...document.fonts].some((f) => /Oswald/.test(f.family) && f.weight === '700' && f.status === 'loaded'),
          };
        });
        const h1 = await page.locator('h1').first().isVisible();
        const crumbs = u === "" ? "—" : await page.locator('nav[aria-label="Хлебные крошки"]').isVisible();
        const ok = r.status() === 200 && v.sw <= v.cw && h1 && crumbs !== false && !v.bad.length && v.font && !errs.length;
        check(`/${u} @${w}: HTTP ${r.status()} scrollWidth=${v.sw} clientWidth=${v.cw} h1=${h1} крошки=${crumbs} img=${v.n - v.bad.length}/${v.n} Oswald=${v.font} jsErr=${errs.length}`,
          ok, JSON.stringify({ bad: v.bad, errs }));
      }
      await page.close();
    }

    const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
    const via = async (name, sel, want) => {
      await page.goto(BASE);
      const [r] = await Promise.all([page.waitForNavigation(), page.locator(sel).first().click()]);
      check(`клик ${name} на / → ${new URL(page.url()).pathname} HTTP ${r.status()}`, r.status() === 200 && new URL(page.url()).pathname === want, page.url());
    };
    await via('по меню', 'header nav[data-desknav] a[href="/arenda-spetstekhniki/"]', '/arenda-spetstekhniki/');
    await via('по карточке услуг', '[data-svc] h3 a[href="/asfaltirovanie/"]', '/asfaltirovanie/');
    await via('по подвалу', 'footer a[href="/shcheben-pesok-grunt/"]', '/shcheben-pesok-grunt/');

    await page.goto(BASE + 'shcheben-pesok-grunt/');
    const name = 'PW seo ' + Date.now();
    const form = page.locator('#contacts form');
    await form.locator('[autocomplete=name]').fill(name);
    await form.locator('[autocomplete=tel]').fill('+7 980 000-00-00');
    await form.locator('input[type=checkbox]').check();
    const [resp] = await Promise.all([page.waitForResponse((x) => x.url().includes('send.php')), form.locator('button[type=submit]').click()]);
    const svc = php('$s=$p->prepare("SELECT service FROM leads WHERE name=?"); $s->execute([$argv[2]]); echo implode("|", $s->fetchAll(PDO::FETCH_COLUMN));', name);
    check(`заявка с /shcheben-pesok-grunt/ → ${resp.url().replace(BASE, '/')} ${resp.status()}, leads.service='${svc}'`, resp.status() === 200 && svc === 'Поставка материалов', svc);

    await page.locator('header button[data-desknav]', { hasText: 'Заказать звонок' }).click();
    // сам #modalOverlay 0×0 (внутри только position:fixed) — видимость проверяем по его оверлею
    check('«Заказать звонок» открывает #modalOverlay', await page.locator('#modalOverlay > div').isVisible());

    const r404 = await page.goto(BASE + 'no-such/');
    check(`/no-such/ → HTTP ${r404.status()}`, r404.status() === 404);
    await page.close();
  } finally {
    setFacts('');
    await browser.close();
  }
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
