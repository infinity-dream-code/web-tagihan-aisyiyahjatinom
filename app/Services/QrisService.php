<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QrisService
{
    public function generate(string $vano, int $amount): array
    {
        $transactionId = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $payload = [
            'accountNo' => (string) config('services.qris.account_no'),
            'amount' => (string) $amount,
            'mitraCustomerId' => (string) config('services.qris.mitra_customer_id'),
            'transactionId' => $transactionId,
            'tipeTransaksi' => 'MTR-GENERATE-QRIS-DYNAMIC',
            'vano' => $vano,
        ];

        $token = $this->encodeJwt($payload, (string) config('services.qris.jwt_key'));
        $url = rtrim((string) config('services.qris.url'), '?&');
        $url .= (str_contains($url, '?') ? '&' : '?').'token='.urlencode($token);

        try {
            $response = Http::timeout(30)
                ->withoutVerifying()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url);

            $json = $response->json();
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

        $detail = is_array($json['transactionDetail'] ?? null) ? $json['transactionDetail'] : [];
        $qrisId = (string) ($detail['transactionQrId'] ?? $json['transactionQrId'] ?? '');
        $rawQr = (string) ($detail['rawQrData'] ?? $json['rawQrData'] ?? '');

        if ($qrisId === '' || $rawQr === '') {
            Log::warning('QRIS generate missing fields', [
                'keys' => array_keys($json),
            ]);

            return [
                'status' => false,
                'message' => $json['responseMessage'] ?? $json['message'] ?? 'QRIS tidak berhasil dibuat',
            ];
        }

        return [
            'status' => true,
            'transaction_id' => $transactionId,
            'transaction_qr_id' => $qrisId,
            'raw_qr_data' => $rawQr,
            'vano' => $vano,
            'amount' => $amount,
        ];
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
