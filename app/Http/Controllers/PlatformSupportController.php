<?php

namespace App\Http\Controllers;

use App\Mail\SupportTicketReply;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SupportTicketIntake;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * v2.80.0 -- MASTER ADMIN > SUPPORT. THE QUEUE, NOT THE MAILBOX.
 *
 * Route-gated to `role:platform_admin` like the rest of /platform. That gate
 * is the whole of the access control here, deliberately: support tickets are
 * platform-owned and NOT tenant-scoped (see the owning migration for why an
 * unidentified sender makes a scope the wrong tool), so there is no scope to
 * fall back on and a test asserts a tenant administrator is refused.
 *
 * Separate from Notifications on purpose (ADR 042): a notification is
 * something IOMS tells its operator, a ticket is something a customer is
 * waiting for, and putting the second inside the first buries it.
 *
 * WHAT IS NOT HERE: automated mail ingestion. Turning the support mailbox
 * into tickets by itself is BLOCKED on an infrastructure decision -- IMAP
 * polling, an inbound-mail webhook, or forwarding -- and inventing one would
 * produce code that looks finished and fails on first contact. Until then an
 * operator logs an incoming message, which goes through the SAME intake
 * service the future adapter will call, so no rule has to be reimplemented.
 */
class PlatformSupportController extends Controller
{
    public function index(Request $request, \App\Services\SchemaStatusService $schema): Response
    {
        /*
         * v2.84.1 -- THE QUEUE SAYS WHY IT IS EMPTY WHEN THE REASON IS THE
         * SCHEMA.
         *
         * Reproduced from a deployed environment: with the v2.80.0 migration
         * unrun, this was the ONLY page in Master Admin that returned 500 --
         * it is the only one that touches these two tables. The exception
         * was correct and completely invisible, because production does not
         * print exceptions.
         *
         * This is a NAMED CHECK, not a catch. `Schema::hasTable()` answers
         * one specific question, so a genuine bug in the support code still
         * surfaces as a bug rather than being reported as a missing
         * migration. Nothing is swallowed and nothing is faked: the page
         * renders with no tickets and states the actual reason.
         */
        $missingTables = $schema->missingTables(['support_tickets', 'support_ticket_messages']);

        if ($missingTables !== []) {
            return Inertia::render('Platform/Support/Index', [
                'tickets' => [],
                'filter' => 'needs_action',
                'counts' => ['needs_action' => 0, 'waiting_customer' => 0, 'unidentified' => 0, 'all' => 0],
                'statuses' => SupportTicket::STATUS_LABELS,
                'priorities' => SupportTicket::PRIORITY_LABELS,
                'support_mailbox' => config('ioms.emails.support'),
                'schema_missing' => $missingTables,
            ]);
        }

        $filter = $request->string('filter')->toString() ?: 'needs_action';

        $query = SupportTicket::with(['tenant:id,name', 'assignee:id,name'])
            ->withCount('messages');

        // THE DEFAULT IS THE WORK. A support queue whose default view is
        // "everything" is a mailbox with extra steps -- the question an
        // operator opens this page with is "what is unanswered".
        $query = match ($filter) {
            'all' => $query,
            'unidentified' => $query->unidentified()->whereNotIn('status', [SupportTicket::STATUS_CLOSED]),
            'waiting_customer' => $query->where('status', SupportTicket::STATUS_WAITING_CUSTOMER),
            'resolved' => $query->whereIn('status', [SupportTicket::STATUS_RESOLVED, SupportTicket::STATUS_CLOSED]),
            default => $query->needsAction(),
        };

        $tickets = $query->queueOrder()->limit(200)->get()->map(fn (SupportTicket $t) => [
            'id' => $t->id,
            'reference' => $t->reference,
            'subject' => $t->subject,
            'status' => $t->status,
            'status_label' => $t->statusLabel(),
            'priority' => $t->priority,
            'priority_label' => $t->priorityLabel(),
            'requester_email' => $t->requester_email,
            'requester_name' => $t->requester_name,
            'tenant' => $t->tenant ? ['id' => $t->tenant->id, 'name' => $t->tenant->name] : null,
            'assignee' => $t->assignee?->name,
            'messages_count' => $t->messages_count,
            // Derived, never stored, and null once the ticket owes nobody
            // anything -- an age on a closed ticket means nothing.
            'age_hours' => $t->ageInHours(),
            'last_customer_reply_at' => $t->last_customer_reply_at,
            'created_at' => $t->created_at,
        ]);

        return Inertia::render('Platform/Support/Index', [
            'tickets' => $tickets,
            'filter' => $filter,
            'counts' => [
                'needs_action' => SupportTicket::needsAction()->count(),
                'waiting_customer' => SupportTicket::where('status', SupportTicket::STATUS_WAITING_CUSTOMER)->count(),
                'unidentified' => SupportTicket::unidentified()->whereNotIn('status', [SupportTicket::STATUS_CLOSED])->count(),
                'all' => SupportTicket::count(),
            ],
            'statuses' => SupportTicket::STATUS_LABELS,
            'priorities' => SupportTicket::PRIORITY_LABELS,
            'support_mailbox' => config('ioms.emails.support'),
            'schema_missing' => [],
        ]);
    }

    public function show(SupportTicket $ticket): Response
    {
        $ticket->load(['tenant:id,name,slug', 'assignee:id,name', 'messages']);

        $subscription = $ticket->tenant
            ? $ticket->tenant->subscription()->withoutGlobalScopes()->with('package:id,name')->first()
            : null;

        return Inertia::render('Platform/Support/Show', [
            'ticket' => [
                'id' => $ticket->id,
                'reference' => $ticket->reference,
                'subject' => $ticket->subject,
                'status' => $ticket->status,
                'status_label' => $ticket->statusLabel(),
                'priority' => $ticket->priority,
                'channel' => $ticket->channel,
                'requester_email' => $ticket->requester_email,
                'requester_name' => $ticket->requester_name,
                'assigned_to' => $ticket->assigned_to,
                'assignee' => $ticket->assignee?->name,
                'age_hours' => $ticket->ageInHours(),
                'created_at' => $ticket->created_at,
                'last_customer_reply_at' => $ticket->last_customer_reply_at,
                'last_support_reply_at' => $ticket->last_support_reply_at,
                'tenant' => $ticket->tenant ? [
                    'id' => $ticket->tenant->id,
                    'name' => $ticket->tenant->name,
                ] : null,
            ],
            'messages' => $ticket->messages->map(fn (SupportTicketMessage $m) => [
                'id' => $m->id,
                'direction' => $m->direction,
                'author_name' => $m->author_name,
                'author_email' => $m->author_email,
                'body' => $m->body,
                'sent_at' => $m->sent_at,
                'created_at' => $m->created_at,
            ]),
            /*
             * COMMERCIAL CONTEXT, WHERE IT IS RELEVANT. An operator answering
             * "why can I not save anything?" needs to know the tenant is
             * read-only, and needs it beside the question rather than in
             * another tab. It is the SAME shared snapshot the customer's own
             * Billing page renders (v2.80.0), so support cannot quote a state
             * the customer does not see. Absent entirely for an unidentified
             * sender, because there is nothing honest to show.
             */
            'subscription' => $subscription?->stateSnapshot(),
            'invoices' => $ticket->tenant
                ? Invoice::withoutGlobalScopes()
                    ->where('tenant_id', $ticket->tenant->id)
                    ->latest()
                    ->limit(5)
                    ->get(['id', 'invoice_number', 'amount', 'currency', 'status', 'due_date', 'payment_date'])
                : [],
            'statuses' => SupportTicket::STATUS_LABELS,
            'priorities' => SupportTicket::PRIORITY_LABELS,
            'operators' => User::withoutGlobalScopes()
                ->where('role', User::ROLE_PLATFORM_ADMIN)
                ->orderBy('name')
                ->get(['id', 'name']),
            // Offered only when the sender could not be placed -- the operator
            // picks the organization by hand.
            'tenant_options' => $ticket->isIdentified()
                ? []
                : Tenant::withoutGlobalScopes()->orderBy('name')->get(['id', 'name']),
            'support_mailbox' => config('ioms.emails.support'),
        ]);
    }

    /**
     * Log a message a customer sent. The manual half of intake while
     * automated ingestion is blocked, and the same service the adapter will
     * use -- so the status rules cannot drift between the two.
     */
    public function store(Request $request, SupportTicketIntake $intake): RedirectResponse
    {
        $validated = $request->validate([
            'requester_email' => ['required', 'email', 'max:255'],
            'requester_name' => ['nullable', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'channel' => ['nullable', Rule::in([SupportTicket::CHANNEL_EMAIL, SupportTicket::CHANNEL_MANUAL])],
        ]);

        $ticket = $intake->record(
            email: $validated['requester_email'],
            subject: $validated['subject'],
            body: $validated['body'],
            name: $validated['requester_name'] ?? null,
            channel: $validated['channel'] ?? SupportTicket::CHANNEL_EMAIL,
        );

        return redirect()
            ->route('platform.support.show', $ticket->id)
            ->with('success', "Tiket {$ticket->reference} dicatat.");
    }

    /**
     * Answer the customer. The reply is recorded FIRST and emailed second, so
     * a mail failure loses the message from the customer's inbox but never
     * from the ticket -- and `sent_at` stays null, which the conversation
     * renders as "belum terkirim" rather than pretending it went out.
     */
    public function reply(Request $request, SupportTicket $ticket, SupportTicketIntake $intake): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
        ]);

        $author = $request->user();
        $message = $ticket->recordSupportReply($validated['body'], $author);

        try {
            Mail::to($ticket->requester_email)->send(
                new SupportTicketReply($ticket->fresh(['tenant']), $validated['body'], $author->name)
            );
            $intake->markSent($message);
        } catch (Throwable $e) {
            Log::warning('Support reply could not be emailed.', [
                'ticket' => $ticket->reference,
                'error' => $e->getMessage(),
            ]);

            return back()->with('warning', "Balasan tersimpan pada {$ticket->reference}, tetapi email gagal dikirim.");
        }

        return back()->with('success', "Balasan terkirim ke {$ticket->requester_email}.");
    }

    /** Status, priority and ownership -- the three things an operator changes by hand. */
    public function update(Request $request, SupportTicket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'required', Rule::in(SupportTicket::STATUSES)],
            'priority' => ['sometimes', 'required', Rule::in(SupportTicket::PRIORITIES)],
            'assigned_to' => ['sometimes', 'nullable', 'exists:users,id'],
        ]);

        if (array_key_exists('assigned_to', $validated)) {
            // Only a platform operator can own a queue item. Without this an
            // exists:users,id check would happily assign a customer's own
            // administrator to an internal ticket.
            $assignee = $validated['assigned_to']
                ? User::withoutGlobalScopes()->find($validated['assigned_to'])
                : null;

            abort_if($validated['assigned_to'] && ! $assignee?->isPlatformAdmin(), 422);

            $ticket->forceFill(['assigned_to' => $assignee?->id])->save();
        }

        if (array_key_exists('priority', $validated)) {
            $ticket->forceFill(['priority' => $validated['priority']])->save();
        }

        // Last, and through the model, so the terminal timestamps stay
        // consistent with whatever the status ends up being.
        if (array_key_exists('status', $validated)) {
            $ticket->moveTo($validated['status']);
        }

        return back()->with('success', 'Tiket diperbarui.');
    }

    /**
     * Associate an unidentified sender with an organization -- by hand, or by
     * re-running identification for a customer whose account now exists.
     */
    public function associate(Request $request, SupportTicket $ticket, SupportTicketIntake $intake): RedirectResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['nullable', 'exists:tenants,id'],
        ]);

        if (! empty($validated['tenant_id'])) {
            $tenant = Tenant::withoutGlobalScopes()->findOrFail($validated['tenant_id']);
            $ticket->forceFill(['tenant_id' => $tenant->id])->save();

            ActivityLog::record('updated', "Support ticket {$ticket->reference} associated with tenant \"{$tenant->name}\".");

            return back()->with('success', "Tiket dikaitkan dengan {$tenant->name}.");
        }

        $tenant = $intake->reidentify($ticket);

        return back()->with(
            $tenant ? 'success' : 'warning',
            $tenant
                ? "Pengirim dikenali sebagai {$tenant->name}."
                : 'Pengirim masih belum dapat dikenali dari alamat emailnya.'
        );
    }
}
