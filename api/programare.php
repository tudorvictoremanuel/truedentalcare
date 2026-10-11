<?php
/*
 * True Dental Care — primește cererile de programare de pe site și le trimite pe e-mail.
 *
 * Ce poți modifica:
 *   $DESTINATAR  adresa la care ajung cererile
 *   $EXPEDITOR   o adresă de pe domeniul site-ului (ex. site@truedentalcare.ro).
 *                Folosește o adresă de pe domeniul tău, altfel e-mailurile pot ajunge în Spam.
 */
$DESTINATAR = 'programari.truedental@gmail.com';
$EXPEDITOR  = 'site@truedentalcare.ro';
$MAX_PE_ORA = 5; // câte cereri acceptăm de la aceeași adresă IP într-o oră

date_default_timezone_set('Europe/Bucharest');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function raspuns($cod, $date) {
    http_response_code($cod);
    echo json_encode($date, JSON_UNESCAPED_UNICODE);
    exit;
}

function camp($nume, $max = 200) {
    $v = isset($_POST[$nume]) ? trim((string) $_POST[$nume]) : '';
    $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v);
    return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}

function lungime($s) {
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    raspuns(405, ['ok' => false]);
}

// Protecție anti-spam: câmpul ascuns „website” trebuie să rămână gol,
// iar un om nu completează formularul în mai puțin de 2 secunde.
if (camp('website') !== '') {
    raspuns(200, ['ok' => true]);
}
$t = (int) camp('_t', 20);
if ($t > 0 && (microtime(true) * 1000 - $t) < 2000) {
    raspuns(200, ['ok' => true]);
}

$nume    = camp('nume', 80);
$telefon = camp('telefon', 20);
$acord   = camp('acord', 10);

$erori = [];
if (lungime($nume) < 2) {
    $erori['nume'] = 'Scrieți-ne numele, ca să știm pe cine căutăm.';
}
$cifre = preg_replace('/\D/', '', $telefon);
if (!preg_match('/^\+?[0-9 ().-]{9,20}$/', $telefon) || strlen($cifre) < 9) {
    $erori['telefon'] = 'Numărul pare incomplet. Exemplu: 07xx xxx xxx.';
}
if ($acord === '') {
    $erori['acord'] = 'Avem nevoie de acordul dumneavoastră ca să vă putem suna.';
}
if ($erori) {
    raspuns(422, ['ok' => false, 'errors' => $erori]);
}

// Limită de cereri pe oră pentru aceeași adresă IP (adresa nu se păstrează mai mult de o zi).
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$fisier = rtrim(sys_get_temp_dir(), '/\\') . '/tdc_programari_' . md5(__DIR__) . '.json';
$acum = time();
$jurnal = [];
if (is_readable($fisier)) {
    $jurnal = json_decode((string) @file_get_contents($fisier), true) ?: [];
}
foreach ($jurnal as $k => $momente) {
    $jurnal[$k] = array_values(array_filter((array) $momente, function ($m) use ($acum) { return $acum - $m < 86400; }));
    if (!$jurnal[$k]) unset($jurnal[$k]);
}
$cheie = hash('sha256', $ip . __DIR__);
$recente = array_filter($jurnal[$cheie] ?? [], function ($m) use ($acum) { return $acum - $m < 3600; });
if (count($recente) >= $MAX_PE_ORA) {
    raspuns(429, ['ok' => false]);
}
$jurnal[$cheie][] = $acum;
@file_put_contents($fisier, json_encode($jurnal), LOCK_EX);

$cod = camp('lead_id', 64);
if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $cod)) {
    $cod = bin2hex(random_bytes(8));
}
$motiv = camp('motiv') !== '' ? camp('motiv') : camp('interes');
$sursa = implode(' · ', array_filter([camp('utm_source'), camp('utm_medium'), camp('utm_campaign'), camp('referrer')]));

$text = implode("\n", [
    'Cerere nouă de programare de pe truedentalcare.ro',
    '',
    'Nume: ' . $nume,
    'Telefon: ' . $telefon,
    'Motivul vizitei: ' . ($motiv !== '' ? $motiv : '-'),
    'Când preferă să fie sunat: ' . (camp('interval') !== '' ? camp('interval') : '-'),
    'Mesaj: ' . (camp('mesaj', 600) !== '' ? camp('mesaj', 600) : '-'),
    '',
    'Pagina: ' . camp('page'),
    'Formular: ' . camp('form_location'),
    'Sursa vizitei: ' . ($sursa !== '' ? $sursa : '-'),
    'Cod cerere: ' . $cod,
    'Primită la: ' . date('d.m.Y H:i'),
]);

$subiect = '=?UTF-8?B?' . base64_encode('Programare nouă: ' . $nume) . '?=';
$antete = implode("\r\n", [
    'From: True Dental Care <' . $EXPEDITOR . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
]);

$trimis = @mail($DESTINATAR, $subiect, $text, $antete, '-f' . $EXPEDITOR);
if (!$trimis) {
    $trimis = @mail($DESTINATAR, $subiect, $text, $antete);
}
if (!$trimis) {
    raspuns(500, ['ok' => false]);
}
raspuns(200, ['ok' => true, 'lead_id' => $cod]);
