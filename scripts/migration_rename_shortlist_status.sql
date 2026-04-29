-- Rename candidates.shortlist_status -> candidates.screening_status
-- Run once against an existing database.

ALTER TABLE candidates
    CHANGE COLUMN shortlist_status screening_status
    ENUM('Pending','Yes','No','Doubtful') DEFAULT 'Pending';

ALTER TABLE candidates DROP INDEX idx_shortlist;
ALTER TABLE candidates ADD INDEX idx_screening (screening_status);
