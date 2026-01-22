-- SQL Script to check and fix supervisor department IDs
-- Run this in SQL Server Management Studio or similar tool

-- First, check what department IDs we have
SELECT dept_id, department_name FROM departments;

-- Check current supervisor records
SELECT id, grn_checked_by, menu, department_id FROM check_by WHERE menu = 'Supervisor';

-- Get the Dipping department ID
DECLARE @DippingDeptId INT;
SELECT @DippingDeptId = dept_id FROM departments WHERE department_name = 'Dipping';
SELECT 'Dipping Department ID:', @DippingDeptId;

-- Update all supervisors to belong to Dipping department if they don't have a department set
-- (Uncomment the line below to actually run the update)
-- UPDATE check_by SET department_id = @DippingDeptId WHERE menu = 'Supervisor' AND (department_id IS NULL OR department_id = 0);

-- Or if you want to set specific supervisors to Dipping department, use:
-- UPDATE check_by SET department_id = @DippingDeptId 
-- WHERE menu = 'Supervisor' AND grn_checked_by IN ('SupervisorName1', 'SupervisorName2');

-- Check the results after update
SELECT id, grn_checked_by, menu, department_id FROM check_by WHERE menu = 'Supervisor';