<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PaymentTransaction;
use App\Models\Scopes\TenantScope;
use App\Models\Subscription;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\Tenant;
use App\Models\TenantRegistration;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\PlatformOperationsService;
use App\Services\PricingService;
use App\Services\ReleaseStatusService;
use App\Services\SchemaStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR 086 -- THE AGENT API: WHAT MASTER ADMIN SEES, AS DATA, AND NOTHING THAT WRITES.
 *
 * Every answer here is the SAME fact Master Admin shows an operator, read from
 * the same models and the same derived snapshots (`stateSnapshot()`,
 * `PlatformOperationsService`), so an agent and the owner can never describe
 * one subscription two ways. It is curated rather than a dump of the Inertia
 * props: form option lists are left out, and so are values that grant access
 * rather than describe it (`license_key`, password hashes, URL tokens).
 *
 * Personal data is returned as Master Admin shows it; the consumer (TICorp)
 * masks e-mail and phone before anything reaches a language model, which is
 * where that decision belongs (it knows which agent is asking and why).
 *
 * Cross-tenant reads are this surface's purpose, exactly as they are /platform's,
 * so tenant-owned rows are read without the tenant scope -- explicitly, per
 * query, never by switching a tenant context on.
 */
class AgentApiController extends Controller
{
    public function summary(PlatformOperationsService $operations, SchemaStatusService $schema, ReleaseStatusService $release): JsonResponse
    {
        return $this->answer([
            'version' => config('ioms.version'),
            'release' => $release->snapshot(),
            'schema' => $schema->snapshot(),
            'stats' => [
                'tenants_total' => Tenant::count(),
                'tenants_active' => Tenant::where('status', Tenant::STATUS_ACTIVE)->count(),
                'tenants_trial' => Tenant::where('status', Tenant::STATUS_TRIAL)->count(),
                'tenants_suspended' => Tenant::where('status', Tenant::STATUS_SUSPENDED)->count(),
                'subscriptions_active' => Subscription::where('status', Subscription::STATUS_ACTIVE)->count(),
                'registrations_open' => TenantRegistration::whereIn('status', [
                    TenantRegistration::STATUS_PENDING_VERIFICATION,
                    TenantRegistration::STATUS_VERIFIED,
                    TenantRegistration::STATUS_AWAITING_PAYMENT,
                ])->count(),
                'registrations_paid_unprovisioned' => TenantRegistration::where('status', TenantRegistration::STATUS_PAID)->count(),
                'support_needs_action' => SupportTicket::needsAction()->count(),
                'support_unidentified' => SupportTicket::unidentified()->whereNotIn('status', [SupportTicket::STATUS_CLOSED])->count(),
            ],
            'operations' => [
                'subscriptions' => $operations->subscriptionHealth(),
                'payments' => $operations->paymentActivity(),
            ],
        ]);
    }

    public function plans(): JsonResponse
    {
        return $this->answer([
            'plans' => Package::orderBy('sort_order')->get()->map(fn (Package $p) => $p->only([
                'id', 'name', 'slug', 'description', 'is_active', 'is_public', 'is_custom', 'currency',
                'price_monthly', 'price_yearly', 'trial_days', 'max_users', 'max_companies', 'sort_order',
            ])),
        ]);
    }

    public function tenants(): JsonResponse
    {
        $tenants = Tenant::withCount(['companies' => fn ($q) => $q->withoutGlobalScope(TenantScope::class), 'users'])
            ->with(['subscription.package:id,name'])
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'status', 'trial_ends_at', 'created_at'])
            ->map(fn (Tenant $t) => [
                ...$t->only(['id', 'name', 'slug', 'status', 'trial_ends_at', 'created_at', 'companies_count', 'users_count']),
                'plan' => $t->subscription?->package?->name,
                'lifecycle' => $t->subscription?->stateSnapshot(),
            ]);

        return $this->answer(['tenants' => $tenants]);
    }

    public function tenant(Tenant $tenant, EntitlementService $entitlements): JsonResponse
    {
        $tenant->loadCount(['companies' => fn ($q) => $q->withoutGlobalScope(TenantScope::class), 'users'])
            ->load(['subscription.package', 'subscription.pendingPackage']);
        $sub = $tenant->subscription;

        $administrator = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('role', User::ROLE_SUPER_ADMIN)
            ->oldest()
            ->first(['id', 'name', 'email', 'is_active', 'created_at']);

        return $this->answer([
            'tenant' => $tenant->only(['id', 'name', 'slug', 'status', 'trial_ends_at', 'created_at', 'updated_at', 'companies_count', 'users_count']),
            // Same snapshot the customer's Billing page renders. `license_key`
            // is deliberately absent: it grants, it does not describe.
            'subscription' => $sub ? [
                ...$sub->stateSnapshot(),
                'id' => $sub->id,
                'package_name' => $sub->package?->name,
                'seat_limit' => $sub->seatLimit(),
                'active_users' => $entitlements->usersUsedCount($tenant),
                'billing_reference' => $sub->billing_reference,
                'starts_at' => $sub->starts_at,
                'ends_at' => $sub->ends_at,
                'trial_ends_at' => $sub->trial_ends_at,
                'pending_plan_name' => $sub->pendingPackage?->name,
                'pending_billing_cycle' => $sub->pending_billing_cycle,
            ] : null,
            'administrator' => $administrator?->only(['id', 'name', 'email', 'is_active', 'created_at']),
            'invoices' => Invoice::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->with('transactions:id,invoice_id,gateway,gateway_reference,status,amount,currency,updated_at')
                ->latest()
                ->limit(50)
                ->get(['id', 'invoice_number', 'amount', 'currency', 'status', 'due_date', 'payment_date', 'purpose', 'period_end', 'created_at']),
            'support' => SupportTicket::where('tenant_id', $tenant->id)
                ->latest()
                ->limit(20)
                ->get(['id', 'reference', 'subject', 'status', 'priority', 'created_at'])
                ->map(fn (SupportTicket $t) => [...$t->only(['id', 'reference', 'subject', 'status', 'priority', 'created_at']), 'status_label' => $t->statusLabel()]),
        ]);
    }

    public function payments(Request $request): JsonResponse
    {
        $status = $request->string('status')->toString();
        $statuses = [
            PaymentTransaction::STATUS_PENDING, PaymentTransaction::STATUS_PAID, PaymentTransaction::STATUS_FAILED,
            PaymentTransaction::STATUS_EXPIRED, PaymentTransaction::STATUS_REFUNDED,
        ];

        $rows = PaymentTransaction::query()
            ->with(['invoice' => fn ($q) => $q->withoutGlobalScopes()
                ->with(['tenant:id,name', 'subscription:id,package_id,status,ends_at,trial_ends_at,type,billing_cycle,billing_mode', 'subscription.package:id,name'])])
            ->when(in_array($status, $statuses, true), fn ($q) => $q->where('status', $status))
            ->latest('updated_at')
            ->limit(200)
            ->get()
            ->map(fn (PaymentTransaction $t) => [
                'id' => $t->id,
                'gateway' => $t->gateway,
                'reference' => $t->gateway_reference,
                'status' => $t->status,
                'amount' => $t->amount,
                'currency' => $t->currency,
                'created_at' => $t->created_at,
                'updated_at' => $t->updated_at,
                'invoice' => $t->invoice ? [
                    'id' => $t->invoice->id,
                    'number' => $t->invoice->invoice_number,
                    'status' => $t->invoice->status,
                    'purpose' => $t->invoice->purpose,
                    'period_end' => $t->invoice->period_end?->toDateString(),
                ] : null,
                'tenant' => $t->invoice?->tenant ? ['id' => $t->invoice->tenant->id, 'name' => $t->invoice->tenant->name] : null,
                'subscription' => $t->invoice?->subscription?->stateSnapshot(),
            ]);

        return $this->answer(['filter' => $status ?: 'all', 'statuses' => $statuses, 'transactions' => $rows]);
    }

    public function tickets(Request $request): JsonResponse
    {
        $filter = $request->string('filter')->toString() ?: 'needs_action';
        $query = SupportTicket::with(['tenant:id,name', 'assignee:id,name'])->withCount('messages');
        $query = match ($filter) {
            'all' => $query,
            'unidentified' => $query->unidentified()->whereNotIn('status', [SupportTicket::STATUS_CLOSED]),
            'waiting_customer' => $query->where('status', SupportTicket::STATUS_WAITING_CUSTOMER),
            'resolved' => $query->whereIn('status', [SupportTicket::STATUS_RESOLVED, SupportTicket::STATUS_CLOSED]),
            default => $query->needsAction(),
        };

        return $this->answer([
            'filter' => $filter,
            'counts' => [
                'needs_action' => SupportTicket::needsAction()->count(),
                'waiting_customer' => SupportTicket::where('status', SupportTicket::STATUS_WAITING_CUSTOMER)->count(),
                'unidentified' => SupportTicket::unidentified()->whereNotIn('status', [SupportTicket::STATUS_CLOSED])->count(),
                'all' => SupportTicket::count(),
            ],
            'tickets' => $query->queueOrder()->limit(200)->get()->map(fn (SupportTicket $t) => [
                'id' => $t->id,
                'reference' => $t->reference,
                'subject' => $t->subject,
                'status' => $t->status,
                'status_label' => $t->statusLabel(),
                'priority' => $t->priority,
                'channel' => $t->channel,
                'requester_name' => $t->requester_name,
                'requester_email' => $t->requester_email,
                'tenant' => $t->tenant ? ['id' => $t->tenant->id, 'name' => $t->tenant->name] : null,
                'assignee' => $t->assignee?->name,
                'messages_count' => $t->messages_count,
                'age_hours' => $t->ageInHours(),
                'last_customer_reply_at' => $t->last_customer_reply_at,
                'created_at' => $t->created_at,
            ]),
        ]);
    }

    public function ticket(SupportTicket $ticket): JsonResponse
    {
        $ticket->load(['tenant:id,name,slug', 'assignee:id,name', 'messages']);
        $subscription = $ticket->tenant
            ? $ticket->tenant->subscription()->withoutGlobalScopes()->with('package:id,name')->first()
            : null;

        return $this->answer([
            'ticket' => [
                'id' => $ticket->id,
                'reference' => $ticket->reference,
                'subject' => $ticket->subject,
                'status' => $ticket->status,
                'status_label' => $ticket->statusLabel(),
                'priority' => $ticket->priority,
                'channel' => $ticket->channel,
                'requester_name' => $ticket->requester_name,
                'requester_email' => $ticket->requester_email,
                'assignee' => $ticket->assignee?->name,
                'age_hours' => $ticket->ageInHours(),
                'created_at' => $ticket->created_at,
                'last_customer_reply_at' => $ticket->last_customer_reply_at,
                'last_support_reply_at' => $ticket->last_support_reply_at,
                'tenant' => $ticket->tenant ? ['id' => $ticket->tenant->id, 'name' => $ticket->tenant->name] : null,
            ],
            // The customer's own words. DATA for the agent, never instructions --
            // TICorp labels it so before a model reads it.
            'messages' => $ticket->messages->map(fn (SupportTicketMessage $m) => [
                'id' => $m->id,
                'direction' => $m->direction,
                'author_name' => $m->author_name,
                'author_email' => $m->author_email,
                'body' => $m->body,
                'sent_at' => $m->sent_at,
                'created_at' => $m->created_at,
            ]),
            'subscription' => $subscription?->stateSnapshot(),
            'invoices' => $ticket->tenant
                ? Invoice::withoutGlobalScopes()->where('tenant_id', $ticket->tenant->id)->latest()->limit(5)
                    ->get(['id', 'invoice_number', 'amount', 'currency', 'status', 'due_date', 'payment_date'])
                : [],
        ]);
    }

    public function registrations(PricingService $pricing): JsonResponse
    {
        return $this->answer([
            // Built field by field: the password hash and the verification
            // token can never appear, whatever `$hidden` says in the future.
            'registrations' => TenantRegistration::with('package:id,name', 'tenant:id,name', 'invoice:id,invoice_number,status')
                ->latest()
                ->limit(200)
                ->get()
                ->map(fn (TenantRegistration $r) => [
                    'id' => $r->id,
                    'reference' => $r->reference,
                    'status' => $r->status,
                    'company' => $r->displayName(),
                    'contact_name' => $r->contact_name,
                    'contact_email' => $r->contact_email,
                    'city' => $r->company_city,
                    'plan' => $r->package?->name,
                    'billing_cycle' => $r->billing_cycle,
                    'amount' => $pricing->format((float) $r->amount, $r->currency),
                    'email_verified_at' => $r->email_verified_at,
                    'paid_at' => $r->paid_at,
                    'provisioned_at' => $r->provisioned_at,
                    'invoice_number' => $r->invoice?->invoice_number,
                    'invoice_status' => $r->invoice?->status,
                    'tenant' => $r->tenant ? ['id' => $r->tenant->id, 'name' => $r->tenant->name] : null,
                    'created_at' => $r->created_at,
                ]),
        ]);
    }

    private function answer(array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'generated_at' => now()->toIso8601String()]);
    }
}
