-- Jalankan di database WS/billing (sama dengan config/database.php).
-- Bukan database Laravel lokal.

CREATE TABLE IF NOT EXISTS pwa_qris (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  custid VARCHAR(64) NOT NULL,
  nocust VARCHAR(64) NOT NULL,
  namacust VARCHAR(191) NULL,
  vano VARCHAR(64) NULL,
  amount DECIMAL(18,2) NOT NULL,
  qris_id VARCHAR(128) NULL,
  qris_content TEXT NULL,
  transaction_id VARCHAR(64) NULL,
  account_no VARCHAR(64) NULL,
  mitra_customer_id VARCHAR(191) NULL,
  description VARCHAR(255) NULL,
  status ENUM('pending','paid','expired','failed') NOT NULL DEFAULT 'pending',
  paid_flag TINYINT(1) NOT NULL DEFAULT 0,
  request_payload MEDIUMTEXT NULL,
  response_payload MEDIUMTEXT NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  paid_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_pwa_qris_custid (custid),
  KEY idx_pwa_qris_nocust (nocust),
  KEY idx_pwa_qris_status (status),
  KEY idx_pwa_qris_qris_id (qris_id),
  KEY idx_pwa_qris_vano (vano)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pwa_qris_item (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_id BIGINT UNSIGNED NOT NULL,
  aa BIGINT UNSIGNED NOT NULL,
  billcd VARCHAR(64) NULL,
  nama_tagihan VARCHAR(255) NULL,
  amount DECIMAL(18,2) NOT NULL,
  is_cicil TINYINT(1) NOT NULL DEFAULT 0,
  sisa_sebelum DECIMAL(18,2) NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_pwa_qris_item_payment (payment_id),
  CONSTRAINT fk_pwa_qris_item_payment
    FOREIGN KEY (payment_id) REFERENCES pwa_qris(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pwa_log_qris_push (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(32) NOT NULL,
  qris_id VARCHAR(128) NULL,
  transaction_id VARCHAR(64) NULL,
  vano VARCHAR(64) NULL,
  custid VARCHAR(64) NULL,
  nocust VARCHAR(64) NULL,
  amount DECIMAL(18,2) NULL,
  paid_flag TINYINT(1) NULL,
  processed VARCHAR(32) NULL,
  scctva_status VARCHAR(32) NULL,
  response_code VARCHAR(8) NULL,
  response_message VARCHAR(255) NULL,
  http_code SMALLINT UNSIGNED NULL,
  request_payload MEDIUMTEXT NULL,
  response_payload MEDIUMTEXT NULL,
  source VARCHAR(128) NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_pwa_log_qris_payment (payment_id),
  KEY idx_pwa_log_qris_qris_id (qris_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
