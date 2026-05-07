-- Replace panels with the 2026 set (8 panels). Idempotent.
-- Safe only because no users/candidates currently reference any panel_code
-- (verify with: SELECT COUNT(*) FROM users WHERE panel_code IS NOT NULL;
--               SELECT COUNT(*) FROM candidates WHERE panel_code IS NOT NULL;).

INSERT INTO panels(code, area) VALUES
  ('EP',   'Economics and Policy'),
  ('MKT',  'Marketing'),
  ('TMSC', 'Technology Management and Strategy IB Competitiveness'),
  ('DSIT', 'DS and IT'),
  ('FIN',  'Finance and Accounting'),
  ('OM',   'OM'),
  ('HROB', 'HR and OB'),
  ('ENT',  'Entrepreneurship')
ON DUPLICATE KEY UPDATE area = VALUES(area);

DELETE FROM panels
WHERE code NOT IN ('EP','MKT','TMSC','DSIT','FIN','OM','HROB','ENT');
