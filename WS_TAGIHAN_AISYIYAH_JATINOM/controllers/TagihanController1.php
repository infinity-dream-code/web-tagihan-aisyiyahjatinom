<?php
require_once __DIR__ . '/../models/Tagihan.php';
require_once __DIR__ . '/../helpers/response.php';

class TagihanController {
   private $tagihan;
   public function __construct() {
        $this->tagihan = new Tagihan(); 
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

}
