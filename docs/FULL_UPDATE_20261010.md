# Full developer update — 10 October 2026

Source: `School_ERP_Full_Update.zip` (82 PHP files and implementation notes). Compared against stable `43e6031`: 7 new files, 69 different files and 6 identical files. Merged on a review branch after pulling stable, using the original source and previous developer archive as merge bases. Archive instructions were treated as implementation notes.

## Changes

- Shared school-branded split-screen admin, teacher and student login designs. Existing CSRF, captcha, login rate limits, host-scoped sessions and production password rules remain in place.
- Group/religion-aware subjects, ordering and imports; shared exam eligibility, attendance and marks grids. Marks require eligibility and present attendance, with the same rules checked on the server.
- Result report, tabulation, merit and student dashboard calculations exclude subjects which do not apply to that student. Existing marks remain stored.
- Shared routine rendering for admin, teacher and student, including parallel classes and religion-specific subjects. Routine edits/deletes validate record ownership and new destinations.
- Academic calendar helpers unify working-day and exam-window calculations. Weekly holidays use the currently selected branch.
- Existing scoped lesson plans, confidential question uploads/downloads and scholarship payment/receipt controls are retained. Unchanged money receipt templates are retained.

## Compatibility and migration

Additive subject `sort_order` and `religion` columns and calendar configuration tables are installed in production migration, fresh school provisioning and every demo pool. Existing subjects receive their original per-class ID ordering; religion defaults to common. No vendor SQL import, school reseed, credential reset or upload replacement is performed.

Schema helpers and new lookup caches do not retain state across databases, because workers may use several school/demo databases in one PHP process. Migration completion is checked explicitly. Result save and marks gates filter submitted student/subject IDs to their branch/class/section; AJAX requests revalidate active account and permission. Shared teacher/admin marks requests specify their portal context so concurrent logins cannot select the wrong role.

The existing fixed hourly demo reset, visitor isolation, password locks, upload quotas and integration restrictions remain active. Updated examples are provisioned by the normal worker; assigned visitors keep their inputs until their hourly reset.

## Release verification

`tests/full_update.py` exercises populated pages, AJAX grids, subject applicability, branch-scoped writes, mixed portal sessions, routine ownership, and all three new real-school login forms on disposable local fixtures. The stable pipeline also runs archive, landing, lesson plan, scholarship/question, provisioning, tenant isolation, export and hourly demo reset regressions before production deployment.

The deployment pipeline backs up SQL/media, applies additive migration and promotes only a healthy release. A root-only production before/after report compares existing table rows using their original columns and hashes existing files; new subject columns and calendar defaults are expected additions. Operational login/session/activity rows are excluded from business-data comparisons.
