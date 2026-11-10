<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Tagihan.php';

class LoginToken
{
    private $db;
    private $tagihan;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->tagihan = new Tagihan();
    }

    public function normalizeNoCust($va)
    {
        $va = preg_replace('/\s+/', '', (string) $va);

        foreach (['757777', '751000', '797766'] as $prefix) {
            if (strpos($va, $prefix) === 0) {
                $va = substr($va, strlen($prefix));
                break;
            }
        }

        $nocust = ltrim($va, '0');

        return $nocust !== '' ? $nocust : $va;
    }

    public function normalizeToken($token)
    {
        $token = strtolower(trim((string) $token));

        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return '';
        }

        return $token;
    }

    public function create($noCust, $expiresHours = 24, $createdBy = null, $tahunAkademik = 'all', $custid = null)
    {
        $noCust = $this->normalizeNoCust($noCust);
        if ($noCust === '') {
            throw new InvalidArgumentException('no_cust wajib diisi');
        }

        $siswa = $this->findSiswa($noCust);
        if (!$siswa) {
            throw new InvalidArgumentException('Siswa tidak ditemukan untuk no_cust tersebut');
        }

        if ($custid === null || $custid === '') {
            $custid = $siswa['CUSTID'] ?? $siswa['id'] ?? null;
        }

        $tahun = $tahunAkademik ?: 'all';
        $hours = (int) $expiresHours;
        if ($hours < 1) {
            $hours = 24;
        }
        if ($hours > 168) {
            $hours = 168;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + ($hours * 3600));

        $stmt = $this->db->prepare('
            INSERT INTO sm_user_credential_link
                (token, no_cust, custid, tahun_akademik, expires_at, created_by)
            VALUES
                (:token, :no_cust, :custid, :tahun_akademik, :expires_at, :created_by)
        ');
        $stmt->execute([
            ':token' => $token,
            ':no_cust' => $noCust,
            ':custid' => $custid,
            ':tahun_akademik' => $tahun,
            ':expires_at' => $expiresAt,
            ':created_by' => $createdBy !== null && $createdBy !== '' ? (string) $createdBy : null,
        ]);

        return [
            'token' => $token,
            'url_path' => $token,
            'no_cust' => $noCust,
            'custid' => $custid,
            'tahun_akademik' => $tahun,
            'expires_at' => $expiresAt,
            'expires_hours' => $hours,
            'created_by' => $createdBy,
        ];
    }

    /**
     * Validasi token sekali pakai, tandai used_at, kembalikan data tagihan (tanpa password).
     */
    public function consume($rawToken)
    {
        $token = $this->normalizeToken($rawToken);
        if ($token === '') {
            throw new InvalidArgumentException('Format token tidak valid');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('
                SELECT id, token, no_cust, custid, tahun_akademik, expires_at, used_at
                FROM sm_user_credential_link
                WHERE token = :token
                LIMIT 1
                FOR UPDATE
            ');
            $stmt->execute([':token' => $token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->db->rollBack();
                throw new InvalidArgumentException('Link login tidak valid');
            }

            if (!empty($row['used_at'])) {
                $this->db->rollBack();
                throw new InvalidArgumentException('Link login sudah pernah dipakai');
            }

            if (strtotime($row['expires_at']) <= time()) {
                $this->db->rollBack();
                throw new InvalidArgumentException('Link login sudah kadaluarsa');
            }

            $upd = $this->db->prepare('UPDATE sm_user_credential_link SET used_at = NOW() WHERE id = :id AND used_at IS NULL');
            $upd->execute([':id' => $row['id']]);
            if ($upd->rowCount() < 1) {
                $this->db->rollBack();
                throw new InvalidArgumentException('Link login sudah pernah dipakai');
            }

            $this->db->commit();
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $tahun = $row['tahun_akademik'] ?: 'all';
        $data = $this->tagihan->cekTagihanByVA($row['no_cust'], $tahun);
        if (!$data) {
            throw new InvalidArgumentException('Data siswa untuk token ini tidak ditemukan');
        }

        return $data;
    }

    private function findSiswa($noCust)
    {
        $stmt = $this->db->prepare('
            SELECT CUSTID, NOCUST
            FROM scctcust
            WHERE NOCUST = :nocust
            LIMIT 1
        ');
        $stmt->execute([':nocust' => $noCust]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
