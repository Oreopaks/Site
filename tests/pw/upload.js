// Playwright: загрузка фото (ADR M7). PNG и JPEG → живая карточка показывает assets/uploads/*.webp ещё до «Сохранить»;
// не-картинка → ошибка у поля; «Сохранить» → «Опубликовать» → фото на /. Загруженные тестом файлы удаляет.
// Стенд: RDS_DB=<db> php -d upload_max_filesize=12M -d post_max_size=13M -S 127.0.0.1:8080 -t public public/router.php
// Запуск: RDS_DB=<db> NODE_PATH=/home/oleg/traning/web/node_modules node tests/pw/upload.js
const { chromium } = require('playwright-core');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1:8080/';
const DB = process.env.RDS_DB;
if (!DB) { console.error('нужен RDS_DB — та же БД, что у стенда'); process.exit(2); }
const LOGIN = 'pwup' + Date.now() % 100000, PASS = 'pw upload password 1';
execFileSync('php', [path.join(__dirname, '../../bin/create-user.php'), LOGIN, 'owner'], { input: PASS + '\n', env: { ...process.env, RDS_DB: DB } });
// картинка 640×480 из GD в память — без временных файлов
const image = (fn) => execFileSync('php', ['-r', '$i=imagecreatetruecolor(640,480); imagefill($i,0,0,imagecolorallocate($i,200,120,40)); ' + fn + '($i);']);
const PUBLIC = path.join(__dirname, '../../public');
const UP = path.join(PUBLIC, 'assets/uploads');
const hadDir = fs.existsSync(UP), made = [];

(async () => {
  let fail = 0;
  const check = (name, ok, why = '') => { console.log(name + (ok ? ' OK' : ' FAIL ' + why)); if (!ok) fail++; };
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || '/home/oleg/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome' });
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce' });
  page.on('response', async (r) => { if (r.url().includes('a=upload')) { const j = await r.json().catch(() => ({})); if (j.img) made.push(j.img); } });
  await page.goto(BASE + 'admin/');
  await page.fill('[name=login]', LOGIN);
  await page.fill('[name=password]', PASS);
  await Promise.all([page.waitForNavigation(), page.click('form.login button')]);

  try {
    const pub = {};
    for (const [t, file] of [['materials', { name: 'щебень.png', mimeType: 'image/png', buffer: image('imagepng') }],
      ['fleet_cards', { name: 'каток.jpg', mimeType: 'image/jpeg', buffer: image('imagejpeg') }]]) {
      await page.goto(BASE + 'admin/?p=content&t=' + t);
      const row = page.locator('.row').first();
      const st = row.locator('.up-st');
      // не-картинка → ошибка у поля, поле img не меняется
      const before = await row.locator('[name=img]').inputValue();
      await row.locator('input[type=file]').setInputFiles({ name: 'прайс.txt', mimeType: 'text/plain', buffer: Buffer.from('это не картинка') });
      await page.waitForFunction((el) => el.classList.contains('err'), await st.elementHandle());
      check(`${t}: .txt → у поля «${await st.innerText()}»`, (await st.innerText()).startsWith('Это не картинка') && await row.locator('[name=img]').inputValue() === before);

      const bad = await st.innerText();
      await row.locator('input[type=file]').setInputFiles(file);
      await page.waitForFunction(([el, bad]) => el.textContent.startsWith('Загружено') || (el.classList.contains('err') && el.textContent !== bad), [await st.elementHandle(), bad]);
      const img = await row.locator('[name=img]').inputValue();
      const pv = await row.locator('.pv img').evaluate((el) => [el.getAttribute('src'), el.complete && el.naturalWidth]);
      check(`${t}: ${file.name} → «${await st.innerText()}», поле ${img}, карточка src=${pv[0]} ширина=${pv[1]} (до «Сохранить»)`,
        /^assets\/uploads\/[0-9a-f]{32}\.webp$/.test(img) && pv[0] === '/' + img && pv[1] > 0);
      await Promise.all([page.waitForNavigation(), row.locator('form.fields button').click()]);
      check(`${t}: сохранено`, await page.locator('.toast p.ok').isVisible());
      pub[t] = img;
    }
    await Promise.all([page.waitForNavigation(), page.click('form[action*="a=publish"] button')]);
    await page.goto(BASE);
    for (const [t, sel] of [['materials', '#materials'], ['fleet_cards', '#fleet']]) {
      const ok = await page.locator(`${sel} img[src="${pub[t]}"]`).evaluate((el) => { el.loading = 'eager'; el.scrollIntoView(); return el.decode().then(() => el.naturalWidth); }).catch((e) => String(e));
      check(`${t}: на / ${sel} img src=${pub[t]} ширина=${ok}`, ok > 0);
    }
  } finally {
    for (const img of made) fs.rmSync(path.join(PUBLIC, img), { force: true });
    if (!hadDir && fs.existsSync(UP) && !fs.readdirSync(UP).length) fs.rmdirSync(UP);
    console.log(`удалено загруженных файлов: ${made.length}`);
  }

  await browser.close();
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
