<?php
date_default_timezone_set("Asia/Bangkok");
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '0');
ini_set('error_log', 'php_errors.log');

require_once '../config/connectQrDummy.php';

class JWT
{
    /**
     * Decodes a JWT string into a PHP object.
     *
     * @param string      $jwt    The JWT
     * @param string|null $key    The secret key
     * @param bool        $verify Don't skip verification process
     *
     * @return object      The JWT's payload as a PHP object
     * @throws UnexpectedValueException Provided JWT was invalid
     * @throws DomainException          Algorithm was not provided
     *
     * @uses jsonDecode
     * @uses urlsafeB64Decode
     */
    public static function decode($jwt, $key = null, $verify = true)
    {
        $tks = explode('.', $jwt);
        if (count($tks) != 3) {
            throw new UnexpectedValueException('Wrong number of segments');
        }
        list($headb64, $bodyb64, $cryptob64) = $tks;
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
    /**
     * Converts and signs a PHP object or array into a JWT string.
     *
     * @param object|array $payload PHP object or array
     * @param string       $key     The secret key
     * @param string       $algo    The signing algorithm. Supported
     *                              algorithms are 'HS256', 'HS384' and 'HS512'
     *
     * @return string      A signed JWT
     * @uses jsonEncode
     * @uses urlsafeB64Encode
     */
    public static function encode($payload, $key, $algo = 'HS256')
    {
        $header = array('typ' => 'JWT', 'alg' => $algo);
        $segments = array();
        $segments[] = JWT::urlsafeB64Encode(JWT::jsonEncode($header));
        $segments[] = JWT::urlsafeB64Encode(JWT::jsonEncode($payload));
        $signing_input = implode('.', $segments);
        $signature = JWT::sign($signing_input, $key, $algo);
        $segments[] = JWT::urlsafeB64Encode($signature);
        return implode('.', $segments);
    }
    /**
     * Sign a string with a given key and algorithm.
     *
     * @param string $msg    The message to sign
     * @param string $key    The secret key
     * @param string $method The signing algorithm. Supported
     *                       algorithms are 'HS256', 'HS384' and 'HS512'
     *
     * @return string          An encrypted message
     * @throws DomainException Unsupported algorithm was specified
     */
    public static function sign($msg, $key, $method = 'HS256')
    {
        $methods = array(
            'HS256' => 'sha256',
            'HS384' => 'sha384',
            'HS512' => 'sha512',
        );
        if (empty($methods[$method])) {
            throw new DomainException('Algorithm not supported');
        }
        return hash_hmac($methods[$method], $msg, $key, true);
    }
    /**
     * Decode a JSON string into a PHP object.
     *
     * @param string $input JSON string
     *
     * @return object          Object representation of JSON string
     * @throws DomainException Provided string was invalid JSON
     */
    public static function jsonDecode($input)
    {
        $obj = json_decode($input);
        if (function_exists('json_last_error') && $errno = json_last_error()) {
            JWT::_handleJsonError($errno);
        } else if ($obj === null && $input !== 'null') {
            throw new DomainException('Null result with non-null input');
        }
        return $obj;
    }
    /**
     * Encode a PHP object into a JSON string.
     *
     * @param object|array $input A PHP object or array
     *
     * @return string          JSON representation of the PHP object or array
     * @throws DomainException Provided object could not be encoded to valid JSON
     */
    public static function jsonEncode($input)
    {
        $json = json_encode($input);
        if (function_exists('json_last_error') && $errno = json_last_error()) {
            JWT::_handleJsonError($errno);
        } else if ($json === 'null' && $input !== null) {
            throw new DomainException('Null result with non-null input');
        }
        return $json;
    }
    /**
     * Decode a string with URL-safe Base64.
     *
     * @param string $input A Base64 encoded string
     *
     * @return string A decoded string
     */
    public static function urlsafeB64Decode($input)
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $input .= str_repeat('=', $padlen);
        }
        return base64_decode(strtr($input, '-_', '+/'));
    }
    /**
     * Encode a string with URL-safe Base64.
     *
     * @param string $input The string you want encoded
     *
     * @return string The base64 encode of what you passed in
     */
    public static function urlsafeB64Encode($input)
    {
        return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
    }
    /**
     * Helper method to create a JSON error.
     *
     * @param int $errno An error number from json_last_error()
     *
     * @return void
     */
    private static function _handleJsonError($errno)
    {
        $messages = array(
            JSON_ERROR_DEPTH => 'Maximum stack depth exceeded',
            JSON_ERROR_CTRL_CHAR => 'Unexpected control character found',
            JSON_ERROR_SYNTAX => 'Syntax error, malformed JSON'
        );
        throw new DomainException(
            isset($messages[$errno])
            ? $messages[$errno]
            : 'Unknown JSON error: ' . $errno
        );
    }
}

$token = new JWT();
$key = "TokenJWT_BMI_ICT";

header('Content-Type: application/json');

if (!isset($dbhandle) || !$dbhandle) {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal koneksi database dummy']);
    exit;
}

$amount = null;
$description = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!isset($data['amount']) || $data['amount'] === '' || !is_numeric($data['amount'])) {
        http_response_code(400);
        echo json_encode(['error' => 'amount is required in payload']);
        exit;
    }

    $amount = $data['amount'];
    $description = isset($data['description']) ? $data['description'] : '';
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($_GET['amount']) || $_GET['amount'] === '' || !is_numeric($_GET['amount'])) {
        http_response_code(400);
        echo json_encode(['error' => 'amount is required as query parameter']);
        exit;
    }

    $amount = $_GET['amount'];
    $description = isset($_GET['description']) ? $_GET['description'] : '';
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use GET or POST request.']);
    exit;
}

$vaNumber = '111111' . str_pad(mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
$amount = strval((int)$amount);

try {
    $transactionId = str_pad(mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
    $data = [
        "accountNo" => "5080010295",
        "amount" => $amount,
        "mitraCustomerId" => "ISLAMIC CENTER SMG451061",
        "transactionId" => $transactionId,
        "tipeTransaksi" => "MTR-GENERATE-QRIS-DYNAMIC",
        "vano" => strval($vaNumber)
    ];

    $secretKey = 'TokenJWT_BMI_ICT';
    $jwtToken = JWT::encode($data, $secretKey);

    $url = 'http://103.23.103.43/qris/lazizmu_diy/server.php?token=' . urlencode($jwtToken);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'cURL Error: ' . $error]);
        exit;
    }

    curl_close($ch);

    $responseData = json_decode($response, true);

    if (!$responseData) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Invalid response from QRIS server', 'serverResponse' => $response]);
        exit;
    }

    $serverResponse = $responseData;

    if (isset($responseData['transactionDetail']['transactionQrId'])) {
        $transactionQrId = $responseData['transactionDetail']['transactionQrId'];
        $responseData['transactionQrId'] = $transactionQrId;
        $responseData['vano'] = $vaNumber;
        $responseData['amount'] = $amount;
        $responseData['status'] = 'pending';
        $responseData['serverResponse'] = $serverResponse;
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Transaction QR ID tidak ditemukan dalam response',
            'serverResponse' => $serverResponse
        ]);
        exit;
    }

    if (isset($responseData['transactionDetail']['rawQrData'])) {
        $rawQrData = $responseData['transactionDetail']['rawQrData'];
        $responseData['rawQrData'] = $rawQrData;
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Qris content tidak ditemukan dalam response']);
        exit;
    }

    $requestJson = json_encode($data);
    $responseJson = json_encode($responseData);
    $accountNo = $data['accountNo'];
    $mitraCustomerId = $data['mitraCustomerId'];

    $stmt = $dbhandle->prepare("INSERT INTO qr_dummy (
            vano, amount, qris_id, qris_content, transaction_id,
            account_no, mitra_customer_id, description, status, paid_flag,
            request_payload, response_payload, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 0, ?, ?, NOW(), NOW())");

    if (!$stmt) {
        throw new Exception('Failed to prepare dummy insert: ' . mysqli_error($dbhandle));
    }

    $stmt->bind_param(
        "sdssssssss",
        $vaNumber,
        $amount,
        $transactionQrId,
        $rawQrData,
        $transactionId,
        $accountNo,
        $mitraCustomerId,
        $description,
        $requestJson,
        $responseJson
    );
    $stmt->execute();
    $stmt->close();

    $responseData['id'] = mysqli_insert_id($dbhandle);

    echo json_encode($responseData);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
    exit;
} finally {
    if (isset($dbhandle)) {
        mysqli_close($dbhandle);
    }
}
