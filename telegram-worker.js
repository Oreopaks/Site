/**
 * Cloudflare Worker — прокси для отправки заявок с сайта в Telegram.
 *
 * Зачем: токен бота нельзя класть в клиентский JS (виден в исходниках).
 * Worker хранит токен в секретах и пересылает заявку в чат.
 *
 * ── Деплой (бесплатный план Cloudflare) ──────────────────────────────
 * 1. Создать бота у @BotFather → получить BOT_TOKEN.
 * 2. Узнать CHAT_ID: написать боту, открыть
 *    https://api.telegram.org/bot<BOT_TOKEN>/getUpdates → поле chat.id
 *    (для группы добавить бота в группу и взять её id, обычно с минусом).
 * 3. dash.cloudflare.com → Workers → Create → вставить этот код.
 * 4. Settings → Variables → добавить секреты:
 *      BOT_TOKEN = <токен>
 *      CHAT_ID   = <id чата>
 *      ALLOW_ORIGIN = https://YOUR-DOMAIN   (домен сайта; для теста '*')
 * 5. Скопировать URL воркера (вида https://xxx.workers.dev) и вставить
 *    в index.html → CFG.tgEndpoint.
 */

export default {
  async fetch(request, env) {
    const cors = {
      'Access-Control-Allow-Origin': env.ALLOW_ORIGIN || '*',
      'Access-Control-Allow-Methods': 'POST, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type',
    };
    if (request.method === 'OPTIONS') return new Response(null, { headers: cors });
    if (request.method !== 'POST') return new Response('Method Not Allowed', { status: 405, headers: cors });

    let data;
    try { data = await request.json(); } catch { return json({ ok: false, error: 'bad json' }, 400, cors); }

    // Honeypot: боты заполняют скрытое поле — молча игнорируем.
    if (data._hp) return json({ ok: true }, 200, cors);

    const esc = (s) => String(s ?? '—').replace(/[<&>]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c]));
    const lines = [
      `<b>🚧 Заявка с сайта</b>`,
      `<b>Тип:</b> ${esc(data.type)}`,
      data.material ? `<b>Материал:</b> ${esc(data.material)}` : '',
      data.service ? `<b>Услуга:</b> ${esc(data.service)}` : '',
      `<b>Имя:</b> ${esc(data.name)}`,
      `<b>Телефон:</b> ${esc(data.phone)}`,
      data.comment ? `<b>Комментарий:</b> ${esc(data.comment)}` : '',
    ].filter(Boolean);

    const tgRes = await fetch(`https://api.telegram.org/bot${env.BOT_TOKEN}/sendMessage`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ chat_id: env.CHAT_ID, text: lines.join('\n'), parse_mode: 'HTML' }),
    });

    if (!tgRes.ok) return json({ ok: false, error: 'telegram failed' }, 502, cors);
    return json({ ok: true }, 200, cors);
  },
};

function json(obj, status, cors) {
  return new Response(JSON.stringify(obj), {
    status,
    headers: { 'Content-Type': 'application/json', ...cors },
  });
}
