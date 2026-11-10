<?php

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/include/jwt.php';
require_once __DIR__ . '/include/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function reply($http, $payload)
{
    if (!headers_sent()) {
        http_response_code($http);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload);
    exit;
}

function fail($http, $message, $extra = array())
{
    reply($http, array_merge(array(
        'responseCode' => '01',
        'responseMessage' => $message,
        'responseTimestamp' => date('Y-m-d H:i:s'),
    ), $extra));
}

function client_ip()
{
    $ip = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
    if (strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }

    return substr((string) $ip, 0, 45);
}

function read_token()
{
    if (isset($_GET['token']) && is_string($_GET['token']) && $_GET['token'] !== '') {
        return trim($_GET['token']);
    }
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $rawIn = file_get_contents('php://input');
        $dataIn = json_decode($rawIn, true);
        if (is_array($dataIn)) {
            if (isset($dataIn['token']) && is_string($dataIn['token'])) {
                return trim($dataIn['token']);
            }
            if (isset($dataIn['TOKEN']) && is_string($dataIn['TOKEN'])) {
                return trim($dataIn['TOKEN']);
            }
        }
        if (isset($_POST['token']) && is_string($_POST['token'])) {
            return trim($_POST['token']);
        }
    }

    return '';
}

function data_value($data, $names)
{
    foreach ($names as $name) {
        if (isset($data->$name) && strval($data->$name) !== '') {
            return strval($data->$name);
        }
    }

    return '';
}

function find_qris(mysqli $db, $qrisId, $transactionId, $vano)
{
    $lookups = array();
    if ($qrisId !== '') {
        $lookups[] = array('qris_id', $qrisId);
    }
    if ($transactionId !== '') {
        $lookups[] = array('transaction_id', $transactionId);
    }
    if ($vano !== '') {
        $lookups[] = array('vano', $vano);
    }

    foreach ($lookups as $lookup) {
        $sql = 'SELECT id, custid, nocust, qris_id, transaction_id, vano, amount, status, paid_flag
            FROM pwa_qris WHERE ' . $lookup[0] . ' = ? ORDER BY id DESC LIMIT 1';
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException($db->error);
        }
        $stmt->bind_param('s', $lookup[1]);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException($error !== '' ? $error : 'Gagal mencari transaksi QRIS');
        }
        $result = $stmt->get_result();
        if ($result === false) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException($error !== '' ? $error : 'Gagal membaca transaksi QRIS');
        }
        $row = $result->fetch_assoc();
        $stmt->close();
        if (is_array($row)) {
            return $row;
        }
    }

    return null;
}

function mark_qris_paid(mysqli $db, array $row, $amount)
{
    if ((int) $row['paid_flag'] === 1 || (string) $row['status'] === 'paid') {
        return 'already_paid';
    }

    $paidAmount = is_numeric($amount) && (float) $amount > 0 ? number_format((float) $amount, 2, '.', '') : $row['amount'];
    $now = date('Y-m-d H:i:s');
    $id = (int) $row['id'];
    $stmt = $db->prepare("UPDATE pwa_qris
        SET status = 'paid', paid_flag = 1, paid_at = ?, updated_at = ?, amount = ?
        WHERE id = ? AND paid_flag = 0");
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param('sssi', $now, $now, $paidAmount, $id);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error !== '' ? $error : 'Gagal mengubah status QRIS');
    }
    $changed = $stmt->affected_rows > 0;
    $stmt->close();

    return $changed ? 'newly_paid' : 'already_paid';
}

function insert_qris_log(mysqli $db, array $row, $event, $processed, $amount, $message)
{
    $now = date('Y-m-d H:i:s');
    $paymentId = (int) $row['id'];
    $qrisId = (string) $row['qris_id'];
    $transactionId = (string) $row['transaction_id'];
    $vano = (string) $row['vano'];
    $custid = (string) $row['custid'];
    $nocust = (string) $row['nocust'];
    $amountValue = is_numeric($amount) && (float) $amount > 0
        ? number_format((float) $amount, 2, '.', '')
        : number_format((float) $row['amount'], 2, '.', '');
    $paidFlag = 1;
    $responseCode = '00';
    $source = 'JATINOM_AISYIYAH/pushNotif.php';
    $ip = client_ip();
    $agent = substr((string) (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''), 0, 255);
    $request = json_encode(array(
        'nama' => (string) env('NAMA_NASABAH'),
        'mitra' => (string) env('MITRA_CUSTOMER_ID'),
        'qris_id' => $qrisId,
        'transaction_id' => $transactionId,
        'vano' => $vano,
        'amount' => $amountValue,
    ));

    $stmt = $db->prepare('INSERT INTO pwa_log_qris_push (
        payment_id, event_type, qris_id, transaction_id, vano, custid, nocust, amount,
        paid_flag, processed, response_code, response_message, request_payload, source,
        ip_address, user_agent, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param(
        'isssssssissssssss',
        $paymentId,
        $event,
        $qrisId,
        $transactionId,
        $vano,
        $custid,
        $nocust,
        $amountValue,
        $paidFlag,
        $processed,
        $responseCode,
        $message,
        $request,
        $source,
        $ip,
        $agent,
        $now
    );
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException($error !== '' ? $error : 'Gagal menulis log QRIS');
    }
    $stmt->close();
}

try {
    load_env(__DIR__ . '/.env');
} catch (Throwable $e) {
    fail(500, 'Konfigurasi .env belum siap');
}

$debug = env_bool('DEBUG', false);
if ($debug) {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

$required = array(
    'DB_HOST',
    'DB_USER',
    'DB_NAME',
    'API_KEY_ICT',
    'NAMA_NASABAH',
    'MITRA_CUSTOMER_ID',
);
foreach ($required as $key) {
    if (trim((string) env($key)) === '') {
        fail(500, 'Konfigurasi belum lengkap', $debug ? array('missing' => $key) : array());
    }
}

$token = read_token();
if ($token === '') {
    fail(400, 'Token tidak ditemukan');
}

try {
    $decoded = JWT::decode($token, env('API_KEY_ICT'), true);
} catch (Throwable $e) {
    fail(401, 'Signature verification failed');
}

$responseCode = isset($decoded->responseCode) ? strval($decoded->responseCode) : '';
$data = isset($decoded->data) ? $decoded->data : null;
$payloadClient = json_decode(json_encode($decoded), true);
if (!is_array($payloadClient)) {
    fail(400, 'Payload JWT tidak valid');
}

if ($responseCode === '00') {
    if (!is_object($data)) {
        fail(400, 'Data pembayaran kosong');
    }

    $vano = data_value($data, array('vano1', 'vano2', 'vano', 'VANO'));
    if ($vano === '' || strpos($vano, '752088') !== 0) {
        fail(400, 'Prefix VA bukan 752088');
    }

    $qrisId = data_value($data, array('transactionQrId', 'transaction_qr_id', 'qris_id'));
    $transactionId = data_value($data, array('transactionId', 'transaction_id'));
    if ($transactionId === '' && isset($decoded->transactionId)) {
        $transactionId = strval($decoded->transactionId);
    }
    $amountRaw = isset($data->amount) ? $data->amount : 0;
    $amount = is_numeric($amountRaw) ? (float) $amountRaw : 0;

    try {
        $db = db_connect();
        $row = find_qris($db, $qrisId, $transactionId, $vano);
        if (!$row) {
            $db->close();
            fail(200, 'Transaksi QRIS tidak ditemukan');
        }
        $processed = mark_qris_paid($db, $row, $amount);
        insert_qris_log(
            $db,
            $row,
            'push_notif',
            $processed,
            $amount,
            $processed === 'newly_paid' ? 'QRIS ditandai lunas' : 'QRIS sudah lunas'
        );
        $db->close();
    } catch (Throwable $e) {
        fail(500, 'Gagal mengubah status QRIS', $debug ? array('detail' => $e->getMessage()) : array());
    }
}

if (trim((string) env('API_KEY_NASABAH')) === '') {
    fail(500, 'API_KEY_NASABAH belum diisi');
}

try {
    $tokenNasabah = JWT::encode($payloadClient, env('API_KEY_NASABAH'));
} catch (Throwable $e) {
    fail(500, 'Gagal membuat token nasabah');
}

$forwardBase = trim((string) env('API_PUSHNOTIF_NASABAH'));
if ($forwardBase === '') {
    fail(200, 'API_PUSHNOTIF_NASABAH belum diisi');
}

$forward = forward_get(append_token_url($forwardBase, $tokenNasabah));
if (!$forward['ok']) {
    fail(502, 'Forward ke nasabah gagal', $debug ? array(
        'forwardError' => $forward['error'],
    ) : array());
}

$forwardJson = json_decode($forward['body'], true);
if (is_array($forwardJson)) {
    $http = $forward['code'] >= 100 ? $forward['code'] : 200;
    reply($http, $forwardJson);
}

$http = $forward['code'] >= 100 ? $forward['code'] : 200;
if (!headers_sent()) {
    http_response_code($http);
}
echo $forward['body'];
exit;
