<?php

class JWT
{
    public static function encode($payload, $key, $algo = 'HS256')
    {
        $header = array('typ' => 'JWT', 'alg' => $algo);
        $segments = array(
            self::base64UrlEncode(json_encode($header)),
            self::base64UrlEncode(json_encode($payload)),
        );
        $signingInput = implode('.', $segments);
        $segments[] = self::base64UrlEncode(self::sign($signingInput, $key, $algo));

        return implode('.', $segments);
    }

    public static function decode($jwt, $key, $verify = true)
    {
        $parts = explode('.', (string) $jwt);
        if (count($parts) !== 3) {
            throw new UnexpectedValueException('Wrong number of segments');
        }

        list($headb64, $bodyb64, $cryptob64) = $parts;
        $header = json_decode(self::base64UrlDecode($headb64));
        $payload = json_decode(self::base64UrlDecode($bodyb64));
        if (!is_object($header) || !is_object($payload)) {
            throw new UnexpectedValueException('Invalid segment encoding');
        }
        if (empty($header->alg)) {
            throw new UnexpectedValueException('Empty algorithm');
        }

        if ($verify) {
            $signature = self::base64UrlDecode($cryptob64);
            $expected = self::sign($headb64 . '.' . $bodyb64, $key, $header->alg);
            if (!hash_equals($expected, $signature)) {
                throw new UnexpectedValueException('Signature verification failed');
            }
        }

        return $payload;
    }

    private static function sign($message, $key, $algo)
    {
        $methods = array(
            'HS256' => 'sha256',
            'HS384' => 'sha384',
            'HS512' => 'sha512',
        );
        if (!isset($methods[$algo])) {
            throw new DomainException('Algorithm not supported');
        }

        return hash_hmac($methods[$algo], $message, (string) $key, true);
    }

    private static function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode($data)
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($data, '-_', '+/'));
    }
}
