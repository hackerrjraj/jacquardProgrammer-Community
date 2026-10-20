# JPPHAF — Final Checklist (before going live)

Tick each box when it is done.

## Accounts and money
- [ ] Change the Super Admin password (the demo password `Demo@12345` must not stay).
- [ ] Enter real payment info: bKash, Nagad and bank account details (Settings).
- [ ] Check the monthly fee and other amounts are correct.

## Site content
- [ ] Contact details are real (phone, email, address — Site Settings).
- [ ] About page text is real.
- [ ] Committee / panel members are real (Dashboard → Committee Panel).
- [ ] Real hero slides uploaded; demo slides removed.
- [ ] Logo and images are final (Site Content → Images).
- [ ] Registration Terms text is final.

## Server
- [ ] Domain name points to the server and HTTPS (SSL) works.
- [ ] Production `.env` files are filled in (see `docs/DEPLOYMENT_NOTES.md`).
- [ ] Cron job (scheduler) is running.
- [ ] Queue worker is running.
- [ ] Reverb (live chat) is running.
- [ ] VAPID keys are set (push notifications).
- [ ] Daily server backup (cron) is set up and a copy is kept off the server.

## Backup
- [ ] First backup taken (`backup-now.bat` on the PC, or the server backup).
- [ ] Backup copied to a safe place (USB / cloud).

## Final test on the live server (do it once)
- [ ] Register a new test member.
- [ ] Admin approves the member.
- [ ] Member submits a payment, admin confirms it.
- [ ] Member applies for assistance and admin handles it.
- [ ] Remove the test data afterwards.
