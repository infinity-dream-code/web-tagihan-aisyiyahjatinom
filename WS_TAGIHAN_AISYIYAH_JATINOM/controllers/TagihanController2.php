<?php
require_once __DIR__ . '/../models/Tagihan.php';
require_once __DIR__ . '/../models/MultiAkun.php';
require_once __DIR__ . '/../helpers/response.php';

class TagihanController {
   private $tagihan;
   private $multiAkun;

   public function __construct() {
        $this->tagihan = new Tagihan();
        $this->multiAkun = new MultiAkun();
    }

    private function readJsonInput()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        return is_array($input) ? $input : [];
    }

    public function cek() {
        $input = json_decode(file_get_contents("php://input"), true);
        $va = $input['va'] ?? null;
        $tahun_akademik = $input['tahun_akademik'] ?? null;

        if (empty($va) || empty($tahun_akademik)) {
            jsonResponse(false, "Nomor VA dan Tahun Akademik wajib diisi");
            return;
        }

        $data = $this->tagihan->cekTagihanByVA($va, $tahun_akademik);

        if ($data) {
            jsonResponse(true, "Data ditemukan", $data);
        } else {
            jsonResponse(false, "Data tidak ditemukan untuk VA dan Tahun Akademik tersebut");
        }
    }
public function cek2()
{
    $input = json_decode(file_get_contents("php://input"), true);
    $va = $input['va'] ?? null;
    $password = $input['password'] ?? null;
    $tahun_akademik = $input['tahun_akademik'] ?? null;

    if (empty($va) || empty($password) || empty($tahun_akademik)) {
        jsonResponse(false, "Nomor VA, Password, dan Tahun Akademik wajib diisi");
        return;
    }

    $data = $this->tagihan->cekTagihanByVAPw($va, $password, $tahun_akademik);

    if ($data) {
        jsonResponse(true, "Data ditemukan", $data);
    } else {
        jsonResponse(false, "VA atau Password salah, atau data tidak ditemukan");
    }
}

    public function generateVA() {
    $input = json_decode(file_get_contents("php://input"), true);
    $custid = $input['custid'] ?? null;
    $nocust = $input['nocust'] ?? null;
    $namacust = $input['namacust'] ?? null;
    $arrayTagihan = $input['array_tagihan'] ?? null;
    $billam = $input['total'] ?? null;

      if (is_null($custid) || is_null($nocust) || is_null($namacust) || is_null($arrayTagihan) || is_null($billam)) {
        jsonResponse(false, "Data tidak lengkap untuk membuat VA");
        return;
    }

    $model = new Tagihan();
    $va_number = $model->insertVA($custid, $nocust, $namacust, $arrayTagihan, $billam);

    jsonResponse(true, "Nomor Virtual Account berhasil dibuat", ['va_number' => $va_number]);
}

public function getTahunAkademik() {
        header('Content-Type: application/json');
        try {
            $data = $this->tagihan->getAllTahunAkademik();
            echo json_encode([
                'status' => true,
                'data' => $data
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'status' => false,
                'message' => 'Gagal mengambil data tahun akademik: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * List akun multi yang terhubung dengan VA aktif.
     * Body JSON: { "va": "...", "active_va": "..." }
     */
    public function multiAkunList()
    {
        $input = $this->readJsonInput();
        $va = $input['va'] ?? $input['active_va'] ?? null;

        if (empty($va)) {
            jsonResponse(false, 'VA wajib diisi');
            return;
        }

        try {
            $active = $input['active_va'] ?? $va;
            $accounts = $this->multiAkun->listAccounts($va, $active);
            jsonResponse(true, 'OK', [
                'accounts' => $accounts,
                'active_no_cust' => $this->multiAkun->normalizeNoCust($active),
            ]);
        } catch (Exception $e) {
            jsonResponse(false, 'Gagal memuat daftar multi akun: ' . $e->getMessage());
        }
    }

    /**
     * Tambah / link akun baru ke grup multi akun.
     * Body JSON: {
     *   "active_va": "...",
     *   "va": "...",
     *   "password": "...",
     *   "tahun_akademik": "all"
     * }
     */
    public function multiAkunTambah()
    {
        $input = $this->readJsonInput();
        $activeVa = $input['active_va'] ?? null;
        $va = $input['va'] ?? $input['no_cust'] ?? null;
        $password = $input['password'] ?? null;
        $tahun = $input['tahun_akademik'] ?? 'all';
        $activeTahun = $input['active_tahun_akademik'] ?? $tahun;

        if (empty($activeVa) || empty($va) || empty($password)) {
            jsonResponse(false, 'active_va, va, dan password wajib diisi');
            return;
        }

        try {
            $activeData = $this->multiAkun->getTagihanByVa($activeVa, $activeTahun);
            if (!$activeData) {
                jsonResponse(false, 'Akun aktif tidak ditemukan');
                return;
            }

            $newData = $this->multiAkun->getTagihanByVaPw($va, $password, $tahun);
            if (!$newData) {
                jsonResponse(false, 'VA atau Password akun baru salah, atau data tidak ditemukan');
                return;
            }

            $linked = $this->multiAkun->linkAccounts(
                $activeData,
                $activeVa,
                $activeTahun,
                $newData,
                $va,
                $tahun
            );

            jsonResponse(true, 'Akun berhasil ditambahkan', $linked);
        } catch (InvalidArgumentException $e) {
            jsonResponse(false, $e->getMessage());
        } catch (Exception $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'multi_account_') !== false || stripos($msg, "doesn't exist") !== false) {
                jsonResponse(false, 'Tabel multi akun belum ada di database WS. Jalankan ws/sql/multi_account_tables.sql');
                return;
            }
            jsonResponse(false, 'Gagal menambahkan multi akun: ' . $msg);
        }
    }

    /**
     * Switch ke akun lain dalam grup yang sama (tanpa password).
     * Body JSON: {
     *   "active_va": "...",
     *   "target_va": "...",
     *   "tahun_akademik": "all"
     * }
     * Response data = struktur sama seperti cek-tagihan + accounts[]
     */
    public function multiAkunSwitch()
    {
        $input = $this->readJsonInput();
        $activeVa = $input['active_va'] ?? null;
        $targetVa = $input['target_va'] ?? $input['va'] ?? $input['no_cust'] ?? null;
        $tahun = $input['tahun_akademik'] ?? 'all';

        if (empty($activeVa) || empty($targetVa)) {
            jsonResponse(false, 'active_va dan target_va wajib diisi');
            return;
        }

        try {
            if (!$this->multiAkun->sameGroup($activeVa, $targetVa)) {
                jsonResponse(false, 'Akun tujuan tidak terhubung dengan multi akun aktif');
                return;
            }

            $member = $this->multiAkun->findMember($targetVa);
            $vaDisplay = $member['va_display'] ?? $targetVa;

            $data = $this->multiAkun->getTagihanByVa($targetVa, $tahun);
            if (!$data) {
                jsonResponse(false, 'Data akun tujuan tidak ditemukan');
                return;
            }

            $this->multiAkun->syncMemberMeta($data, $vaDisplay, $tahun);
            $accounts = $this->multiAkun->listAccounts($targetVa, $targetVa);

            $data['accounts'] = $accounts;
            $data['active_no_cust'] = $this->multiAkun->normalizeNoCust($targetVa);
            $data['group_id'] = $member['group_id'] ?? null;

            jsonResponse(true, 'Berhasil beralih akun', $data);
        } catch (Exception $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'multi_account_') !== false || stripos($msg, "doesn't exist") !== false) {
                jsonResponse(false, 'Tabel multi akun belum ada di database WS. Jalankan ws/sql/multi_account_tables.sql');
                return;
            }
            jsonResponse(false, 'Gagal beralih akun: ' . $msg);
        }
    }

    /**
     * Hapus akun dari grup multi akun.
     * Body JSON: {
     *   "active_va": "...",
     *   "target_va": "..."
     * }
     */
    public function multiAkunHapus()
    {
        $input = $this->readJsonInput();
        $activeVa = $input['active_va'] ?? null;
        $targetVa = $input['target_va'] ?? $input['va'] ?? $input['no_cust'] ?? null;

        if (empty($activeVa) || empty($targetVa)) {
            jsonResponse(false, 'active_va dan target_va wajib diisi');
            return;
        }

        try {
            $result = $this->multiAkun->removeMember($activeVa, $targetVa);
            jsonResponse(true, 'Akun berhasil dihapus dari multi akun', $result);
        } catch (InvalidArgumentException $e) {
            jsonResponse(false, $e->getMessage());
        } catch (Exception $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'multi_account_') !== false || stripos($msg, "doesn't exist") !== false) {
                jsonResponse(false, 'Tabel multi akun belum ada di database WS. Jalankan ws/sql/multi_account_tables.sql');
                return;
            }
            jsonResponse(false, 'Gagal menghapus multi akun: ' . $msg);
        }
    }

}
