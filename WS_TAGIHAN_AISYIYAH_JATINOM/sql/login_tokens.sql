-- Jalankan di database WS/billing (contoh: sidoarjo_raudhatul_jannah)
-- Bukan database Laravel lokal.
-- Dipakai dashboard admin (project terpisah) untuk membuat link login otomatis:
--   {APP_URL}/{token}
-- Kolom `token` = path URL (hex 64 karakter). Kolom `no_cust` = NIS tanpa prefix bank.

CREATE TABLE IF NOT EXISTS login_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token CHAR(64) NOT NULL COMMENT 'Hex 64 karakter; path URL GET /{token}',
  no_cust VARCHAR(50) NOT NULL COMMENT 'NIS/VA sudah dinormalisasi (tanpa 757777)',
  custid INT NULL COMMENT 'scctcust.CUSTID jika diketahui',
  tahun_akademik VARCHAR(50) NOT NULL DEFAULT 'all',
  expires_at DATETIME NOT NULL COMMENT 'Link mati setelah waktu ini',
  used_at DATETIME NULL COMMENT 'NULL = belum dipakai; diisi saat login sukses (sekali pakai)',
  created_by VARCHAR(100) NULL COMMENT 'Id/nama admin dari dashboard admin',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_login_tokens_token (token),
  KEY idx_login_tokens_no_cust (no_cust),
  KEY idx_login_tokens_expires (expires_at),
  KEY idx_login_tokens_used (used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contoh insert dari dashboard admin:
-- INSERT INTO login_tokens (token, no_cust, custid, tahun_akademik, expires_at, created_by)
-- VALUES (
--   'a8f3c91e0123456789abcdef0123456789abcdef0123456789abcdef01234567',
--   '123456789',
--   42,
--   'all',
--   DATE_ADD(NOW(), INTERVAL 24 HOUR),
--   'admin-12'
-- );
