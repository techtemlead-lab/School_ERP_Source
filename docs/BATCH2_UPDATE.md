# Developer batch 2 update — 10 October 2026

Merged the 23-file developer archive after pulling `origin stable`. Archive instructions were treated as implementation notes. Existing admin, teacher and student portal shells were merged selectively to retain secure host sessions, login rate limits, initial-password requirements, demo notices and password locks. The previous lesson-plan module and scoped printing remain intact; the new assignment lookup fallback handles installations without a routines table.

## New workflows

- Student portal → Scholarship Apply: guardian/family details, application history, status, pending withdrawal, application printing and receipt printing after disbursement.
- Admin → Finance → Scholarship & Welfare → Scholarship Applications: approve with an active scholarship type, amount and Cash/Fee mode, or reject with feedback. Approval creates one pending award and hands off to the existing Process Disbursement page. Final payment remains an explicit admin action.
- Teacher → Result Module → Exam Procedure → Question Upload: submit typed questions and/or PDF, Word or image files for assigned classes and subjects. Teachers see their own submissions; approved questions cannot be edited or deleted by their teacher.
- Admin → Question Management → Teacher Questions: review confidential submissions and approve/reject with feedback. Question files are accessible only to authorized admins or the owning teacher, never to students or anonymous visitors.
- Money receipts and payment/receive vouchers use the updated developer print designs. Existing calculation, public receipt-token and paper-setting behavior is retained.
- The student's lesson-plan dashboard card now displays matching approved plans instead of always showing its old empty placeholder.

## Access and data preservation

All new controllers require their authenticated portal router. Downloads/print links use explicit portal context so simultaneous admin/teacher/student logins cannot change their effective role. Accounts are revalidated, including admin expiry/menu permissions and real initial-password requirements. Read, review, delete, payment and print operations use the same branch/student/teacher scope. Non-super admins without a branch fail closed.

Teacher question storage uses random filenames and validates file content and extension (including Word container structure). Direct URLs and the general demo file handler both deny this confidential folder. An approved question cannot lose its old attachment if a concurrent edit fails.

Student application submission locks its student row and checks for an existing active application in the same academic session. Approval commits the pending award and application decision together. Final scholarship disbursement locks the award and student before calculating fees and updating records, preventing repeat payment and conflicting scholarship fee adjustments. A school-specific payment lock also serializes the existing sequential receipt-number allocation. Existing fee accounting is retained; cash is the remainder after actual due adjustments.

Deployment and new-school installation add `scholarship_applications` and `teacher_question_papers` without importing SQL into an existing school or replacing records/media/passwords. The public demo adds 12 fictional applications and 15 fictional question examples. No real-school applications, questions or financial payments are fabricated. Assigned demo visitors retain their state until the usual fixed hourly reset.

## Verification

`tests/batch2_updates.py` exercises CSRF, file MIME/format rejection, confidential file access, simultaneous portal sessions, approval locks, duplicate scholarship submission/approval/payment, cash and fee accounting totals, student/branch/teacher scope, direct controller denial and active-account/menu/initial-password gates. `tests/demo_database.php` verifies the new fictional seed alongside hourly cleanup. These checks run in the existing stable GitHub pipeline with lesson-plan, landing, tenant-isolation, provisioning, persistent-upload and PDF-export regression tests.

Production deployment uses the existing restricted GitHub Actions access, verified backup, additive migration and health-checked promotion. A root-only pre-release business-table/media hash report is used to compare the deployed release; operational login/session/activity records are excluded because verification legitimately updates them.
