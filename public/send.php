<?php
// Приём заявки (контракт §4). ПДн — только в SQLite на своём сервере; IP не хранится.
require dirname(__DIR__) . '/app/bootstrap.php';

const SEND_PER_MIN = 20; // ponytail: общий лимит на сайт за минуту; первая линия — honeypot + согласие

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function out(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); out(405, ['ok' => false, 'error' => 'method']); }
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) out(400, ['ok' => false, 'error' => 'invalid']);

$f = fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';

// honeypot: боту — «успех», в БД ничего
if (!array_key_exists('website', $_POST) || $f('website') !== '') out(200, ['ok' => true]);
if (($_POST['consent'] ?? null) !== '1') out(400, ['ok' => false, 'error' => 'consent']);

$lead = [
    'source' => $f('source'), 'name' => $f('name'), 'phone' => $f('phone'),
    'service' => $f('service'), 'material' => $f('material'), 'comment' => $f('comment'),
];
$ok = in_array($lead['source'], ['calc', 'call', 'material'], true)
    && preg_match('/^.{1,80}$/suD', $lead['name'])
    && preg_match('/^[\d\s()+\-]{10,20}$/D', $lead['phone'])
    && preg_match('/^.{0,80}$/suD', $lead['service'])
    && preg_match('/^.{0,80}$/suD', $lead['material'])
    && preg_match('/^.{0,1000}$/suD', $lead['comment']);
if (!$ok) out(400, ['ok' => false, 'error' => 'invalid']);

try {
    $db = db();
    $bucket = gmdate('Y-m-d H:i');
    $db->prepare('DELETE FROM send_window WHERE bucket < ?')->execute([$bucket]);
    $st = $db->prepare('INSERT INTO send_window (bucket, n) VALUES (?, 1) ON CONFLICT(bucket) DO UPDATE SET n = n + 1 RETURNING n');
    $st->execute([$bucket]);
    if ((int)$st->fetchColumn() > SEND_PER_MIN) out(429, ['ok' => false, 'error' => 'rate']);

    $s = settings();
    $db->prepare('INSERT INTO leads (source, name, phone, service, material, comment, consent, consent_rev)
                  VALUES (:source, :name, :phone, :service, :material, :comment, 1, :rev)')
        ->execute($lead + ['rev' => $s['consent_rev']]);
    $n = (int)$db->lastInsertId();
} catch (Throwable $e) {
    error_log('send.php: ' . $e->getMessage());
    out(500, ['ok' => false, 'error' => 'server']);
}

// D12: уведомление на notify_email (только ящик в РФ); пусто — выключено. Данные пользователя — только в теле.
$to = trim($s['notify_email'] ?? '');
if ($to !== '') {
    $body = "Заявка №$n\n";
    foreach (['source' => 'Форма', 'name' => 'Имя', 'phone' => 'Телефон', 'service' => 'Услуга', 'material' => 'Материал', 'comment' => 'Комментарий'] as $k => $label)
        if ($lead[$k] !== '') $body .= "$label: {$lead[$k]}\n";
    $subj = '=?UTF-8?B?' . base64_encode("Заявка №$n") . '?=';
    if (!@mail($to, $subj, $body, "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit")) {
        try { db()->prepare('UPDATE leads SET mail_error = ? WHERE id = ?')->execute([gmdate('c') . ' mail() failed', $n]); }
        catch (Throwable $e) { error_log('send.php mail_error: ' . $e->getMessage()); }
    }
}

out(200, ['ok' => true, 'n' => $n]);
