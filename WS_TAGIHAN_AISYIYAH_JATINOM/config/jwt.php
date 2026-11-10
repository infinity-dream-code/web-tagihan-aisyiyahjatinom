<?php
class JWT {
    private $alg = "HS256";
    private $supported_algs = [
        'HS256' => 'sha256',
        'HS384' => 'sha384',
        'HS512' => 'sha512',
    ];

    public function encode($payload, $key) {
        $header = json_encode(['typ' => 'JWT', 'alg' => $this->alg]);
        $segments = [];
        $segments[] = $this->base64UrlEncode($header);
        $segments[] = $this->base64UrlEncode(json_encode($payload));
        $signing_input = implode('.', $segments);
        $signature = $this->sign($signing_input, $key);
        $segments[] = $this->base64UrlEncode($signature);
        return implode('.', $segments);
    }

    public function decode($jwt, $key) {
        $tokens = explode('.', $jwt);
        if (count($tokens) != 3) {
            return null;
        }

        list($headb64, $bodyb64, $cryptob64) = $tokens;
        $header = json_decode($this->base64UrlDecode($headb64), true);
        $payload = json_decode($this->base64UrlDecode($bodyb64), true);
        $sig = $this->base64UrlDecode($cryptob64);

        $valid = $this->verify("$headb64.$bodyb64", $sig, $key, $header['alg']);
        if (!$valid) {
            return null; // di project mas kemarin baris ini dikomentari → jadi rawan
        }

        return json_encode($payload);
    }

    private function sign($msg, $key) {
        return hash_hmac($this->supported_algs[$this->alg], $msg, $key, true);
    }

    private function verify($msg, $signature, $key, $alg) {
        $expected = $this->sign($msg, $key);
        return hash_equals($signature, $expected);
    }

    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
