# Lesson-plan update — 9 October 2026

Imported the developer's `lesson_plan_update_for_client.zip` into the existing stable source after pulling GitHub. The archive README was treated as implementation notes. Older copies of the admin/teacher portal shells were merged selectively to preserve host validation, secure sessions, login rate limits, initial-password requirements, demo notices and demo password locks.

## Workflow

- Teacher: E-Learning Module → Lesson Plan. Create a draft or submit an assigned class/subject plan for approval.
- School admin: E-Learning Module → Lesson Plan. Review pending plans, approve, or reject with feedback. Admin-created plans are approved immediately.
- Student: My Lesson Plan shows approved plans for the student's branch, class, section and academic session.
- Print links open the selected portal's authorized copy and support browser Print / Save PDF. Editing an approved teacher plan withdraws its approval until reviewed again.

Each school uses its own existing isolated database. The release adds `elearning_lesson_plans` and a section branch column if missing. It does not import vendor SQL, reseed a real school, change account passwords, or replace existing records/media. The production CLI migration and new-school installer include the additive schema. Existing private demo sessions keep their state until the usual hourly reset; fresh demo databases contain 18 fictional examples, including 15 approved plans.

## Access rules

Authorization uses the portal route rather than whichever login flag happens to exist in a shared browser session. Teachers can create only assigned class/subject plans and edit/print their own plans. Section and teacher selections must belong to the target branch. Branch-scoped admins cannot read, modify, review or print another branch's plans. Restricted admin accounts need the `lesson_plan` menu permission. Students cannot view or print unapproved plans or plans belonging to a different class, section, session or branch. Standalone printing rechecks active accounts and initial-password requirements. Direct controller URLs are denied.

Legacy class/subject assignments authorize the class and subject within the teacher's branch; routine-based assignments are also checked against their section. A teacher without a branch in a school with multiple branches must be assigned before creating a plan. Legacy empty branch fields remain usable in a school with a single active branch.

## Verification

`tests/lesson_plans.py` exercises the HTTP approval/rejection flow, simultaneous portal logins, CSRF rejection, edits requiring reapproval, escaped user content, scoped printing, branch/class/section/session restrictions and separate demo visitors. It runs in the existing stable GitHub workflow before deployment. Database checks verify the fictional seed and hourly cleanup. The worker is stopped during the final CLI cleanup test to avoid two workers competing for the maintenance lock.

Production rollout uses the existing restricted GitHub Actions deployment, verified database/media backup and additive migration. Infrastructure secrets and existing school credentials stay outside the source archive.
