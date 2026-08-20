-- ============================================================
-- Migration 005: Knowledge Base starter content
-- 14 self-help articles across all categories.
-- Safe to re-run: slugs are UNIQUE and INSERT IGNORE is used.
-- ============================================================

USE `belmont_helpdesk`;

INSERT IGNORE INTO kb_articles (kb_cat_id, author_id, title, slug, body, is_published) VALUES

-- ===== 1. Getting Started =====
(1, 1, 'How to Use the AI Ticket Writer', 'how-to-use-ai-ticket-writer',
'Problem: You are not sure how to describe your issue clearly when submitting a ticket.

Solution:
1. Go to Tickets > New Ticket.
2. Type a few rough words about your problem in the Subject field (e.g. "printer 2nd floor jam").
3. Click the "Write with AI" button next to the subject.
4. The AI writes a complete subject and description, and automatically sets the department, category, priority, and due date.
5. Review the description and replace any [bracketed] hints with your actual details.
6. Click Submit Ticket.

Prevention / Tips: The more specific your draft words, the better the AI output. Mention the device, location, and what stopped working.', 1),

(1, 1, 'Understanding Ticket Statuses and Priorities', 'understanding-ticket-statuses-priorities',
'Problem: You do not know what your ticket status means or when it will be handled.

Statuses:
- Open: received and waiting for a handler.
- In Progress: someone is actively working on it.
- Pending: waiting for your reply or for a third party.
- Resolved: a fix was applied; reply if the issue persists, otherwise it closes automatically after a few days.
- Closed: completed and archived.

Priorities:
- Critical: business stopped (handled within 1 day).
- High: a person is fully blocked (2-3 days).
- Medium: degraded but workable (5-7 days).
- Low: requests and supplies (7-14 days).

Prevention / Tips: If your issue becomes more urgent, post a reply on the ticket so the team is notified.', 1),

-- ===== 2. Account & Access =====
(2, 1, 'Forgot Password — How to Reset It', 'forgot-password-how-to-reset',
'Problem: You cannot log in because you forgot your password.

Solution:
1. Ask a colleague in your department or your supervisor to submit a "Password reset" ticket for you, or contact the IT Department directly at it@belmont.ph.
2. IT will verify your identity and issue a temporary password.
3. Log in with the temporary password.
4. Go to your Profile (top-right avatar > My Profile) and set a new password under "Change Password".

Prevention / Tips: Use a passphrase you can remember (at least 6 characters). Never share your password — every employee has their own personal login even though notifications go to the shared department inbox.', 1),

(2, 1, 'Locked Out of NetSuite / ERP Account', 'locked-out-netsuite-erp-account',
'Problem: Your NetSuite or ERP account is locked after too many failed login attempts.

Possible Cause: Entering a wrong password several times triggers an automatic lockout for security.

Solution:
1. Wait 15 minutes — short lockouts clear automatically.
2. If still locked, submit a ticket with category "NetSuite / ERP" and include your NetSuite email address.
3. IT will unlock the account and confirm by email.

Prevention / Tips: If you recently changed your password, update it everywhere it is saved (browser, mobile app) so old saved passwords do not trigger the lockout again.', 1),

(2, 1, 'Requesting Access for a New Employee', 'requesting-access-new-employee',
'Problem: A new team member needs a system account, email, and access to shared folders.

Solution:
1. The supervisor submits a ticket with category "Account / Access" at least 3 working days before the start date.
2. Include: full name, position, department, start date, and the systems needed (helpdesk, NetSuite, email, shared drives, POS).
3. HR confirms the hire, then IT creates the accounts.
4. Credentials are handed to the supervisor on day one.

Prevention / Tips: One ticket per employee keeps the audit trail clean. For resignations, submit a "disable account" ticket on the last working day.', 1),

-- ===== 3. Hardware & Devices =====
(3, 1, 'Printer Not Printing or Paper Jam', 'printer-not-printing-paper-jam',
'Problem: The office printer does not respond, prints blank pages, or reports a paper jam.

Solution:
1. Check the printer screen for an error message.
2. For paper jams: open the indicated tray/cover, pull the paper out slowly in the direction of the paper path, and close all covers firmly.
3. Turn the printer off, wait 10 seconds, turn it on.
4. Confirm the paper tray is not overfilled and the paper is not damp.
5. On your PC, cancel stuck jobs: Settings > Devices > Printers > Open queue > Cancel all.
6. Still failing? Submit a ticket with category "Hardware Issue" and include the printer location and error message.

Prevention / Tips: Fan the paper stack before loading and never mix paper sizes in one tray.', 1),

(3, 1, 'Computer Running Very Slow', 'computer-running-very-slow',
'Problem: Your PC takes a long time to start or freezes during work.

Possible Cause: Too many startup programs, a full disk, pending updates, or failing hardware.

Solution:
1. Restart the computer — fixes the majority of slowdowns.
2. Close browser tabs and programs you are not using.
3. Check free disk space (This PC). If the C: drive is red/full, delete files from Downloads and empty the Recycle Bin.
4. Let pending Windows updates finish (shut down overnight).
5. If it is still slow after a restart, submit a ticket with category "Hardware Issue" — the unit may need more RAM or a disk replacement.

Prevention / Tips: Shut down your PC at the end of the day instead of leaving it on for weeks.', 1),

-- ===== 4. Software & Systems =====
(4, 1, 'Email Not Sending or Receiving', 'email-not-sending-receiving',
'Problem: Emails stay in the Outbox or expected emails never arrive.

Solution:
1. Check your internet connection (open any website).
2. Check the Outbox for a stuck oversized attachment — attachments over 25 MB usually fail; delete the stuck email and resend with a smaller file or a link.
3. Check the Junk/Spam folder for missing emails.
4. Verify your mailbox is not full — archive or delete large old emails.
5. Restart the email app.
6. Still broken? Submit a ticket with category "Software Issue" and include the exact error message.

Prevention / Tips: Department emails (it@belmont.ph, hr@belmont.ph, etc.) are shared — every member of the department receives them, so avoid sending duplicates to individuals as well.', 1),

(4, 1, 'Excel or Word File Won''t Open / Is Corrupted', 'excel-word-file-wont-open-corrupted',
'Problem: A document shows "file is corrupt and cannot be opened" or opens with garbled content.

Solution:
1. Right-click the file > Properties — if it shows "Unblock", tick it and click OK.
2. Open the app first (Excel/Word), then File > Open > browse to the file > use the arrow next to Open > "Open and Repair".
3. If the file lives on a shared drive, copy it to your Desktop first, then open the copy.
4. Check for an auto-saved version: File > Info > Version History (or Manage Workbook).
5. If none of this works, submit a ticket with the file path — IT may recover a previous version from backup.

Prevention / Tips: Never edit files directly from a USB drive; copy them locally first.', 1),

-- ===== 5. Network =====
(5, 1, 'WiFi Connected But No Internet', 'wifi-connected-no-internet',
'Problem: Your device shows WiFi is connected but pages do not load.

Solution:
1. Toggle WiFi off and on, or unplug/replug the LAN cable.
2. Restart your device.
3. Try another website — a single site being down is not a network problem.
4. Check if officemates are affected. If the whole area is down, the issue is the router/ISP — report it once per area.
5. "Forget" the WiFi network and reconnect with the password.
6. Still down? Submit a ticket with category "Network / Connectivity" and state your floor/area and how many people are affected.

Prevention / Tips: For desk PCs, a LAN cable is always more reliable than WiFi.', 1),

(5, 1, 'Cannot Access Shared Drive / Network Folder', 'cannot-access-shared-drive',
'Problem: The shared network folder does not open or asks for credentials that are rejected.

Possible Cause: Expired cached credentials, server restart, or missing folder permissions.

Solution:
1. Restart your PC — this refreshes the network session.
2. Try the full path directly in File Explorer (e.g. \\\\server\\shared).
3. If a password prompt loops, open Control Panel > Credential Manager > Windows Credentials and remove the saved entry for the server, then reconnect.
4. If you get "Access denied", you may not have permission for that folder — submit a ticket with category "Account / Access" and name the folder plus the approval from the folder owner.

Prevention / Tips: Map frequently used folders as network drives so reconnection is automatic.', 1),

-- ===== 6. POS & Retail =====
(6, 1, 'POS Terminal Frozen or Not Responding', 'pos-terminal-frozen-not-responding',
'Problem: The POS screen is stuck and cashiers cannot process sales.

Solution:
1. Wait 30 seconds — the terminal may be processing a transaction.
2. If still frozen, restart the POS terminal using the power button (hold 5 seconds, wait 10 seconds, power on).
3. After restart, verify the last transaction in the sales history before re-entering it to avoid double charging.
4. If the terminal freezes repeatedly or will not boot, submit a CRITICAL ticket with category "POS Issue" and call the Store department immediately — include the branch and terminal number.

Prevention / Tips: Do not power off the terminal during end-of-day processing.', 1),

(6, 1, 'Barcode Scanner Not Reading Items', 'barcode-scanner-not-reading',
'Problem: The scanner beeps but nothing appears, or it does not beep at all.

Solution:
1. Check the cable connection at both the scanner and the POS/PC end — unplug and replug.
2. Clean the scanner window with a soft dry cloth.
3. Test with a known-good barcode (any retail product). If that scans, the problem is the item label — reprint it.
4. If nothing scans, restart the POS terminal with the scanner connected.
5. Try the scanner on another terminal; if it fails there too, the unit is defective — submit a ticket with category "Barcode Scanner Issue" for a replacement.

Prevention / Tips: Keep scanners in their holders; dropping them is the most common cause of failure.', 1),

-- ===== 7. General FAQ =====
(7, 1, 'How Department Shared Emails Work', 'how-department-shared-emails-work',
'Problem: You are confused about personal logins versus department email addresses.

Explanation:
Each department has ONE shared email address (it@belmont.ph, accounting@belmont.ph, hr@belmont.ph, store@belmont.ph, merchandising@belmont.ph, audit@belmont.ph, support@belmont.ph). Every employee still has a personal account and logs in individually, but any notification sent to the department is delivered to every active member of that department — in the app and by email.

Tips:
1. Your Profile page shows your department''s shared inbox address.
2. You can turn email or in-app notifications on/off in Profile > Notification Preferences.
3. The Department Inbox page in the sidebar shows all tickets assigned to your department.', 1);
