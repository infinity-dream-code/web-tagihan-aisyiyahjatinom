<?php

namespace App\Http\Controllers;

use App\Services\QrisService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QrisController extends Controller
{
    public function generate(Request $request, QrisService $qris)
    {
        $session = $this->requireSession();
        if (! $session) {
            return response()->json([
                'status' => false,
                'message' => 'Sesi akun tidak ditemukan. Silakan cek tagihan ulang.',
            ], 401);
        }

        $minAmount = max(1, (int) config('services.qris.min_amount', 10000));
        $request->validate([
            'amount' => 'required|integer|min:'.$minAmount,
        ], [
            'amount.min' => 'Nominal top up minimal Rp '.number_format($minAmount, 0, ',', '.'),
        ]);

        $amount = (int) $request->input('amount');
        $noCust = TagihanController::normalizeVa($session['active_no_cust'] ?? '');
        $vano = TagihanController::formatNova($noCust);
        if ($vano === '' || $vano === '-' || ! str_starts_with($vano, TagihanController::vaPrefix())) {
            return response()->json([
                'status' => false,
                'message' => 'Nomor VA siswa tidak valid',
            ], 422);
        }

        $result = $qris->generate($vano, $amount);
        if (empty($result['status'])) {
            return response()->json([
                'status' => false,
                'message' => $result['message'] ?? 'Gagal membuat QRIS',
            ], 422);
        }

        $siswa = $this->fetchSiswa($noCust, $vano);
        $custid = trim((string) ($siswa['id'] ?? ''));
        $nama = trim((string) ($siswa['nama'] ?? ''));
        $saved = ['status' => false, 'message' => 'Data siswa tidak ditemukan'];
        if ($custid !== '') {
            $description = 'TOPUP|Top up saldo'.($nama !== '' ? ' '.$nama : '');
            $saved = $this->postWs('save-qris', [
                'custid' => $custid,
                'nocust' => $noCust,
                'namacust' => $nama,
                'vano' => $vano,
                'amount' => $amount,
                'qris_id' => $result['transaction_qr_id'],
                'qris_content' => $result['raw_qr_data'],
                'transaction_id' => $result['transaction_id'],
                'account_no' => $result['account_no'] ?? '',
                'mitra_customer_id' => $result['mitra_customer_id'] ?? '',
                'description' => $description,
                'request_payload' => $result['request_payload'] ?? null,
                'response_payload' => $result['response_payload'] ?? null,
            ]);
        }
        if (empty($saved['status'])) {
            Log::warning('QRIS save-qris failed', [
                'message' => $saved['message'] ?? null,
                'qris_id' => $result['transaction_qr_id'],
                'vano' => $vano,
            ]);
        }

        $ttl = max(1, (int) config('services.qris.ttl_minutes', 15));
        $expiresAt = Carbon::now()->addMinutes($ttl);

        session([
            'qris_topup' => [
                'amount' => $amount,
                'qris_id' => $result['transaction_qr_id'],
                'transaction_id' => $result['transaction_id'],
                'vano' => $vano,
                'no_cust' => $noCust,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ]);

        return response()->json([
            'status' => true,
            'data' => [
                'rawQrData' => $result['raw_qr_data'],
                'transactionQrId' => $result['transaction_qr_id'],
                'vano' => $vano,
                'amount' => $amount,
                'expires_at' => $expiresAt->toIso8601String(),
                'saved' => ! empty($saved['status']),
            ],
        ]);
    }

    public function status()
    {
        $session = $this->requireSession();
        if (! $session) {
            return response()->json([
                'status' => false,
                'message' => 'Sesi akun tidak ditemukan. Silakan cek tagihan ulang.',
            ], 401);
        }

        $topup = session('qris_topup');
        if (! is_array($topup) || empty($topup['qris_id'])) {
            return response()->json([
                'status' => false,
                'paid' => false,
                'message' => 'Belum ada QRIS top up yang aktif',
            ]);
        }

        $ws = $this->postWs('qris-status', [
            'qris_id' => $topup['qris_id'] ?? '',
            'transaction_id' => $topup['transaction_id'] ?? '',
            'vano' => $topup['vano'] ?? '',
        ]);
        $row = is_array($ws['data'] ?? null) ? $ws['data'] : [];
        $paid = ! empty($ws['status']) && ! empty($row['paid']);

        $expiresAt = Carbon::parse($topup['expires_at'] ?? now()->subMinute());
        $expired = ! $paid && $expiresAt->isPast();
        $saldo = $this->fetchSaldo($topup['no_cust'] ?? '', $topup['vano'] ?? '');

        if ($paid) {
            session()->forget('qris_topup');
        }

        $payload = [
            'status' => true,
            'paid' => $paid,
            'expired' => $expired,
            'amount' => (int) ($topup['amount'] ?? ($row['amount'] ?? 0)),
            'message' => $paid
                ? 'Top up berhasil'
                : ($expired ? 'QRIS sudah kedaluwarsa. Generate ulang untuk top up.' : 'Menunggu pembayaran'),
        ];
        if ($saldo !== null) {
            $payload['saldo'] = $saldo;
        }

        return response()->json($payload);
    }

    private function requireSession(): ?array
    {
        $session = session('tagihan');
        if (! is_array($session) || empty($session['active_no_cust'])) {
            return null;
        }

        return $session;
    }

    private function fetchSiswa(string $noCust, string $vano): array
    {
        $json = TagihanController::postWsWithVaCandidates('cek-tagihan', [
            'tahun_akademik' => 'all',
        ], $vano !== '' ? $vano : $noCust);

        $data = $json['data'] ?? null;

        return is_array($data) ? $data : [];
    }

    private function fetchSaldo(string $noCust, string $vano): ?int
    {
        $siswa = $this->fetchSiswa($noCust, $vano);
        if (! array_key_exists('saldo', $siswa)) {
            return null;
        }

        return (int) $siswa['saldo'];
    }

    private function postWs(string $path, array $payload): array
    {
        $url = rtrim((string) config('services.tagihan_ws.url'), '?&');
        $url .= (str_contains($url, '?') ? '&' : '?').'path='.$path;

        try {
            $response = Http::timeout(30)
                ->withoutVerifying()
                ->acceptJson()
                ->asJson()
                ->post($url, $payload);
            $json = $response->json();

            return is_array($json)
                ? $json
                : ['status' => false, 'message' => 'Response WS tidak valid'];
        } catch (\Throwable $e) {
            Log::warning('WS QRIS call failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => false,
                'message' => 'Gagal menghubungi web service tagihan',
            ];
        }
    }
}
