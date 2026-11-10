<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QrisService
{
    public function generate(string $vano, int $amount): array
    {
        $accountNo = trim((string) config('services.qris.account_no'));
        $mitraCustomerId = trim((string) config('services.qris.mitra_customer_id'));
        $jwtKey = trim((string) config('services.qris.jwt_key'));
        $baseUrl = trim((string) config('services.qris.url'));

        if ($accountNo === '' || $mitraCustomerId === '') {
            return [
                'status' => false,
                'message' => 'QRIS belum dikonfigurasi. Isi QRIS_ACCOUNT_NO dan QRIS_MITRA_CUSTOMER_ID di .env',
            ];
        }

        if ($jwtKey === '' || $baseUrl === '') {
            return [
                'status' => false,
                'message' => 'QRIS belum dikonfigurasi. Isi QRIS_URL dan QRIS_JWT_KEY di .env',
            ];
        }

        $transactionId = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $payload = [
            'accountNo' => $accountNo,
            'amount' => (string) $amount,
            'mitraCustomerId' => $mitraCustomerId,
            'transactionId' => $transactionId,
            'tipeTransaksi' => 'MTR-GENERATE-QRIS-DYNAMIC',
            'vano' => $vano,
        ];
        $requestJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $token = $this->encodeJwt($payload, $jwtKey);
        $url = rtrim($baseUrl, '?&');
        $url .= (str_contains($url, '?') ? '&' : '?').'token='.urlencode($token);

        try {
            $response = Http::timeout(30)
                ->withoutVerifying()
                ->acceptJson()
                ->get($url);

            $json = $this->decodeResponse($response->body());
            if (! is_array($json)) {
                Log::warning('QRIS generate invalid response', [
                    'http' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 1000),
                ]);

                return [
                    'status' => false,
                    'message' => 'Response server QRIS tidak valid',
                ];
            }
        } catch (\Throwable $e) {
            Log::error('QRIS generate failed', ['error' => $e->getMessage()]);

            return [
                'status' => false,
                'message' => 'Gagal menghubungi server QRIS',
            ];
        }

        $responseJson = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $detail = is_array($json['transactionDetail'] ?? null) ? $json['transactionDetail'] : [];
        $qrisId = (string) ($detail['transactionQrId'] ?? $json['transactionQrId'] ?? '');
        $rawQr = (string) ($detail['rawQrData'] ?? $json['rawQrData'] ?? '');
        $responseCode = (string) ($json['responseCode'] ?? '');

        if ($responseCode !== '00' || $qrisId === '' || $rawQr === '') {
            Log::warning('QRIS generate rejected', [
                'responseCode' => $responseCode,
                'message' => $json['responseMessage'] ?? $json['message'] ?? null,
                'has_qr' => $rawQr !== '',
                'has_qris_id' => $qrisId !== '',
            ]);

            $message = $json['responseMessage'] ?? $json['message'] ?? 'QRIS tidak berhasil dibuat';
            if ($responseCode === '00' && ($qrisId === '' || $rawQr === '')) {
                $message = 'Server QRIS tidak mengembalikan data QR';
            }

            return [
                'status' => false,
                'message' => $message,
                'request_payload' => $requestJson,
                'response_payload' => $responseJson,
            ];
        }

        return [
            'status' => true,
            'transaction_id' => $transactionId,
            'transaction_qr_id' => $qrisId,
            'raw_qr_data' => $rawQr,
            'vano' => $vano,
            'amount' => $amount,
            'account_no' => $accountNo,
            'mitra_customer_id' => $mitraCustomerId,
            'request_payload' => $requestJson,
            'response_payload' => $responseJson,
        ];
    }

    private function decodeResponse(string $body): ?array
    {
        $body = trim($body);
        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }

        $decoded = json_decode($body, true);
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }
        if (! is_array($decoded)) {
            $start = strpos($body, '{');
            $end = strrpos($body, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($body, $start, $end - $start + 1), true);
            }
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function encodeJwt(array $payload, string $key): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $segments = [
            $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];
        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $key, true);
        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $input): string
    {
        return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
    }
}
