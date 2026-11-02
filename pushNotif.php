<?php

date_default_timezone_set('Asia/Bangkok');
ini_set('display_errors', '1');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__.'/php_errors.log');

require_once __DIR__ . '/../config/connectQrisIslamicCenter.php';

// Data container untuk menampung detail transaksi JWT yang didecode
$logHitData = [
    'transaction_id'    => null,
    'transaction_qr_id' => null,
    'vano'              => null,
    'amount'            => null,
];

// Inisialisasi output buffer untuk menangkap response body yang dikirim ke client / PG
ob_start();

// Shutdown function untuk otomatis menyimpan log hit ke database server 56 (qris_islamic_center)
register_shutdown_function(function() use (&$logHitData) {
    $responseBody = ob_get_contents();

    $httpStatus = http_response_code();
    if (!$httpStatus) {
        $httpStatus = 200;
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
    $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($clientIp, ',') !== false) {
        $clientIp = trim(explode(',', $clientIp)[0]);
    }

    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        $rawInput = json_encode(['GET' => $_GET, 'POST' => $_POST]);
    }

    $headers = [];
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else {
        foreach ($_SERVER as $key => $value) {
            if (substr($key, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))))] = $value;
            }
        }
    }
    $headerJson = json_encode($headers);

    $resCode = null;
    $resMsg  = null;
    $decodedRes = json_decode($responseBody, true);
    if (is_array($decodedRes)) {
        $resCode = $decodedRes['responseCode'] ?? null;
        $resMsg  = $decodedRes['responseMessage'] ?? null;
    }

    $statusStr = 'FAILED';
    if ($httpStatus >= 200 && $httpStatus < 300) {
        if ($resCode === '00' || $resCode === null) {
            $statusStr = 'SUCCESS';
        }
    }

    try {
        $conn = getQrisLogDbConnection();
        if ($conn) {
            ensureLogPushnotifTableExists($conn);

            $stmt = mysqli_prepare($conn, "INSERT INTO `log_hit_pushnotif` 
                (`transaction_id`, `transaction_qr_id`, `vano`, `amount`, `http_method`, `client_ip`, `request_header`, `request_payload`, `response_code`, `response_message`, `http_status`, `response_body`, `status`, `created_at`) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

            if ($stmt) {
                $txId     = $logHitData['transaction_id'] ?? null;
                $txQrId   = $logHitData['transaction_qr_id'] ?? null;
                $vanoVal  = $logHitData['vano'] ?? null;
                $amtVal   = $logHitData['amount'] !== null ? (float)$logHitData['amount'] : null;

                mysqli_stmt_bind_param(
                    $stmt,
                    'sssdssssssiss',
                    $txId,
                    $txQrId,
                    $vanoVal,
                    $amtVal,
                    $method,
                    $clientIp,
                    $headerJson,
                    $rawInput,
                    $resCode,
                    $resMsg,
                    $httpStatus,
                    $responseBody,
                    $statusStr
                );
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }
    } catch (Throwable $e) {
        error_log("Failed to insert pushnotif log: " . $e->getMessage());
    }
});

class JWT
{
    public static function decode($jwt, $key = null, $verify = true)
    {
        $tks = explode('.', $jwt);
        if (count($tks) != 3) {
            throw new UnexpectedValueException('Wrong number of segments');
        }
        [$headb64, $bodyb64, $cryptob64] = $tks;
        if (null === ($header = JWT::jsonDecode(JWT::urlsafeB64Decode($headb64)))) {
            throw new UnexpectedValueException('Invalid segment encoding');
        }
        if (null === $payload = JWT::jsonDecode(JWT::urlsafeB64Decode($bodyb64))) {
            throw new UnexpectedValueException('Invalid segment encoding');
        }
        $sig = JWT::urlsafeB64Decode($cryptob64);
        if ($verify) {
            if (empty($header->alg)) {
                throw new DomainException('Empty algorithm');
            }
            if ($sig != JWT::sign("$headb64.$bodyb64", $key, $header->alg)) {
                throw new UnexpectedValueException('Signature verification failed');
            }
        }

        return $payload;
    }

    public static function encode($payload, $key, $algo = 'HS256')
    {
        $header = ['typ' => 'JWT', 'alg' => $algo];
        $segments = [];
        $segments[] = JWT::urlsafeB64Encode(JWT::jsonEncode($header));
        $segments[] = JWT::urlsafeB64Encode(JWT::jsonEncode($payload));
        $signing_input = implode('.', $segments);
        $signature = JWT::sign($signing_input, $key, $algo);
        $segments[] = JWT::urlsafeB64Encode($signature);

        return implode('.', $segments);
    }

    public static function sign($msg, $key, $method = 'HS256')
    {
        $methods = ['HS256' => 'sha256', 'HS384' => 'sha384', 'HS512' => 'sha512'];
        if (empty($methods[$method])) {
            throw new DomainException('Algorithm not supported');
        }

        return hash_hmac($methods[$method], $msg, $key, true);
    }

    public static function jsonDecode($input)
    {
        $obj = json_decode($input);
        if (function_exists('json_last_error') && $errno = json_last_error()) {
            JWT::_handleJsonError($errno);
        } elseif ($obj === null && $input !== 'null') {
            throw new DomainException('Null result with non-null input');
        }

        return $obj;
    }

    public static function jsonEncode($input)
    {
        $json = json_encode($input);
        if (function_exists('json_last_error') && $errno = json_last_error()) {
            JWT::_handleJsonError($errno);
        } elseif ($json === 'null' && $input !== null) {
            throw new DomainException('Null result with non-null input');
        }

        return $json;
    }

    public static function urlsafeB64Decode($input)
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($input, '-_', '+/'));
    }

    public static function urlsafeB64Encode($input)
    {
        return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
    }

    private static function _handleJsonError($errno)
    {
        $messages = [
            JSON_ERROR_DEPTH => 'Maximum stack depth exceeded',
            JSON_ERROR_CTRL_CHAR => 'Unexpected control character found',
            JSON_ERROR_SYNTAX => 'Syntax error, malformed JSON',
        ];
        throw new DomainException(isset($messages[$errno]) ? $messages[$errno] : 'Unknown JSON error: '.$errno);
    }
}

header('Content-Type: application/json');
$token = null;

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'POST';

if ($requestMethod === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    $token = $data['token'] ?? null;

    if (empty($token)) {
        $token = $_GET['token'] ?? null;
    }
} elseif ($requestMethod === 'GET') {
    $token = $_GET['token'] ?? null;
}
if (empty($token)) {
    http_response_code(400);
    echo json_encode([
        'responseCode' => '01',
        'responseMessage' => 'Token tidak ditemukan',
        'responseTimestamp' => date('Y-m-d H:i:s'),
    ]);
    exit;
}

$secretKey = 'TokenJWT_BMI_ICT';

try {
    $decoded = JWT::decode($token, $secretKey, ['HS256']);

    $responseCode = $decoded->responseCode ?? null;
    $responseMessage = $decoded->responseMessage ?? null;
    $transactionId = $decoded->transactionId ?? null;
    $responseTimestamp = $decoded->responseTimestamp ?? null;
    $data = $decoded->data ?? null;

    $logHitData['transaction_id'] = $transactionId;
    if (isset($data)) {
        $logHitData['vano'] = $data->vano1 ?? null;
        $logHitData['amount'] = $data->amount ?? null;
        $logHitData['transaction_qr_id'] = $data->transactionQrId ?? null;
    }
    if ($responseCode === '00') {
        $vano = $data->vano1;
        $amount = $data->amount;
        $accountNo = $data->accountNo;
        $transactionQrId = $data->transactionQrId;
        $description = $data->description ?? '';
        $enamDigitVano = substr($vano, 0, 6);

        if ($enamDigitVano === '508001') {
            require_once __DIR__.'/../config/connectWalisongo.php';
            require_once __DIR__.'/pushNotif/walisongo.php';
        } elseif ($enamDigitVano === '070240') {
            require_once __DIR__.'/../config/connectAlberr.php';
            require_once __DIR__.'/pushNotif/walisongo.php';
        } elseif ($enamDigitVano === '880088') {
            require_once __DIR__.'/../config/connectMarkaz.php';
            require_once __DIR__.'/pushNotif/markaz.php';
        } elseif ($enamDigitVano === '111111') {
            require_once __DIR__.'/../config/connectQrDummy.php';
            require_once __DIR__.'/pushNotif/dummy.php';
        } elseif ($enamDigitVano === '751000') {
            // WEB_TAGIHAN_DEMO → forward DEMO_INSTALLMENT (103.23.103.43), log DB (103.23.103.36)
            require_once __DIR__.'/pushNotif/demoInstallment.php';
        } elseif ($enamDigitVano === '797737') {
            require_once __DIR__.'/pushNotif/switchingCustomerBsim.php';
        } elseif ($enamDigitVano === '757777' || $enamDigitVano === '222222') {
            require_once __DIR__.'/../config/connectTagihanCicilan.php';
            require_once __DIR__.'/pushNotif/tagihanCicilan.php';
        } elseif ($enamDigitVano === '752088') {
            require_once __DIR__.'/pushNotif/aisyiyahJatinom.php';
        } else {
            require_once __DIR__.'/../config/connectMetaschool.php';
            require_once __DIR__.'/pushNotif/metaschool.php';
        }
    } else {
        http_response_code(400);
        echo json_encode([
            'responseCode' => '01',
            'responseMessage' => $responseMessage,
            'responseTimestamp' => date('Y-m-d H:i:s'),
        ]);
        exit;
    }
} catch (Throwable $th) {
    error_log('pushNotif.php exception: '.$th->getMessage().' @ '.$th->getFile().':'.$th->getLine());
    if (! headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode([
        'responseCode' => '01',
        'responseMessage' => 'pushNotif error: '.$th->getMessage(),
        'responseTimestamp' => date('Y-m-d H:i:s'),
    ]);
    exit;
}
