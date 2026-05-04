-- One-time migration: collapse RA / RA/TA / TA/RA values to TA in candidates table.
-- Run once after deploying the normalize_categories_applied changes; subsequent uploads/edits
-- pass through normalize_categories_applied() in helpers.php and extract_excel.py.

-- Exact-match cases (case- and whitespace-insensitive) on the original column.
UPDATE candidates
   SET categories_applied = 'TA'
 WHERE UPPER(REPLACE(categories_applied, ' ', '')) IN ('RA', 'RA/TA', 'TA/RA');

-- Same for the admin-revised column.
UPDATE candidates
   SET revised_categories_applied = 'TA'
 WHERE UPPER(REPLACE(revised_categories_applied, ' ', '')) IN ('RA', 'RA/TA', 'TA/RA');

-- Standalone "RA" tokens inside list-style values (e.g. "RA, SF" -> "TA, SF").
-- Requires MySQL 8+ for REGEXP_REPLACE (ICU). NOTE: backreferences use $1/$2 (ICU), NOT \1/\2.
UPDATE candidates
   SET categories_applied = REGEXP_REPLACE(categories_applied, '(^|[[:space:],/])RA([[:space:],/]|$)', '$1TA$2')
 WHERE categories_applied REGEXP '(^|[[:space:],/])RA([[:space:],/]|$)';

UPDATE candidates
   SET revised_categories_applied = REGEXP_REPLACE(revised_categories_applied, '(^|[[:space:],/])RA([[:space:],/]|$)', '$1TA$2')
 WHERE revised_categories_applied REGEXP '(^|[[:space:],/])RA([[:space:],/]|$)';
