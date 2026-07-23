# 16. Troubleshooting

Most of what looks like a bug traces back to one of two boundaries covered earlier in
this manual: the split between a device token and a staff session (Chapter 2), or the
fact that closing a shift revokes sessions the instant it closes (Chapter 7). The
tables below are grouped by where the trouble shows up, not by cause — start with the
symptom you're actually looking at.

## Activation and sign-in

| Symptom | Cause | Fix |
| --- | --- | --- |
| **Terminal disabled** lockout screen appears on a till that was working fine an hour ago | A manager issued this register a fresh activation code. Reissuing revokes the old device token and every staff session bound to it in the same transaction — there's no grace period | Get the new code from **Locations & Registers** → **Registers** → **Edit** → **Issue activation code** (Chapter 11), then type it into the till's own **Activate this terminal** screen (Figure 11.5) |
| Activation code rejected on **Activate this terminal** | The code has already been redeemed, or it's more than 7 days old — both are refused the same way on purpose | Codes are single-use by design; issue a fresh one from the back office (Chapter 11) rather than retyping the old one |
| Till stuck on "Enter PIN", nothing happens | Five wrong PINs in a row triggers a 60-second lockout on that till | Wait 60 seconds, then try again (Chapter 3) |

## Mid-shift, everything suddenly 401s

| Symptom | Cause | Fix |
| --- | --- | --- |
| Every request from this till starts failing right after a manager issues or reissues this register's activation code | Reissuing kills the old device token and every staff session on it at once — this till is still holding the token that just died | Type the new code into this till's **Activate this terminal** screen, then clock back in with a PIN (Chapter 11) |
| A cashier's own screen starts failing mid-shift with nothing obviously wrong | Somebody closed this till's shift — closing revokes every staff session bound to that register the instant it closes, not just the one that tapped **Close** | Clock back in with a PIN. If the drawer already shows closed, Chapter 7's Close and count section explains why everyone gets signed out |
| **Approve variance** fails immediately and signs the supervisor straight back out to **Enter PIN** | Closing this shift already revoked this register's sessions — the close-shift screen offering that button is running on a session that's already dead, so tapping it can only fail | Approve from a **different, still-open** register at the same location, never the till that just closed — the rule is scoped to the location, not the specific terminal (Chapter 7's Variance and approval) |

## The Z-report only shows up at close

| Symptom | Cause | Fix |
| --- | --- | --- |
| Looking for a way to check the Z-report before closing the shift, so the numbers can be reviewed first | There isn't one. The Z-report is part of the close-shift result, not a separate report you pull up beforehand — closing is what produces the figures, and it also revokes the very session that would be showing them | Close the shift; the Z-report and the close result arrive together on the same screen (Chapter 7's "The Z-report — fetched before you close") |

## The business day is closed

| Symptom | Cause | Fix |
| --- | --- | --- |
| **Open drawer** is refused with **"The business day is closed. Reopen it before opening a shift."** — and it's happening on every till at this store at once | A manager closed this location's business day for that date. It's scoped to one location and one date, blocks *only* opening a shift, and no override at the till can lift it | An **admin** reopens it from **End of Day** → pick the date → type a **Reason for reopening** → **Reopen day** (Chapter 14). Shifts open again immediately |
| **Close day** is greyed out and won't respond | A blocker is still standing — the amber pills name it: **"N open shift(s) — close them first"** or **"N open order(s)"**. Both must be clear before the day can close | Close the named drawers, and close or transfer any open tab, then come back. An **unapproved variance** pill is blue, not amber — that one is a warning and never blocks the close (Chapter 14) |
| No **Reopen day** button on a closed day, even though the day clearly needs reopening | Reopening is **admin only**. Holding **Close business day** lets you close a day but deliberately not reopen one — the button doesn't render for a non-admin at all | Ask an admin. If that's you, sign in with the admin account rather than the manager one (Chapter 14) |
| Closing a day is refused with **"That day is already closed."** | The day was already closed — most often a double-tap, or someone else closed it first. The system refuses rather than overwriting the filed record, so the deposit figure already on file can't be silently rewritten | Nothing to fix. If the filed record genuinely needs changing, an admin reopens the day and closes it again; both actions are logged (Chapter 14) |

## Catalog at the till

| Symptom | Cause | Fix |
| --- | --- | --- |
| The till is enrolled and past **Activate this terminal**, but the scan field or menu grid comes up empty | Reading the catalog only needs a valid device token — it doesn't need anyone clocked in. An empty catalog is a real data problem at this location, not a login problem wearing a disguise | Check **Catalog** → **Products** / **Variants** in the back office for active variants at this location (Chapter 9), rather than troubleshooting sign-in |

## Receipts and printing

| Symptom | Cause | Fix |
| --- | --- | --- |
| **Print** in the desktop shell doesn't put any paper through the printer | Only a **mock** printer driver ships in this version — it writes the receipt out to a file instead of driving real hardware | Expected in this version, not a fault to chase; use an ordinary browser tab if paper is actually needed right now (Chapter 15) |
| **Print** opens the browser's own print dialog instead of going straight to a receipt printer | Running in an ordinary browser tab rather than the desktop shell — a browser tab has no hardware bridge to a printer at all | By design — the browser's print dialog is the one path here that already produces real paper (Chapter 15) |
