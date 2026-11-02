<?php

namespace App\Http\Controllers;

use App\Services\QrisService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
        if ($vano === '' || $vano === '-') {
            return response()->json([
                'status' => false,
                'message' => 'Nomor VA siswa tidak valid',
            ], 422);
        }

        $saldoAwal = $this->fetchSaldo($noCust, $vano);
        if ($saldoAwal === null && $request->filled('saldo')) {
            $saldoAwal = (int) $request->input('saldo');
        }
        if ($saldoAwal === null) {
            $saldoAwal = 0;
        }

        $result = $qris->generate($vano, $amount);
        if (empty($result['status'])) {
            return response()->json([
                'status' => false,
                'message' => $result['message'] ?? 'Gagal membuat QRIS',
            ], 422);
        }

        $ttl = max(1, (int) config('services.qris.ttl_minutes', 15));
        $expiresAt = Carbon::now()->addMinutes($ttl);

        session([
            'qris_topup' => [
                'amount' => $amount,
                'saldo_awal' => $saldoAwal,
                'qris_id' => $result['transaction_qr_id'],
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
                'saldo_awal' => $saldoAwal,
                'expires_at' => $expiresAt->toIso8601String(),
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
        if (! is_array($topup) || empty($topup['amount'])) {
            return response()->json([
                'status' => false,
                'paid' => false,
                'message' => 'Belum ada QRIS top up yang aktif',
            ]);
        }

        $expiresAt = Carbon::parse($topup['expires_at'] ?? now()->subMinute());
        if ($expiresAt->isPast()) {
            return response()->json([
                'status' => true,
                'paid' => false,
                'expired' => true,
                'message' => 'QRIS sudah kedaluwarsa. Generate ulang untuk top up.',
                'saldo' => $this->fetchSaldo($topup['no_cust'] ?? '', $topup['vano'] ?? '') ?? (int) ($topup['saldo_awal'] ?? 0),
            ]);
        }

        $saldo = $this->fetchSaldo($topup['no_cust'] ?? '', $topup['vano'] ?? '');
        if ($saldo === null) {
            return response()->json([
                'status' => true,
                'paid' => false,
                'expired' => false,
                'saldo' => (int) ($topup['saldo_awal'] ?? 0),
                'message' => 'Menunggu pembayaran',
            ]);
        }

        $target = (int) ($topup['saldo_awal'] ?? 0) + (int) $topup['amount'];
        $paid = $saldo >= $target;

        if ($paid) {
            session()->forget('qris_topup');
        }

        return response()->json([
            'status' => true,
            'paid' => $paid,
            'expired' => false,
            'saldo' => $saldo,
            'amount' => (int) $topup['amount'],
            'message' => $paid ? 'Top up berhasil' : 'Menunggu pembayaran',
        ]);
    }

    private function requireSession(): ?array
    {
        $session = session('tagihan');
        if (! is_array($session) || empty($session['active_no_cust'])) {
            return null;
        }

        return $session;
    }

    private function fetchSaldo(string $noCust, string $vano): ?int
    {
        $va = $vano !== '' && $vano !== '-' ? $vano : $noCust;
        if ($va === '') {
            return null;
        }

        $json = TagihanController::postWsWithVaCandidates('cek-tagihan', [
            'tahun_akademik' => 'all',
        ], $va);

        if (empty($json['status']) || ! is_array($json['data'] ?? null)) {
            return null;
        }

        if (! array_key_exists('saldo', $json['data'])) {
            return null;
        }

        return (int) $json['data']['saldo'];
    }
}
