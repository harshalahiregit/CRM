# These docs describe the package. The code beside them is what runs.

Moved here from the original `SIRE-v1.1-PLUG-AND-PLAY/` distributable when that
folder was deleted. Everything in this directory still describes SIRE as it was
shipped, and that is useful — the architecture, the tenancy strategies, the
thirteen SDK seams, the compatibility matrix and the deployment rules have not
changed.

**What has changed is the code.** Installing SIRE into this CRM was the first
time it had ever been installed into a real host, and it surfaced bugs that are
fixed in `../src` and are still described as working in these pages:

- `ReportController` had no `show()`, and `routes/sire.php` registered neither
  `POST reports` nor `GET reports/{report}` — Report Issue posted to a 404.
- `store()` called `SireWorkflowService::submit()`, a method that does not exist,
  so every report filed from the button returned a 500.
- The attachment provider read `$owner->tenantId` on an Eloquent model whose
  attribute is `tenant_id`, so every upload landed in `sire/0/`.
- There was no route to download an attachment at all.
- `SireDoctor` used uppercase statuses in one check, which fataled the doctor.

Two checks were added that the package did not have: **Build parity**, and a
**production warning when no seam is host-connected**.

So read these for intent and design. Read `../src` for behaviour. Where they
disagree, the code is right.
