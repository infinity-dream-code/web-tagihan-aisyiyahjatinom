<?php

/**
 * Callback QRIS top up SD Aisyiyah Jatinom (prefix VA 752088).
 * Upload file ini ke folder pushNotif/ di server QRIS, bersama cabang 752088 di pushNotif.php.
 */

$wsUrl = getenv('WS_TAGIHAN_AISYIYAH_JATINOM_URL') ?: 'http://103.23.103.43/WEB_TAGIHAN_PROJECT/WS_TAGIHAN_AISYIYAH_JATINOM/index.php';
$wsUrl = rtrim($wsUrl, '?&');
$wsUrl .= (strpos($wsUrl, '?') !== false ? '&' : '?').'path=qris-topup-paid';

$payload = [
    'vano' => isset($vano) ? (string) $vano : '',
    'amount' => isset($amount) ? (int) $amount : 0,
    'transactionQrId' => isset($transactionQrId) ? (string) $transactionQrId : '',
];

$ch = curl_init($wsUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$curlErr = curl_error($ch);
$http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$decoded = is_string($response) ? json_decode($response, true) : null;
$ok = is_array($decoded) && !empty($decoded['status']);

if (!$ok) {
    error_log('aisyiyahJatinom QRIS topup failed: '.$curlErr.' http='.$http.' body='.substr((string) $response, 0, 500));
    if (!headers_sent()) {
        http_response_code($http >= 400 ? $http : 500);
        header('Content-Type: application/json');
    }
    echo json_encode([
        'responseCode' => '01',
        'responseMessage' => is_array($decoded) ? ($decoded['message'] ?? 'Gagal kredit saldo') : 'Gagal menghubungi WS tagihan',
        'responseTimestamp' => date('Y-m-d H:i:s'),
    ]);
    exit;
}

if (!headers_sent()) {
    header('Content-Type: application/json');
}
echo json_encode([
    'responseCode' => '00',
    'responseMessage' => $decoded['message'] ?? 'Success',
    'responseTimestamp' => date('Y-m-d H:i:s'),
    'data' => $decoded['data'] ?? $payload,
]);
