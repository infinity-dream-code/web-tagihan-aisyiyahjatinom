<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QrisTopupGenerateTest extends TestCase
{
    public function test_generate_rejects_empty_merchant_credentials(): void
    {
        config([
            'services.qris.account_no' => '',
            'services.qris.mitra_customer_id' => '',
        ]);

        Http::fake();

        $response = $this->withSession([
            'tagihan' => ['active_no_cust' => '3271998'],
        ])->postJson('/qris/generate', ['amount' => 10000]);

        $response->assertStatus(422);
        $response->assertJsonPath('status', false);
        $this->assertStringContainsString('QRIS_ACCOUNT_NO', (string) $response->json('message'));
        Http::assertNothingSent();
    }

    public function test_generate_shows_qr_and_status_stays_unpaid(): void
    {
        config([
            'services.qris.account_no' => '5080010295',
            'services.qris.mitra_customer_id' => 'MITRA-UJI',
            'services.qris.url' => 'http://103.23.103.43/qris/lazizmu_diy/server.php',
            'services.qris.jwt_key' => 'TokenJWT_BMI_ICT',
            'services.tagihan_ws.url' => 'http://ws.test/index.php',
        ]);

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'lazizmu_diy')) {
                return Http::response([
                    'responseCode' => '00',
                    'responseMessage' => 'Success',
                    'transactionDetail' => [
                        'transactionQrId' => 'QRIDTEST1',
                        'rawQrData' => '000201010212QRISTEST',
                    ],
                ]);
            }

            if (str_contains($url, 'path=cek-tagihan')) {
                return Http::response([
                    'status' => true,
                    'data' => [
                        'id' => '99',
                        'nama' => 'Siswa Uji',
                        'no_cust' => '3271998',
                        'saldo' => 5000,
                    ],
                ]);
            }

            if (str_contains($url, 'path=save-qris')) {
                return Http::response([
                    'status' => true,
                    'message' => 'QRIS tersimpan',
                    'data' => [
                        'id' => 1,
                        'paid' => false,
                        'paid_flag' => 0,
                        'status' => 'pending',
                    ],
                ]);
            }

            if (str_contains($url, 'path=qris-status')) {
                return Http::response([
                    'status' => true,
                    'message' => 'Menunggu pembayaran',
                    'data' => [
                        'paid' => false,
                        'paid_flag' => 0,
                        'status' => 'pending',
                        'amount' => 10000,
                    ],
                ]);
            }

            return Http::response(['status' => false, 'message' => 'unexpected'], 500);
        });

        $generate = $this->withSession([
            'tagihan' => ['active_no_cust' => '3271998'],
        ])->postJson('/qris/generate', ['amount' => 10000]);

        $generate->assertOk();
        $generate->assertJsonPath('status', true);
        $generate->assertJsonPath('data.rawQrData', '000201010212QRISTEST');
        $generate->assertJsonPath('data.vano', '7520880003271998');
        $generate->assertJsonPath('data.saved', true);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'path=save-qris')) {
                return false;
            }
            $body = $request->data();

            return ($body['vano'] ?? null) === '7520880003271998'
                && ($body['nocust'] ?? null) === '3271998'
                && ($body['custid'] ?? null) === '99'
                && str_starts_with((string) ($body['description'] ?? ''), 'TOPUP|')
                && ! array_key_exists('items', $body);
        });

        $status = $this->getJson('/qris/status');
        $status->assertOk();
        $status->assertJsonPath('paid', false);
        $status->assertJsonPath('expired', false);
        $status->assertJsonPath('message', 'Menunggu pembayaran');
        $status->assertJsonPath('saldo', 5000);
    }
}
