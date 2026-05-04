-- PhD Admissions Portal — schema for SJMSOM IIT Bombay
-- Run once on a fresh database, then `php scripts/install.php` to seed admin/panel users.

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    role ENUM('admin','panel') NOT NULL DEFAULT 'panel',
    panel_code VARCHAR(30) NULL,
    panel_area VARCHAR(100) NULL,
    active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS intakes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    season ENUM('Spring','Autumn') NOT NULL,
    year INT NOT NULL,
    entrance_mode ENUM('Written','CBT') NULL,
    entrance_datetime DATETIME NULL,
    interview_datetime DATETIME NULL,
    cutoff_marks DECIMAL(6,2) NULL,
    ta_seats_gn  INT NULL,
    ta_seats_obc INT NULL,
    ta_seats_sc  INT NULL,
    ta_seats_st  INT NULL,
    ta_seats_ews INT NULL,
    is_active TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_intake (season, year)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS candidates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    intake_id INT NOT NULL,
    serial_no INT NULL,
    applicant_id VARCHAR(50) NULL,
    is_international TINYINT(1) DEFAULT 0,
    password_no VARCHAR(30) NULL,
    dept_reg_no VARCHAR(50) NOT NULL,
    name VARCHAR(200) NOT NULL,
    email VARCHAR(150) NULL,
    gender VARCHAR(10) NULL,
    birth_category VARCHAR(30) NULL,
    ews VARCHAR(5) NULL,
    disabled VARCHAR(5) NULL,
    requires_scribe TINYINT(1) NOT NULL DEFAULT 0,
    cfti VARCHAR(5) NULL,
    iit_btech VARCHAR(5) NULL,
    categories_applied VARCHAR(150) NULL,
    revised_categories_applied VARCHAR(150) NULL,
    academic_record TEXT NULL,
    qualifying_exam VARCHAR(100) NULL,
    exam_status VARCHAR(100) NULL,
    qualifying_discipline VARCHAR(100) NULL,
    passing_year VARCHAR(10) NULL,
    percentage VARCHAR(30) NULL,
    original_percentage VARCHAR(30) NULL,
    original_percentage_out_of VARCHAR(20) NULL,
    cpi_grade VARCHAR(30) NULL,
    gate_score VARCHAR(30) NULL,
    gate_year VARCHAR(10) NULL,
    gate_regn VARCHAR(50) NULL,
    work_experience VARCHAR(30) NULL,
    fellowship VARCHAR(200) NULL,
    research_interest_selected TEXT NULL,
    research_interest_other TEXT NULL,
    nationality VARCHAR(100) NULL,
    remark TEXT NULL,
    screening_status ENUM('Pending','Yes','No','Doubtful') DEFAULT 'Pending',
    written_marks DECIMAL(6,2) NULL,
    written_remark TEXT NULL,
    s1_correct INT NULL,
    s1_wrong INT NULL,
    s2_correct INT NULL,
    s2_wrong INT NULL,
    s3_correct INT NULL,
    s3_wrong INT NULL,
    s4_correct INT NULL,
    s4_wrong INT NULL,
    cbt_file VARCHAR(255) NULL,
    application_pdf VARCHAR(255) NULL,
    photo VARCHAR(255) NULL,
    passed_cutoff TINYINT(1) DEFAULT 0,
    panel_code VARCHAR(30) NULL,
    panel_area VARCHAR(150) NULL,
    final_status ENUM('Pending','Selected','Not Selected','Waitlisted') DEFAULT 'Pending',
    final_category VARCHAR(20) NULL,
    birth_category_number VARCHAR(20) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dept (intake_id, dept_reg_no),
    KEY idx_intake (intake_id),
    KEY idx_screening (screening_status),
    FOREIGN KEY (intake_id) REFERENCES intakes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS interview_marks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    candidate_id INT NOT NULL,
    panel_user_id INT NOT NULL,
    functional_knowledge DECIMAL(5,2) NOT NULL DEFAULT 0,
    research_aptitude DECIMAL(5,2) NOT NULL DEFAULT 0,
    research_proposal_quality DECIMAL(5,2) NOT NULL DEFAULT 0,
    communication_skill DECIMAL(5,2) NOT NULL DEFAULT 0,
    total_marks DECIMAL(6,2) GENERATED ALWAYS AS
        (functional_knowledge + research_aptitude + research_proposal_quality + communication_skill) STORED,
    recommended TINYINT(1) DEFAULT 0,
    ug_marks VARCHAR(30) NULL,
    pg_marks VARCHAR(30) NULL,
    competitive_exam_marks VARCHAR(30) NULL,
    notes TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cand_panel (candidate_id, panel_user_id),
    KEY idx_panel_user (panel_user_id),
    FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE,
    FOREIGN KEY (panel_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS panels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) UNIQUE NOT NULL,
    area VARCHAR(150) NOT NULL,
    description TEXT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    intake_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    capacity INT NOT NULL DEFAULT 30,
    is_pwd_scribe TINYINT(1) DEFAULT 0,
    notes VARCHAR(200) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_intake (intake_id),
    FOREIGN KEY (intake_id) REFERENCES intakes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS room_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    candidate_id INT NOT NULL,
    room_id INT NOT NULL,
    seat_no VARCHAR(10) NULL,
    UNIQUE KEY uq_cand (candidate_id),
    KEY idx_room (room_id),
    FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(80) PRIMARY KEY,
    `value` TEXT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS upload_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    intake_id INT NULL,
    filename VARCHAR(255) NOT NULL,
    rows_inserted INT DEFAULT 0,
    rows_updated INT DEFAULT 0,
    rows_skipped INT DEFAULT 0,
    uploaded_by INT NULL,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS email_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    intake_id INT NULL,
    phase VARCHAR(40) NOT NULL,
    recipient_count INT DEFAULT 0,
    subject VARCHAR(255) NULL,
    body_preview VARCHAR(500) NULL,
    sent_by INT NULL,
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(30) DEFAULT 'sent',
    KEY idx_intake (intake_id)
) ENGINE=InnoDB;
