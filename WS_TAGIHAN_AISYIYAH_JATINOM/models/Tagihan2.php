
<?php
require_once __DIR__ . '/../config/database.php';

class Tagihan
{
    private $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    public function getAllTahunAkademik()
    {
        $sql = "SELECT urut, thn_aka 
            FROM mst_thn_aka 
            ORDER BY urut DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function logVa($message, $data = [])
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        @file_put_contents(__DIR__ . '/va_debug.log', $line, FILE_APPEND);
    }

    /**
     * Pola insert sama seperti WS Deliserdang (bukan generate VA unik).
     * Untuk klien ini: NOVA = NOCUST, setiap bayar = baris baru.
     *
     * index.php generate-va — tambahkan log ini sebelum insert:
     *   file_put_contents(__DIR__.'/va_debug.log', date('c').' INPUT '.json_encode($in).PHP_EOL, FILE_APPEND);
     */
    public function insertVA($custid, $nocust, $namacust, $arrayTagihan, $billam)
    {
        $this->logVa('insertVA start', [
            'custid' => $custid,
            'nocust' => $nocust,
            'namacust' => $namacust,
            'arrayTagihan' => $arrayTagihan,
            'billam' => $billam,
            'billam_type' => gettype($billam),
        ]);

        if ($custid === '' || $custid === null || $nocust === '' || $nocust === null) {
            $this->logVa('insertVA abort empty custid/nocust');
            return false;
        }

        $ids = array_values(array_filter(array_map('intval', explode(',', str_replace(' ', '', (string) $arrayTagihan))), function ($id) {
            return $id > 0;
        }));
        if (empty($ids)) {
            $this->logVa('insertVA abort empty ids', ['arrayTagihan' => $arrayTagihan]);
            return false;
        }

        $amounts = $this->resolveBillam($ids, $billam);
        $billtot = array_sum($amounts);
        if ($billtot <= 0) {
            $this->logVa('insertVA abort billtot', ['billam' => $billam, 'amounts' => $amounts]);
            return false;
        }

        $tagihanAA = implode(',', $ids);
        $billamStore = implode(',', $amounts);

        $inserted = $this->doInsertVa($custid, $nocust, $namacust, $nocust, $tagihanAA, $billamStore, $billtot);
        if ($inserted) {
            $this->logVa('insertVA ok', ['nova' => $nocust, 'arrayTagihan' => $tagihanAA, 'billam' => $billamStore, 'billtot' => $billtot]);
            return $nocust;
        }

        $this->logVa('insertVA failed, NOVA harus sama dengan NOCUST', ['nocust' => $nocust]);
        return false;
    }

    private function resolveBillam(array $ids, $billam)
    {
        $fromArg = $this->parseAmounts($billam);
        if (count($fromArg) === count($ids)) {
            return $fromArg;
        }

        $raw = $this->readIncoming();
        $fromField = $this->parseAmounts($raw['billam'] ?? '');
        if (count($fromField) === count($ids)) {
            return $fromField;
        }

        $items = $raw['items'] ?? [];
        if (is_array($items) && count($items)) {
            $byAa = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $aa = (int) ($item['AA'] ?? $item['aa'] ?? 0);
                $amt = (int) ($item['amount'] ?? $item['billam'] ?? 0);
                if ($aa > 0 && $amt > 0) {
                    $byAa[$aa] = $amt;
                }
            }
            $ordered = [];
            foreach ($ids as $aa) {
                $ordered[] = (int) ($byAa[$aa] ?? 0);
            }
            if (count(array_filter($ordered)) === count($ids)) {
                return $ordered;
            }
        }

        return $fromArg;
    }

    private function parseAmounts($billam)
    {
        if (is_array($billam)) {
            return array_values(array_filter(array_map('intval', $billam), function ($n) {
                return $n > 0;
            }));
        }

        return array_values(array_filter(array_map('intval', explode(',', str_replace(' ', '', (string) $billam))), function ($n) {
            return $n > 0;
        }));
    }

    private function readIncoming()
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $json = json_decode((string) @file_get_contents('php://input'), true);
        if (is_array($json) && count($json)) {
            $cached = $json;
            return $cached;
        }

        $cached = is_array($_POST) ? $_POST : [];
        return $cached;
    }

    private function doInsertVa($custid, $nocust, $namacust, $nova, $arrayTagihan, $billam, $billtot)
    {
        $params = [
            ':custid' => $custid,
            ':nocust' => $nocust,
            ':namacust' => $namacust,
            ':nova' => $nova,
            ':arrayTagihan' => $arrayTagihan,
            ':billam' => $billam,
        ];

        try {
            $stmt = $this->db->prepare("
                INSERT INTO scctva (CUSTID, NOCUST, NMCUST, NOVA, ArrayTagihan, BILLAM, BILLTOT, STATUS, CREATED_AT)
                VALUES (:custid, :nocust, :namacust, :nova, :arrayTagihan, :billam, :billtot, 1, NOW())
            ");
            $ok = $stmt->execute($params + [':billtot' => $billtot]);
            if ($ok) {
                return true;
            }
            $this->logVa('insert with BILLTOT returned false', ['error' => $stmt->errorInfo()]);
        } catch (Exception $e) {
            $this->logVa('insert with BILLTOT exception', ['error' => $e->getMessage()]);
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO scctva (CUSTID, NOCUST, NMCUST, NOVA, ArrayTagihan, BILLAM, STATUS, CREATED_AT)
                VALUES (:custid, :nocust, :namacust, :nova, :arrayTagihan, :billam, 1, NOW())
            ");
            $ok = $stmt->execute($params);
            if ($ok) {
                return true;
            }
            $this->logVa('insert without BILLTOT returned false', ['error' => $stmt->errorInfo()]);
        } catch (Exception $e) {
            $this->logVa('insert without BILLTOT exception', ['error' => $e->getMessage()]);
        }

        return false;
    }

    public function cekTagihanByVA($va_number, $tahun_akademik = null)
    {
        $siswa = $this->getSiswaByVa($va_number);
        if (!$siswa) {
            return null;
        }

        return $this->attachTagihan($siswa, $va_number, $tahun_akademik);
    }

    public function cekTagihanByVAPw($va_number, $password, $tahun_akademik = null)
    {
        $num2nd = $this->stripVa($va_number);

        $stmtUser = $this->db->prepare("SELECT userlogin, kunci FROM sm_user WHERE userlogin = :userlogin LIMIT 1");
        $stmtUser->execute([':userlogin' => $num2nd]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
        if (!$user || $user['kunci'] !== sha1($password)) {
            return null;
        }

        $siswa = $this->getSiswaByVa($va_number);
        if (!$siswa) {
            return null;
        }

        return $this->attachTagihan($siswa, $va_number, $tahun_akademik);
    }

    private function stripVa($va_number)
    {
        foreach (['757777', '751000', '797766'] as $prefix) {
            if (strpos((string) $va_number, $prefix) === 0) {
                return substr($va_number, strlen($prefix));
            }
        }

        return $va_number;
    }

    private function getSiswaByVa($va_number)
    {
        $num2nd = $this->stripVa($va_number);
        $sql = "
        SELECT 
            c.CUSTID AS id,
            c.NOCUST AS no_cust,
            c.NUM2ND AS num2nd,
            c.NMCUST AS nama,
            c.CODE02 AS jenjang,
            c.DESC02 AS kelas,
            c.CODE03 AS kode_jurusan,
            c.DESC03 AS jurusan,
            c.DESC04 AS tahun_akademik,
            c.DESC05 AS alamat,
            c.GENUS AS orangtua,
            c.LastUpdate AS updated_at
        FROM scctcust c
        WHERE c.NOCUST = :nocust
        LIMIT 1
    ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':nocust' => $num2nd]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function attachTagihan(array $siswa, $va_number, $tahun_akademik = null)
    {
        $num2nd = $siswa['no_cust'];

        $sqlSaldo = "SELECT SALDO FROM v_saldo_va WHERE NOCUST = :nocust LIMIT 1";
        $stmtSaldo = $this->db->prepare($sqlSaldo);
        $stmtSaldo->execute([':nocust' => $num2nd]);
        $saldoRow = $stmtSaldo->fetch(PDO::FETCH_ASSOC);
        $saldo = $saldoRow['SALDO'] ?? 0;

        $siswa['va_number'] = $va_number;
        $siswa['saldo'] = $saldo;

        if (!$tahun_akademik || strtolower($tahun_akademik) === 'all') {
            $tahun_akademik = null;
        }

        $baseFilter = "b.CUSTID = :custid";
        if ($tahun_akademik) {
            $baseFilter .= " AND b.BTA = :tahun_akademik";
        }

        $selectCols = "
            b.AA, b.BILLCD, b.BILLNM AS nama_tagihan, b.BILLAM AS total_tagihan,
            b.BILLAC AS periode, b.BTA AS tahun_akademik_tagihan,
            b.FTGLTagihan, b.FURUTAN, b.isINSTALLABLE AS isINSTALLABLE,
            d.BILLAM AS nominal_detail, d.tahun AS tahun_detail, u.NamaAkun AS akun_detail,
            b.PAIDST, b.PAIDDT
        ";
        $joins = "
            FROM scctbill b
            LEFT JOIN scctbill_detail d ON b.CUSTID = d.CUSTID AND b.BILLCD = d.BILLCD
            LEFT JOIN u_akun u ON d.KodePost = u.KodeAkun
        ";

        $sqlBelum = "
        SELECT $selectCols
        $joins
        WHERE $baseFilter AND b.PAIDST = '0' AND b.FSTSBolehBayar = '1'
        ORDER BY b.FURUTAN ASC, d.tahun ASC
    ";
        $stmtBelum = $this->db->prepare($sqlBelum);
        $params = [':custid' => $siswa['id']];
        if ($tahun_akademik) {
            $params[':tahun_akademik'] = $tahun_akademik;
        }
        $stmtBelum->execute($params);
        $belumLunas = $stmtBelum->fetchAll(PDO::FETCH_ASSOC);

        $sqlLunas = "
        SELECT $selectCols
        $joins
        WHERE $baseFilter AND b.PAIDST = '1'
        ORDER BY b.PAIDDT DESC, b.FURUTAN ASC
    ";
        $stmtLunas = $this->db->prepare($sqlLunas);
        $stmtLunas->execute($params);
        $lunas = $stmtLunas->fetchAll(PDO::FETCH_ASSOC);

        $paidMap = $this->getPaidMap($siswa['id'], $num2nd);

        $siswa['tahun_dipilih'] = $tahun_akademik ?: 'Semua Tahun Akademik';
        $siswa['tagihan'] = $this->groupData($belumLunas, $paidMap);
        $siswa['tagihan_lunas'] = $this->groupData($lunas, $paidMap);

        return $siswa;
    }

    private function getPaidMap($custid, $nocust)
    {
        $sql = "SELECT ArrayTagihan, BILLAM FROM scctva WHERE CUSTID = :custid OR NOCUST = :nocust AND STATUS = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':custid' => $custid,
            ':nocust' => $nocust,
        ]);

        $paid = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $aas = array_map('trim', explode(',', (string) $row['ArrayTagihan']));
            $ams = array_map('trim', explode(',', (string) $row['BILLAM']));
            foreach ($aas as $i => $aa) {
                if ($aa === '') {
                    continue;
                }
                $amt = isset($ams[$i]) ? (int) $ams[$i] : 0;
                $paid[$aa] = (int) ($paid[$aa] ?? 0) + $amt;
            }
        }

        return $paid;
    }

    private function groupData(array $rows, array $paidMap)
    {
        $grouped = [];
        foreach ($rows as $row) {
            $key = $row['AA'];
            if (!isset($grouped[$key])) {
                $total = (int) $row['total_tagihan'];
                $sudah = (int) ($paidMap[$row['AA']] ?? $paidMap[(string) $row['AA']] ?? 0);
                $grouped[$key] = [
                    'AA' => $row['AA'],
                    'BILLCD' => $row['BILLCD'],
                    'nama_tagihan' => $row['nama_tagihan'],
                    'total_tagihan' => $row['total_tagihan'],
                    'periode' => $row['periode'],
                    'tahun_akademik_tagihan' => $row['tahun_akademik_tagihan'],
                    'FTGLTagihan' => $row['FTGLTagihan'] ?? null,
                    'FURUTAN' => $row['FURUTAN'] ?? null,
                    'isINSTALLABLE' => $this->flagInstallable($row),
                    'is_installment' => $this->flagInstallable($row),
                    'sudah_dibayar' => $sudah,
                    'sisa_tagihan' => max(0, $total - $sudah),
                    'PAIDST' => $row['PAIDST'],
                    'PAIDDT' => $row['PAIDDT'],
                    'detail' => []
                ];
            }
            if (!empty($row['nominal_detail'])) {
                $grouped[$key]['detail'][] = [
                    'nominal_detail' => $row['nominal_detail'],
                    'akun_detail' => $row['akun_detail']
                ];
            }
        }

        return array_values($grouped);
    }

    private function flagInstallable(array $row)
    {
        $value = $row['isINSTALLABLE']
            ?? $row['isinstallable']
            ?? $row['ISINSTALLABLE']
            ?? $row['is_installment']
            ?? 0;

        return ((int) $value === 1) ? 1 : 0;
    }
}
