<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Tagihan.php';

class MultiAkun
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

        foreach (['751000', '797766'] as $prefix) {
            if (strpos($va, $prefix) === 0) {
                $va = substr($va, strlen($prefix));
                break;
            }
        }

        $nocust = ltrim($va, '0');

        return $nocust !== '' ? $nocust : $va;
    }

    public function findMember($noCust)
    {
        $noCust = $this->normalizeNoCust($noCust);
        $stmt = $this->db->prepare('SELECT * FROM multi_account_members WHERE no_cust = :no_cust LIMIT 1');
        $stmt->execute([':no_cust' => $noCust]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function listAccounts($noCust, $activeNoCust = null)
    {
        $member = $this->findMember($noCust);
        if (!$member) {
            return [];
        }

        $active = $this->normalizeNoCust($activeNoCust ?: $noCust);
        $stmt = $this->db->prepare('
            SELECT id, group_id, no_cust, va_display, nama, kelas, jenjang, last_academic_year
            FROM multi_account_members
            WHERE group_id = :group_id
            ORDER BY nama ASC, no_cust ASC
        ');
        $stmt->execute([':group_id' => $member['group_id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['va_display'] = $row['va_display'] ?: $row['no_cust'];
            $row['nama'] = $row['nama'] ?: '-';
            $row['kelas'] = $row['kelas'] ?: '-';
            $row['jenjang'] = $row['jenjang'] ?: '-';
            $row['is_active'] = ($row['no_cust'] === $active);
        }
        unset($row);

        return $rows;
    }

    public function sameGroup($activeNoCust, $targetNoCust)
    {
        $a = $this->findMember($activeNoCust);
        $b = $this->findMember($targetNoCust);

        return $a && $b && (int) $a['group_id'] === (int) $b['group_id'];
    }

    private function upsertMember(array $siswa, $vaDisplay, $academicYear, $groupId)
    {
        $noCust = $this->normalizeNoCust($siswa['no_cust'] ?? $vaDisplay);
        $existing = $this->findMember($noCust);

        $payload = [
            ':group_id' => $groupId,
            ':no_cust' => $noCust,
            ':va_display' => $vaDisplay,
            ':nama' => $siswa['nama'] ?? null,
            ':kelas' => $siswa['kelas'] ?? null,
            ':jenjang' => $siswa['jenjang'] ?? null,
            ':last_academic_year' => $academicYear,
        ];

        if ($existing) {
            $stmt = $this->db->prepare('
                UPDATE multi_account_members
                SET group_id = :group_id,
                    va_display = :va_display,
                    nama = :nama,
                    kelas = :kelas,
                    jenjang = :jenjang,
                    last_academic_year = :last_academic_year,
                    updated_at = NOW()
                WHERE no_cust = :no_cust
            ');
            $stmt->execute($payload);
        } else {
            $stmt = $this->db->prepare('
                INSERT INTO multi_account_members
                    (group_id, no_cust, va_display, nama, kelas, jenjang, last_academic_year, created_at, updated_at)
                VALUES
                    (:group_id, :no_cust, :va_display, :nama, :kelas, :jenjang, :last_academic_year, NOW(), NOW())
            ');
            $stmt->execute($payload);
        }

        return $this->findMember($noCust);
    }

    private function createGroup()
    {
        $this->db->exec('INSERT INTO multi_account_groups (created_at, updated_at) VALUES (NOW(), NOW())');

        return (int) $this->db->lastInsertId();
    }

    /**
     * Link akun aktif dengan akun baru (dua arah / merge grup).
     *
     * @return array{group_id:int, accounts:array}
     */
    public function linkAccounts(array $activeSiswa, $activeVaDisplay, $activeYear, array $newSiswa, $newVaDisplay, $newYear)
    {
        $activeNoCust = $this->normalizeNoCust($activeSiswa['no_cust'] ?? $activeVaDisplay);
        $newNoCust = $this->normalizeNoCust($newSiswa['no_cust'] ?? $newVaDisplay);

        if ($activeNoCust === '' || $newNoCust === '') {
            throw new InvalidArgumentException('VA tidak valid');
        }
        if ($activeNoCust === $newNoCust) {
            throw new InvalidArgumentException('Akun yang ditambahkan sama dengan akun aktif');
        }

        $this->db->beginTransaction();
        try {
            $activeMember = $this->findMember($activeNoCust);
            $newMember = $this->findMember($newNoCust);

            if (!$activeMember && !$newMember) {
                $groupId = $this->createGroup();
                $this->upsertMember($activeSiswa, $activeVaDisplay, $activeYear, $groupId);
                $this->upsertMember($newSiswa, $newVaDisplay, $newYear, $groupId);
            } elseif ($activeMember && !$newMember) {
                $groupId = (int) $activeMember['group_id'];
                $this->upsertMember($activeSiswa, $activeVaDisplay, $activeYear, $groupId);
                $this->upsertMember($newSiswa, $newVaDisplay, $newYear, $groupId);
            } elseif (!$activeMember && $newMember) {
                $groupId = (int) $newMember['group_id'];
                $this->upsertMember($newSiswa, $newVaDisplay, $newYear, $groupId);
                $this->upsertMember($activeSiswa, $activeVaDisplay, $activeYear, $groupId);
            } else {
                $groupId = (int) $activeMember['group_id'];
                $otherGroupId = (int) $newMember['group_id'];
                $this->upsertMember($activeSiswa, $activeVaDisplay, $activeYear, $groupId);
                $this->upsertMember($newSiswa, $newVaDisplay, $newYear, $groupId);

                if ($groupId !== $otherGroupId) {
                    $stmt = $this->db->prepare('UPDATE multi_account_members SET group_id = :gid, updated_at = NOW() WHERE group_id = :old');
                    $stmt->execute([':gid' => $groupId, ':old' => $otherGroupId]);
                    $del = $this->db->prepare('DELETE FROM multi_account_groups WHERE id = :id');
                    $del->execute([':id' => $otherGroupId]);
                }
            }

            $this->db->commit();

            return [
                'group_id' => $groupId,
                'accounts' => $this->listAccounts($activeNoCust, $activeNoCust),
                'active_no_cust' => $activeNoCust,
            ];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function syncMemberMeta(array $siswa, $vaDisplay, $academicYear)
    {
        $member = $this->findMember($siswa['no_cust'] ?? $vaDisplay);
        if (!$member) {
            return null;
        }

        return $this->upsertMember($siswa, $vaDisplay, $academicYear, (int) $member['group_id']);
    }

    /**
     * Hapus satu akun dari grup multi akun.
     * active_va harus berada di grup yang sama dengan target.
     * Jika sisa anggota < 2, grup dibubarkan.
     *
     * @return array{group_id:int|null, accounts:array, active_no_cust:string, removed_no_cust:string}
     */
    public function removeMember($activeVa, $targetVa)
    {
        $activeNoCust = $this->normalizeNoCust($activeVa);
        $targetNoCust = $this->normalizeNoCust($targetVa);

        if ($activeNoCust === '' || $targetNoCust === '') {
            throw new InvalidArgumentException('VA tidak valid');
        }

        $activeMember = $this->findMember($activeNoCust);
        $targetMember = $this->findMember($targetNoCust);

        if (!$activeMember) {
            throw new InvalidArgumentException('Akun aktif tidak berada dalam multi akun');
        }
        if (!$targetMember) {
            throw new InvalidArgumentException('Akun yang akan dihapus tidak ditemukan di multi akun');
        }
        if ((int) $activeMember['group_id'] !== (int) $targetMember['group_id']) {
            throw new InvalidArgumentException('Akun tujuan tidak terhubung dengan multi akun aktif');
        }

        $groupId = (int) $activeMember['group_id'];

        $this->db->beginTransaction();
        try {
            $del = $this->db->prepare('DELETE FROM multi_account_members WHERE no_cust = :no_cust LIMIT 1');
            $del->execute([':no_cust' => $targetNoCust]);

            $countStmt = $this->db->prepare('SELECT COUNT(*) FROM multi_account_members WHERE group_id = :gid');
            $countStmt->execute([':gid' => $groupId]);
            $remaining = (int) $countStmt->fetchColumn();

            if ($remaining < 2) {
                $clear = $this->db->prepare('DELETE FROM multi_account_members WHERE group_id = :gid');
                $clear->execute([':gid' => $groupId]);
                $delGroup = $this->db->prepare('DELETE FROM multi_account_groups WHERE id = :id');
                $delGroup->execute([':id' => $groupId]);
                $groupId = null;
                $accounts = [];
            } else {
                // List dari akun aktif jika masih ada, kalau aktif yang dihapus pakai anggota tersisa
                $listFrom = $this->findMember($activeNoCust) ? $activeNoCust : null;
                if (!$listFrom) {
                    $any = $this->db->prepare('SELECT no_cust FROM multi_account_members WHERE group_id = :gid LIMIT 1');
                    $any->execute([':gid' => $groupId]);
                    $listFrom = $any->fetchColumn() ?: $activeNoCust;
                }
                $accounts = $this->listAccounts($listFrom, $activeNoCust);
            }

            $this->db->commit();

            return [
                'group_id' => $groupId,
                'accounts' => $accounts,
                'active_no_cust' => $activeNoCust,
                'removed_no_cust' => $targetNoCust,
            ];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function getTagihanByVa($va, $tahunAkademik)
    {
        return $this->tagihan->cekTagihanByVA($va, $tahunAkademik);
    }

    public function getTagihanByVaPw($va, $password, $tahunAkademik)
    {
        return $this->tagihan->cekTagihanByVAPw($va, $password, $tahunAkademik);
    }
}
