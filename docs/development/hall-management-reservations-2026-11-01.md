# Active-reservation display follow-up

Scope: keep four queues, replace draft cards with active official reservations,
limit preinvoices to 24 hours from the oldest open official hold, and increase
visible card capacity. No business documents or reservations are changed.

Validation: 29 focused PHP tests / 99 assertions and 12 JavaScript tests pass.
Regression cases include missing, temporary, zero, expired, released and converted
holds; exact 24-hour boundary; stale drafts; old drafts with fresh official holds;
released orders; and conversion identity. Browser fixture checks at 1280x720 and
1920x1080 show 3 and 5 cards per column, with no card or document overflow.
The full repository suite has previously documented baseline failures; these
focused results are not a claim that the whole repository suite passes.

Deployment needs no migration. Backup selected source files, compiled config,
Git index and unrelated untracked-file hashes under the private r2 release
backup before atomically replacing source. Preserve all unrelated stocktake
files. Check snapshot schema version 2, queue counts, access restrictions,
asset responses, console and live rendering after deployment. Existing browser
tabs require one refresh for the new payload version.
