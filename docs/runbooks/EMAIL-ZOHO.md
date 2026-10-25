# Email on Zoho Mail — verification, application sending, and legacy cPanel mail

**Status (v2.131.0):** repository side done; DNS and delivery **not yet verified**. This environment
can't resolve DNS over HTTPS or reach Zoho/Cloudflare, and no Zoho or Cloudflare credentials were
available. Every check below is something the owner runs, with the exact result that counts as a pass.

Email and website migration are **independent**. Nothing here depends on the Tencent move, and the
Tencent move changes no mail record.

---

## 1. What exists (from the owner)

| Item | State |
|---|---|
| MX | Pointed at Zoho |
| Mailbox | `support@iomsuite.com` (the only one that can log in) |
| Aliases | `noreply@`, `billing@`, `social@` — all on `support@` |
| SPF | Configured for Zoho |
| DKIM | Selector `zmail._domainkey` — verification **not yet confirmed** |
| DMARC | Exists, `p=none` |
| Legacy | cPanel DKIM / mail records may still exist; cPanel mail not confirmed migrated |

## 2. What the application sends, and from where

From the repository (`config/mail.php`, `config/ioms.php`):

| Email | Sent by | From | Reply-To |
|---|---|---|---|
| Account verification | `User::sendEmailVerificationNotification()` | `noreply@` | `hello` → now `support@` |
| Password reset | `App\Mail\PasswordResetLink` | `noreply@` | `support@` |
| Onboarding verification (legacy `/get-started`) | `VerifyRegistrationEmail` | `noreply@` | `hello` → now `support@` |
| Invoice issued | `InvoiceIssued` | `noreply@` | `billing@` |
| Workspace activated | `TenantActivated` | `noreply@` | `support@` |
| Subscription lifecycle notices | `SubscriptionLifecycleNotice` | `noreply@` | `billing@` |
| Support ticket replies | `SupportTicketReply` | `support@` | `support@` |

**Provider before v2.131.0:** cPanel SMTP, `mail.iomsuite.com:465`, logging in as `noreply@`. Once MX
and SPF moved to Zoho, that path is either refused (the cPanel mailbox may be gone) or delivered with
an SPF/DKIM failure. The refused case is what turned `/register` into an HTTP 500 for Duitku's
reviewers.

**Provider from v2.131.0:** Zoho SMTP. Server `.env`:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtppro.zoho.com        # confirm in Zoho Mail > Settings > Mail Accounts > SMTP
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=support@iomsuite.com
MAIL_PASSWORD=                    # Zoho APP-SPECIFIC password; server only, never git
MAIL_FROM_ADDRESS=noreply@iomsuite.com
IOMS_HELLO_EMAIL=support@iomsuite.com   # or delete the line; there is no hello@ in Zoho
```

Then `php artisan config:clear && php artisan config:cache` (or `./deploy.sh`), and:

```bash
php artisan ioms:mail-check your-own-address@example.com
```

Pass = both lines say `accepted`, and the delivered messages' headers read `spf=pass`, `dkim=pass`
(`d=iomsuite.com`) and `dmarc=pass`. `535 Authentication Failed` means the app password or username
is wrong. `553`/"sender not allowed" means `noreply@` is not an alias of `support@`.

> [!warning] Shared hosting and outbound SMTP
> Some shared hosts block outbound 465/587 to third-party SMTP. If `ioms:mail-check` times out
> connecting to `smtppro.zoho.com` on IDCloudHost, ask IDCloudHost to allow it, or try port 587 with
> `MAIL_SCHEME=smtp` (STARTTLS). On Tencent this is under your control (Lighthouse firewall, outbound).

## 3. DNS checks (Cloudflare), with what counts as a pass

Run from any machine (`dig` or `nslookup -type=TXT`):

| Check | Command | Pass |
|---|---|---|
| MX | `dig +short MX iomsuite.com` | Only Zoho hosts (`mx.zoho.com`, `mx2.zoho.com`, `mx3.zoho.com`, or your Zoho data centre's equivalents). No cPanel host |
| SPF — exactly ONE | `dig +short TXT iomsuite.com \| grep spf1` | **One** `v=spf1` record, containing `include:zoho.com` (or the regional include Zoho shows), ending `~all` or `-all` |
| DKIM | `dig +short TXT zmail._domainkey.iomsuite.com` | One `v=DKIM1; k=rsa; p=...` value **identical** to Zoho Admin → Domains → Email Authentication → DKIM, then press **Verify** there until it shows Verified |
| DMARC | `dig +short TXT _dmarc.iomsuite.com` | One `v=DMARC1; p=none; rua=mailto:...` record |
| Cloudflare proxy | Cloudflare DNS page | MX and every mail-related name are **DNS only** (grey cloud) |

Two SPF records is a **permerror**: receivers treat SPF as failed. If the cPanel-era record
(`v=spf1 +a +mx include:...idcloudhost...`) still exists beside Zoho's, merge into one. While IDCloudHost
still hosts the website, it doesn't need to send mail for the domain once the app uses Zoho SMTP, so the
merged record is just Zoho's include.

DMARC: keep `p=none` until a week of `rua` reports shows only Zoho passing, then move to `quarantine`.

## 4. Legacy cPanel records: review before deleting anything

| Record | Purpose | Action |
|---|---|---|
| `default._domainkey` (cPanel DKIM) | Signed mail sent from cPanel | Keep until the app has sent through Zoho for a week with `dkim=pass d=iomsuite.com`; then delete. Harmless while unused |
| `mail.iomsuite.com` A record | cPanel webmail / SMTP host | Keep while cPanel mail is still being exported (§5); delete after |
| `autoconfig` / `autodiscover` / `webmail` / `cpanel` | cPanel conveniences | Delete after §5 |
| Second SPF | — | Merge now (§3) |

## 5. Historical cPanel mail

Not confirmed migrated. Before deleting any cPanel mailbox:

1. cPanel → Email Accounts: list every mailbox and its disk usage. Write the list down.
2. For each one with content: Zoho Admin → Data Migration → **IMAP** source `mail.iomsuite.com:993`,
   per-mailbox credentials. The source account must still exist and `mail.iomsuite.com` must still
   resolve, which is why §4 keeps that A record.
3. Spot-check folder counts in Zoho against cPanel.
4. Download a cPanel full backup (Backup → Download a Home Directory Backup), which includes `mail/`,
   and keep it off-server.
5. Only then delete the cPanel mailboxes.

## 6. Application-level guarantees (v2.131.0, tested)

- A mail failure never fails the request that triggered it: registration, password reset and
  verification resend log the transport error (`storage/logs/laravel.log`, no credential) and tell the
  person plainly. `RegistrationSurvivesMailFailureTest` pins this.
- No password is printed by `ioms:mail-check` (`MailCheckCommandTest`).
- Every credential key in `.env.example` ships empty (`EmailIdentityTest`).
