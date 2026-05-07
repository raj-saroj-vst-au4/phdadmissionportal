-- Add confidence + review-flag columns to omr_results.
-- Apply once: mysql phdadmissions < scripts/migration_omr_confidence.sql

ALTER TABLE omr_results
    ADD COLUMN confidence DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER multi_count,
    ADD COLUMN review_needed TINYINT(1) NOT NULL DEFAULT 0 AFTER confidence;
