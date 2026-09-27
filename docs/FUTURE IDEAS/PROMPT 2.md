# IOMS — FUTURE PROMPT 02
## iPaymu Payment Integration + Existing Tenant → Paid Subscription Migration

> STATUS: FUTURE
> PROJECT: IOMS
>
> IMPORTANT:
> This milestone must be executed only after auditing the current repository.
>
> The two core objectives are:
>
> 1. Integrate iPaymu as an additional payment provider.
> 2. Ensure an existing tenant created before payment gateway availability can later become a paid tenant WITHOUT recreating the tenant or migrating its operational data into a new tenant.
>
> DO NOT REMOVE MIDTRANS.
>
> DO NOT TOUCH CPANEL.

---

# 1. AUDIT CURRENT ARCHITECTURE FIRST

Before implementation, inspect the actual current repository.

Audit:

- Tenant/Organization model.
- Subscription model/service.
- Plan and entitlement system.
- Invoice system.
- Payment model/service.
- Payment provider abstraction.
- Midtrans implementation.
- Webhook processing.
- Payment status handling.
- Subscription state machine.
- Master Admin billing screens.
- Tenant creation flow.
- Existing billing modes/statuses.
- Existing tests.

Do not assume the architecture described in this prompt still exactly matches the repository.

Reuse existing architecture.

Do not duplicate existing concepts.

---

# 2. EXISTING TENANT MUST NOT REQUIRE RECREATION

This is a critical product requirement.

An IOMS tenant may be created before a production payment gateway is available.

Example:

Tenant:

PT Galangan Aliran Jaya

Current:

Billing = Complimentary / Internal Use
Subscription = Active
Payment Gateway = Not yet available

Later:

iPaymu becomes production-ready
→ Existing tenant
→ Select/activate paid subscription
→ Payment through iPaymu
→ Subscription becomes paid

The tenant must remain the SAME tenant.

DO NOT require:

Old Tenant
→ Delete
→ Create New Tenant
→ Migrate Data

---

# 3. DATA THAT MUST REMAIN INTACT

When converting an existing complimentary/manual tenant to paid, preserve:

- Tenant ID.
- Organization.
- Users.
- User roles.
- Operating Units.
- Departments.
- Employees.
- Projects.
- HSE records.
- PTW.
- Incidents.
- Checklists.
- CAPA.
- Documents.
- Audit history.
- Existing subscription history.
- Existing tenant configuration.
- Existing branding.
- All legitimate operational data.

No destructive migration should be required.

---

# 4. BILLING MODEL

Ensure billing architecture can distinguish appropriate states such as:

- COMPLIMENTARY / INTERNAL
- MANUAL
- PAID

Use the existing architecture and naming conventions where possible.

Payment provider must NOT define tenant identity.

Conceptually:

TENANT
↓
SUBSCRIPTION
↓
PLAN
↓
BILLING STATE
↓
PAYMENT PROVIDER

Not:

TENANT
↓
PAYMENT GATEWAY
↓
TENANT

The tenant must exist independently from payment provider availability.

---

# 5. EXISTING TENANT → PAID FLOW

Intended flow:

Existing Tenant
↓
Complimentary / Manual
↓
Customer selects paid plan
↓
Invoice / Payment Order
↓
iPaymu Checkout
↓
Customer pays
↓
iPaymu callback/webhook
↓
Signature verification
↓
Payment verified
↓
Invoice/payment marked successful
↓
Subscription becomes paid/active
↓
Entitlements applied
↓
Same tenant continues operating

No tenant recreation.

No duplicate organization.

No duplicate users.

No operational data migration.

---

# 6. IPAYMU PROVIDER IMPLEMENTATION

Implement iPaymu through the existing provider abstraction.

Architecture:

IOMS Payment Service
↓
Payment Provider Interface
↙ ↘
Midtrans  iPaymu

DO NOT remove Midtrans.

DO NOT rewrite working Midtrans functionality unless necessary to make the provider abstraction genuinely provider-agnostic.

Do not hard-code iPaymu-specific behavior throughout the subscription domain.

Provider-specific behavior should remain inside the iPaymu integration boundary.

---

# 7. IPAYMU SANDBOX

Implement and verify sandbox integration first.

Use current official iPaymu documentation as the source of truth for current API requirements.

Support the appropriate:

- API credentials.
- Merchant configuration.
- Environment separation.
- Authentication/signature.
- Checkout/payment creation.
- Callback/webhook.
- Payment status verification.
- Error handling.
- Idempotency.

Do not invent undocumented iPaymu behavior.

If official iPaymu documentation is required, use current official documentation.

---

# 8. PAYMENT VERIFICATION

Never activate paid subscription solely because browser was redirected to a success page.

Authoritative transition must come from verified server-side payment status/event.

Conceptually:

Customer Browser
↓
Checkout
↓
Payment Provider
↓
Server-side verification
↓
Verified Payment
↓
Subscription Activation

Client-side success state must never grant paid entitlement by itself.

---

# 9. WEBHOOK / CALLBACK SECURITY

Implement/verify:

- Signature verification.
- Authentication.
- Idempotency.
- Duplicate event handling.
- Invalid event rejection.
- Unknown transaction handling.
- Tenant/payment mapping validation.
- Amount/currency validation where applicable.
- Invoice/order matching.
- Audit logging.

Duplicated payment notifications must never:

- extend subscription twice;
- create duplicate invoice;
- activate duplicate subscription.

---

# 10. PAYMENT → SUBSCRIPTION → ENTITLEMENT

After verified payment:

Payment
↓
Invoice
↓
Subscription
↓
Plan
↓
Entitlement
↓
Tenant Access

Ensure payment event does not bypass existing subscription business rules.

Preserve:

- billing cycle;
- price snapshot;
- renewal date rules;
- upgrade/downgrade rules;
- existing 7-day grace period;
- entitlement limits.

---

# 11. EXISTING TENANT PAYMENT TEST

Create a realistic test scenario.

Example:

Tenant ID: X
Organization: Existing Organization
Users: Existing Users
Operational data: Existing Data

Billing:
Complimentary

Then:

X
↓
Select paid plan
↓
iPaymu sandbox payment
↓
Verified callback
↓
Paid subscription

Assert:

- Tenant ID = X.
- Organization unchanged.
- Users unchanged.
- Operating Units unchanged.
- Operational data unchanged.
- Subscription = paid.
- Correct plan.
- Correct entitlement.
- Payment recorded.
- Invoice recorded.
- Audit trail recorded.

This test is mandatory.

---

# 12. RENEWAL

iPaymu must work with the existing renewal lifecycle.

Before expiry:

Current:
1 Sep → 30 Sep

Renewal payment:
25 Sep

New period:
1 Oct → 30 Oct

Never:

25 Sep → 25 Oct

After expiry:

Respect:

Expiry
→ 7-day full-access grace
→ Read-only

Verified renewal must restore the appropriate active state.

---

# 13. MASTER ADMIN INTEGRATION

Master Admin must be able to see:

- Payment provider.
- Payment status.
- Invoice.
- Subscription.
- Tenant.
- Payment date.
- Amount.
- Billing cycle.
- Relevant gateway event.
- Failed/pending payment.
- Successful payment.
- Renewal.

Payment events should generate appropriate internal notifications.

Master Admin must remain provider-agnostic.

It should support:

- Midtrans.
- iPaymu.
- Future providers.

---

# 14. CUSTOMER CHECKOUT

Review existing IOMS checkout architecture.

Expected customer flow:

Pricing
↓
Plan selection
↓
Billing cycle
↓
Checkout
↓
Payment provider
↓
Payment
↓
Verified result
↓
Subscription active

Use the existing IOMS design system.

Do not create an isolated payment UI that feels unrelated to IOMS.

---

# 15. ERROR / FAILURE STATES

Handle appropriately:

- Payment pending.
- Payment failed.
- Payment cancelled.
- Expired payment.
- Invalid callback.
- Duplicate callback.
- Gateway unavailable.
- Invalid credentials.
- Amount mismatch.
- Invoice mismatch.
- Unknown transaction.
- Customer abandons checkout.

Never activate paid access for unsuccessful/unverified payments.

---

# 16. SECURITY

Preserve:

- tenant isolation;
- payment ownership;
- subscription ownership;
- Master Admin authorization;
- server-side entitlement enforcement;
- webhook verification;
- idempotency;
- audit trail;
- no cross-tenant payment access.

A tenant must never inspect another tenant's payment/subscription data.

---

# 17. TESTING

## iPaymu

Test:

- Configuration.
- Sandbox payment creation.
- Successful payment.
- Failed payment.
- Pending payment.
- Cancelled payment.
- Invalid signature.
- Duplicate callback.
- Unknown transaction.

## Subscription

Test:

- Complimentary → Paid.
- Existing tenant remains the same tenant.
- Existing users remain.
- Existing operational data remains.
- Entitlements update correctly.
- Renewal before expiry.
- Renewal after expiry.
- 7-day grace.
- Read-only after grace.
- Renewal restores access.

## Midtrans Regression

Verify existing Midtrans behavior still passes.

DO NOT break the existing provider.

## Master Admin

Verify:

- Tenant.
- Subscription.
- Invoice.
- Payment.
- Provider.
- Gateway events.
- Notifications.

## Security

Run tenant isolation and authorization regression tests.

## Browser

Verify approximately:

- 375px.
- 768px.
- 1280px.

---

# 18. DOCUMENTATION

After implementation update relevant:

- Obsidian/KB.
- ADR.
- Payment architecture documentation.
- Subscription lifecycle documentation.
- Tenant billing migration documentation.
- iPaymu integration documentation.

Document any external boundary that could not be tested.

Never claim production verification if only sandbox was tested.

---

# 19. PRODUCTION READINESS

Before production, verify current official iPaymu requirements for:

- Production credentials.
- Domain.
- Static/server IP requirements.
- Callback/webhook configuration.
- Merchant verification.
- Production environment.
- Required account settings.

Do not hard-code credentials.

Use environment configuration.

Never commit secrets.

---

# 20. FINAL ARCHITECTURE

The intended architecture:

IOMS
↓
Payment Service
↓
Payment Provider
↙ ↘
Midtrans  iPaymu
↘ ↙
Verified Payment
↓
Invoice
↓
Subscription
↓
Entitlement
↓
Tenant

Existing tenant migration:

Existing Tenant
↓
Complimentary / Internal
↓
Payment Gateway becomes available
↓
iPaymu Payment
↓
Verified
↓
Paid Subscription
↓
SAME TENANT
↓
SAME DATA
↓
SAME USERS
↓
SAME ORGANIZATION

No tenant recreation.

No duplicate organization.

No destructive migration.

---

# 21. IMPLEMENTATION ORDER

Claude should execute this milestone in order:

1. Read this Future document.
2. Inspect current repository.
3. Identify what is already implemented.
4. Audit existing tenant billing architecture.
5. Verify complimentary/manual → paid migration path.
6. Implement iPaymu through existing payment abstraction.
7. Keep Midtrans working.
8. Test iPaymu Sandbox.
9. Test existing tenant → paid.
10. Test subscription lifecycle.
11. Test Master Admin payment visibility.
12. Run security regression.
13. Update Obsidian/KB/ADR.
14. Run complete relevant test suite.
15. Build and browser verify.
16. Commit.
17. Push to GitHub main.
18. Do NOT touch cPanel.

---

# DONE CRITERIA

Prompt #2 is complete only when:

- iPaymu provider is implemented.
- Midtrans still works.
- iPaymu sandbox flow works.
- Server-side payment verification works.
- Webhook/callback security works.
- Duplicate events are safe.
- Existing tenant can become paid without recreation.
- Existing tenant data remains intact.
- Subscription/entitlement updates correctly.
- Renewal works.
- Master Admin sees iPaymu payment activity.
- Security regression passes.
- Documentation updated.
- Git commit/push completed.