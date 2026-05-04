ALTER TABLE candidates
  ADD COLUMN requires_scribe TINYINT(1) NOT NULL DEFAULT 0 AFTER disabled;