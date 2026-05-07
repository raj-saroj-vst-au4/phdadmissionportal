-- OMR upload, answer key and per-candidate result storage.
-- Apply once: mysql phdadmissions < scripts/migration_add_omr.sql

CREATE TABLE IF NOT EXISTS omr_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    intake_id INT NOT NULL,
    num_questions INT NOT NULL,
    layout_json TEXT NOT NULL,
    answer_json TEXT NOT NULL,
    uploaded_by INT NULL,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_intake (intake_id),
    FOREIGN KEY (intake_id) REFERENCES intakes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS omr_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    candidate_id INT NOT NULL,
    intake_id INT NOT NULL,
    qr_code VARCHAR(100) NULL,
    correct_count INT NOT NULL DEFAULT 0,
    wrong_count INT NOT NULL DEFAULT 0,
    blank_count INT NOT NULL DEFAULT 0,
    multi_count INT NOT NULL DEFAULT 0,
    marks DECIMAL(7,2) NOT NULL DEFAULT 0,
    answers_json TEXT NULL,
    page_image VARCHAR(255) NULL,
    processed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cand (candidate_id),
    KEY idx_intake (intake_id),
    FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE,
    FOREIGN KEY (intake_id) REFERENCES intakes(id) ON DELETE CASCADE
) ENGINE=InnoDB;
