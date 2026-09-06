-- 032_fix_outsource_source_branch.sql
-- Existing cases that were side-outsourced (outsourced_lab_id) or fully
-- outsourced (lab_out) to a lab of ANOTHER branch may have source_branch_id NULL
-- (set before the fix). Fill it in so the receiving branch can see the case
-- and its financial view (طلب/بدهی) works correctly.

-- Side outsourcing: source branch = the branch of the receiving lab
UPDATE cases c
JOIN users olab ON c.outsourced_lab_id = olab.id
SET c.source_branch_id = olab.branch_id
WHERE c.source_branch_id IS NULL
  AND c.outsourced_lab_id IS NOT NULL
  AND c.outsourced_qty > 0
  AND olab.branch_id IS NOT NULL
  AND olab.branch_id <> c.branch_id;

-- Full outsourcing (lab_out): source branch = the branch of the receiving lab
UPDATE cases c
JOIN users lab ON c.lab_id = lab.id
SET c.source_branch_id = lab.branch_id
WHERE c.source_branch_id IS NULL
  AND c.case_type = 'lab_out'
  AND c.lab_id IS NOT NULL
  AND lab.branch_id IS NOT NULL
  AND lab.branch_id <> c.branch_id;
