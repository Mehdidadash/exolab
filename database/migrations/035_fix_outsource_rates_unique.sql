-- 035_fix_outsource_rates_unique.sql
-- The outsource_rates table previously had UNIQUE(lab_id, service_id), but the
-- code treats rates as BRANCH-scoped (branch_id column). Because of that, when a
-- branch tried to save its own rate for a (lab, service) that already had a row
-- from another branch, MySQL raised "Duplicate entry 'x-y' for key 'lab_id'".
-- Make the unique key branch-aware, and make the central inter-branch rate
-- (lab 27 / service 1) GLOBAL so every branch sees it.
ALTER TABLE outsource_rates DROP INDEX lab_id,
    ADD UNIQUE KEY lab_id (lab_id, service_id, branch_id);

-- The inter-branch rate (نرخ بین شعب) must be visible to every branch.
UPDATE outsource_rates SET branch_id = NULL WHERE lab_id = 27 AND service_id = 1;
