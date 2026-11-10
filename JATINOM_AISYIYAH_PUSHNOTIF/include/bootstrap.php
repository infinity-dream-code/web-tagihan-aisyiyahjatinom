<?php

function load_env($path)
{
    if (!is_file($path)) {
        throw new RuntimeException('File .env tidak ditemukan');
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('File .env tidak bisa dibaca');
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $quote = $value[0];
            if (substr($value, -1) === $quote) {
                $value = substr($value, 1, -1);
            }
        }
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function env($key, $default = '')
{
    $value = getenv($key);
    if ($value === false && isset($_ENV[$key])) {
        $value = $_ENV[$key];
    }
    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return $value;
}

function env_bool($key, $default = false)
{
    $value = strtolower(trim((string) env($key, $default ? '1' : '0')));

    return in_array($value, array('1', 'true', 'yes', 'on'), true);
}

function db_connect()
{
    $port = (int) env('DB_PORT', '3306');
    $mysqli = mysqli_init();
    if ($mysqli === false) {
        throw new RuntimeException('Gagal koneksi database');
    }
    $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 8);
    try {
        $connected = $mysqli->real_connect(
            (string) env('DB_HOST'),
            (string) env('DB_USER'),
            (string) env('DB_PASS'),
            (string) env('DB_NAME'),
            $port > 0 ? $port : 3306
        );
    } catch (Throwable $e) {
        throw new RuntimeException('Gagal koneksi database');
    }
    if (!$connected || $mysqli->connect_error) {
        throw new RuntimeException('Gagal koneksi database');
    }
    $mysqli->set_charset('utf8mb4');

    return $mysqli;
}

function append_token_url($url, $token)
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    $encoded = urlencode((string) $token);
    if (preg_match('/(?:\?|&)token=$/', $url)) {
        return $url . $encoded;
    }
    if (preg_match('/(?:\?|&)token=/', $url)) {
        return preg_replace('/((?:\?|&)token=)[^&]*/', '$1' . $encoded, $url, 1);
    }
    $join = strpos($url, '?') !== false ? '&' : '?';

    return $url . $join . 'token=' . $encoded;
}

function forward_get($url)
{
    $result = array(
        'ok' => false,
        'code' => 0,
        'error' => '',
        'body' => '',
    );
    if (trim((string) $url) === '') {
        $result['error'] = 'URL kosong';

        return $result;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result['code'] = $code;
    $result['error'] = $error;
    $result['body'] = is_string($body) ? $body : '';
    $result['ok'] = $error === '' && $code >= 200 && $code < 300;

    return $result;
}
