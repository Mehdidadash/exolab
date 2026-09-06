-- Seed for price_links (run on the HOST database, e.g. phpMyAdmin SQL tab)
-- Generated 2026-09-05 00:15:35 from local DB — 11 rows.
-- Idempotent: rows already present (same keys, same active) are skipped.

INSERT INTO price_links (service_id, provider_type, provider_id, receiver_type, receiver_id, price, price_type, branch_id, note, active)
SELECT NULL, 'designer', 23, 'branch', 1, 120000.00, 'design_fee', 1, 'نرخ طراحی — پرداخت توسط شعبه اصلی', 1
WHERE NOT EXISTS (SELECT 1 FROM price_links WHERE service_id <=> NULL AND provider_type='designer' AND provider_id=23 AND receiver_type='branch' AND receiver_id <=> 1 AND price_type='design_fee' AND active = 1);
INSERT INTO price_links (service_id, provider_type, provider_id, receiver_type, receiver_id, price, price_type, branch_id, note, active)
SELECT 1, 'designer', 26, 'branch', 1, 160000.00, 'design_fee', 1, 'نرخ طراحی — پرداخت توسط شعبه اصلی', 1
WHERE NOT EXISTS (SELECT 1 FROM price_links WHERE service_id <=> 1 AND provider_type='designer' AND provider_id=26 AND receiver_type='branch' AND receiver_id <=> 1 AND price_type='design_fee' AND active = 1);
INSERT INTO price_links (service_id, provider_type, provider_id, receiver_type, receiver_id, price, price_type, branch_id, note, active)
SELECT 14, 'designer', 26, 'branch', 1, 60000.00, 'design_fee', 1, 'نرخ طراحی — پرداخت توسط شعبه اصلی', 1
WHERE NOT EXISTS (SELECT 1 FROM price_links WHERE service_id <=> 14 AND provider_type='designer' AND provider_id=26 AND receiver_type='branch' AND receiver_id <=> 1 AND price_type='design_fee' AND active = 1);
INSERT INTO price_links (service_id, provider_type, provider_id, receiver_type, receiver_id, price, price_type, branch_id, note, active)
SELECT 15, 'designer', 26, 'branch', 1, 80000.00, 'design_fee', 1, 'نرخ طراحی — پرداخت توسط شعبه اصلی', 1
WHERE NOT EXISTS (SELECT 1 FROM price_links WHERE service_id <=> 15 AND provider_type='designer' AND provider_id=26 AND receiver_type='branch' AND receiver_id <=> 1 AND price_type='design_fee' AND active = 1);
INSERT INTO price_links (service_id, provider_type, provider_id, receiver_type, receiver_id, price, price_type, branch_id, note, active)
SELECT 12, 'branch', 2, 'branch', 1, 250000.00, 'specific', 1, 'برون‌سپاری جانبی به شعبه قزوین (پرینت کست فک کامل)', 1
WHERE NOT EXISTS (SELECT 1 FROM price_links WHERE service_id <=> 12 AND provider_type='branch' AND provider_id=2 AND receiver_type='branch' AND receiver_id <=> 1 AND price_type='specific' AND active = 1);
INSERT INTO price_links (service_id, provider_type, provider_id, receiver_type, receiver_id, price, price_type, branch_id, note, active)
SELECT 13, 'branch', 2, 'branch', 1, 250000.00, 'specific', 1, 'برون‌سپاری جانبی به شعبه قزوین (پرینت کست نیم فک)', 1
WHERE NOT EXISTS (SELECT 1 FROM price_links WHERE service_id <=> 13 AND provider_type='branch' AND provider_id=2 AND receiver_type='branch' AND receiver_id <=> 1 AND price_type='specific' AND active = 1);
INSERT INTO price_links (service_id, provider_type, provider_id, receiver_type, receiver_id, price, price_type, branch_id, note, active)
SELECT 18, 'branch', 2, 'branch', 1, 1500000.00, 'specific', 1, 'برون‌سپاری جانبی به شعبه قزوین (لیرینگ روی فریم)', 1
WHERE NOT EXISTS (SELECT 1 FROM price_links WHERE service_id <=> 18 AND provider_type='branch' AND provider_id=2 AND receiver_type='branch' AND receiver_id <=> 1 AND price_type='specific' AND active = 1);
INSERT INTO price_links (service_id, provider_type, provider_id, receiver_type, receiver_id, price, price_type, branch_id, note, active)
SELECT 11, 'lab', 17, 'branch', 1, 1000000.00, 'specific', 1, 'برون‌سپاری به لابراتوار ساجدی (پست NPG)', 1
WHERE NOT EXISTS (SELECT 1 FROM price_links WHERE service_id <=> 11 AND provider_type='lab' AND provider_id=17 AND receiver_type='branch' AND receiver_id <=> 1 AND price_type='specific' AND active = 1);
INSERT INTO price_links (service_id, provider_type, provider_id, receiver_type, receiver_id, price, price_type, branch_id, note, active)
SELECT 1, 'branch', 1, 'branch', 2, 1000000.00, 'specific', NULL, 'نرخ بین‌شعبه‌ای: قزوین → شعبه اصلی (روکش زیرکونیا مولتی لیر)', 1
WHERE NOT EXISTS (SELECT 1 FROM price_links WHERE service_id <=> 1 AND provider_type='branch' AND provider_id=1 AND receiver_type='branch' AND receiver_id <=> 2 AND price_type='specific' AND active = 1);
INSERT INTO price_links (service_id, provider_type, provider_id, receiver_type, receiver_id, price, price_type, branch_id, note, active)
SELECT 1, 'branch', 1, 'lab', 18, 1300000.00, 'specific', 1, 'قیمت اختصاصی: لابراتوار امین به شعبه اصلی (کیس لابراتوار همکار)', 1
WHERE NOT EXISTS (SELECT 1 FROM price_links WHERE service_id <=> 1 AND provider_type='branch' AND provider_id=1 AND receiver_type='lab' AND receiver_id <=> 18 AND price_type='specific' AND active = 1);
INSERT INTO price_links (service_id, provider_type, provider_id, receiver_type, receiver_id, price, price_type, branch_id, note, active)
SELECT 2, 'branch', 1, 'branch', 2, 1000000.00, 'specific', NULL, '', 1
WHERE NOT EXISTS (SELECT 1 FROM price_links WHERE service_id <=> 2 AND provider_type='branch' AND provider_id=1 AND receiver_type='branch' AND receiver_id <=> 2 AND price_type='specific' AND active = 1);
