-- RFID card lifecycle migration for existing library_management databases.
USE library_management;

CREATE TABLE IF NOT EXISTS rfid_card_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    rfid_number VARCHAR(100) NOT NULL,
    status ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMP NULL DEFAULT NULL,
    assigned_by INT NULL,
    revoked_by INT NULL,
    CONSTRAINT fk_rfid_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rfid_history_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_rfid_history_revoked_by FOREIGN KEY (revoked_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_rfid_history_user_status (user_id, status),
    INDEX idx_rfid_history_number (rfid_number)
);

-- Preserve existing assignments in the history table.
INSERT INTO rfid_card_history (user_id, rfid_number, status, assigned_at)
SELECT u.id, u.rfid_number, 'active', u.created_at
FROM users u
WHERE u.rfid_number IS NOT NULL
  AND TRIM(u.rfid_number) <> ''
  AND NOT EXISTS (
      SELECT 1
      FROM rfid_card_history h
      WHERE h.user_id = u.id
        AND h.rfid_number = u.rfid_number
        AND h.status = 'active'
  );

-- Enforce one active RFID value per student account.
ALTER TABLE users ADD UNIQUE KEY unique_rfid_number (rfid_number);
