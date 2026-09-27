<?php

namespace Tests\Feature;

use App\Mail\SupportTicketReply;
use App\Models\Company;
use App\Models\Notification;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SupportTicketIntake;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.80.0 -- THE SUPPORT QUEUE IS A STATE MACHINE, NOT A MAILBOX.
 *
 * What is asserted here is the difference between the two: that the default
 * view is the WORK rather than everything, that the two automatic transitions
 * happen (a customer message reopens, a support reply hands the ball back),
 * that an unrecognised sender is a supported state rather than a dropped
 * message, that a ticket's age measures how long WE have kept somebody
 * waiting, and that none of this leaks across a tenant boundary or into the
 * operator's notification feed.
 */
class SupportTicketQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;
    private Tenant $tenant;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $package = Package::create([
            'name' => 'Business', 'slug' => 'business-'.uniqid(), 'is_active' => true, 'is_public' => true,
            'price_monthly' => 1499000, 'price_yearly' => 14990000, 'currency' => 'IDR',
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Yard Works', 'slug' => 'yard-works-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);
        Company::withoutGlobalScopes()->create([
            'name' => 'Yard Works Co', 'code' => 'YWC', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);
        Subscription::create([
            'tenant_id' => $this->tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);

        app(CurrentTenant::class)->set($this->tenant);

        $this->customer = User::create([
            'name' => 'Sari', 'email' => 'sari@yardworks.test', 'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_SUPER_ADMIN, 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        $this->operator = User::withoutGlobalScopes()->create([
            'name' => 'Ops', 'email' => 'ops-support@ioms.test', 'password' => bcrypt('secret-pass-2'),
            'role' => User::ROLE_PLATFORM_ADMIN, 'tenant_id' => null, 'is_active' => true,
        ]);
    }

    private function asOperator(): self
    {
        Auth::forgetGuards();
        $this->flushSession();

        return $this->actingAs($this->operator->fresh());
    }

    private function intake(): SupportTicketIntake
    {
        return app(SupportTicketIntake::class);
    }

    /* ==================================================================
     * Identification
     * ================================================================== */

    public function test_a_known_address_is_attached_to_its_organization(): void
    {
        $ticket = $this->intake()->record(
            email: 'SARI@yardworks.test',   // case must not matter
            subject: 'Tidak bisa cetak permit',
            body: 'Tombol cetak tidak muncul.',
        );

        $this->assertSame($this->tenant->id, $ticket->tenant_id);
        $this->assertTrue($ticket->isIdentified());
        $this->assertSame('sari@yardworks.test', $ticket->requester_email);
    }

    public function test_an_unknown_address_becomes_an_unidentified_queue_item(): void
    {
        // Not a dropped message and not a guess: a supported state that a
        // human resolves.
        $ticket = $this->intake()->record(
            email: 'someone@unknown.test',
            subject: 'Minta demo',
            body: 'Apakah ada versi percobaan?',
        );

        $this->assertNull($ticket->tenant_id);
        $this->assertFalse($ticket->isIdentified());
        $this->assertSame(1, SupportTicket::unidentified()->count());
    }

    public function test_identification_is_never_inferred_from_a_shared_mail_domain(): void
    {
        // Two customers can use the same provider. Attaching a ticket by
        // domain would show one customer's commercial context beside
        // another's question.
        $ticket = $this->intake()->record(
            email: 'other.person@yardworks.test',
            subject: 'Pertanyaan',
            body: 'Halo.',
        );

        $this->assertNull($ticket->tenant_id);
    }

    public function test_an_unidentified_sender_can_be_associated_by_hand(): void
    {
        $ticket = $this->intake()->record(email: 'walkin@unknown.test', subject: 'Telepon', body: 'Ditelepon.');

        $this->asOperator()
            ->put(route('platform.support.associate', $ticket->id), ['tenant_id' => $this->tenant->id])
            ->assertRedirect();

        $this->assertSame($this->tenant->id, $ticket->fresh()->tenant_id);
    }

    public function test_reidentification_finds_an_account_created_after_the_message(): void
    {
        $ticket = $this->intake()->record(email: 'later@yardworks.test', subject: 'Akun baru', body: 'Belum bisa masuk.');
        $this->assertNull($ticket->tenant_id);

        User::withoutGlobalScopes()->create([
            'name' => 'Later', 'email' => 'later@yardworks.test', 'password' => bcrypt('secret-pass-3'),
            'role' => User::ROLE_HSE, 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        $this->asOperator()
            ->put(route('platform.support.associate', $ticket->id), ['tenant_id' => null])
            ->assertRedirect();

        $this->assertSame($this->tenant->id, $ticket->fresh()->tenant_id);
    }

    /* ==================================================================
     * The two automatic transitions
     * ================================================================== */

    public function test_a_support_reply_hands_the_ball_to_the_customer_and_is_emailed(): void
    {
        $ticket = $this->intake()->record(email: 'sari@yardworks.test', subject: 'Permit', body: 'Mohon bantuan.');

        $this->asOperator()
            ->post(route('platform.support.reply', $ticket->id), ['body' => "Sudah kami perbaiki.\nTerima kasih."])
            ->assertRedirect();

        $ticket->refresh();

        $this->assertSame(SupportTicket::STATUS_WAITING_CUSTOMER, $ticket->status);
        $this->assertNotNull($ticket->last_support_reply_at);

        $reply = $ticket->messages()->where('direction', SupportTicketMessage::DIRECTION_OUTBOUND)->sole();
        $this->assertNotNull($reply->sent_at, 'A sent reply must be recorded as sent.');

        // From the support mailbox, not noreply: a support answer exists to
        // be replied to.
        Mail::assertSent(SupportTicketReply::class, function ($mail) use ($ticket) {
            return $mail->hasTo($ticket->requester_email)
                && $mail->hasFrom(config('ioms.emails.support'))
                && str_contains($mail->envelope()->subject, $ticket->reference);
        });
    }

    public function test_a_customer_message_reopens_a_ticket_we_thought_was_finished(): void
    {
        $ticket = $this->intake()->record(email: 'sari@yardworks.test', subject: 'Permit', body: 'Mohon bantuan.');
        $ticket->moveTo(SupportTicket::STATUS_RESOLVED);
        $this->assertNotNull($ticket->fresh()->resolved_at);

        // A customer writing again is the clearest possible statement that it
        // is not finished -- and it must keep the history of the first ticket
        // rather than starting a second one.
        $reopened = $this->intake()->record(
            email: 'sari@yardworks.test',
            subject: 'Permit',
            body: 'Masih belum jalan.',
        );

        $this->assertSame($ticket->id, $reopened->id, 'A reply must thread onto the same ticket.');
        $this->assertSame(SupportTicket::STATUS_OPEN, $reopened->status);
        $this->assertNull($reopened->resolved_at);
        $this->assertCount(2, $reopened->messages);
    }

    public function test_a_closed_conversation_starts_a_new_ticket(): void
    {
        $first = $this->intake()->record(email: 'sari@yardworks.test', subject: 'Permit', body: 'Satu.');
        $first->moveTo(SupportTicket::STATUS_CLOSED);

        $second = $this->intake()->record(email: 'sari@yardworks.test', subject: 'Hal lain', body: 'Dua.');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(SupportTicket::STATUS_CLOSED, $first->fresh()->status);
    }

    /* ==================================================================
     * The queue
     * ================================================================== */

    public function test_the_default_queue_is_the_work_and_excludes_tickets_waiting_on_the_customer(): void
    {
        $open = $this->intake()->record(email: 'sari@yardworks.test', subject: 'Terbuka', body: 'A');

        $waiting = $this->intake()->record(email: 'b@unknown.test', subject: 'Menunggu pelanggan', body: 'B');
        $waiting->recordSupportReply('Sudah dijawab.', $this->operator);

        $closed = $this->intake()->record(email: 'c@unknown.test', subject: 'Ditutup', body: 'C');
        $closed->moveTo(SupportTicket::STATUS_CLOSED);

        $props = $this->asOperator()
            ->get(route('platform.support'))
            ->assertOk()
            ->viewData('page')['props'];

        $references = collect($props['tickets'])->pluck('reference')->all();

        $this->assertContains($open->reference, $references);
        $this->assertNotContains($waiting->reference, $references, 'A ticket waiting on the customer is not our work.');
        $this->assertNotContains($closed->reference, $references);
        $this->assertSame('needs_action', $props['filter']);
        $this->assertSame(1, $props['counts']['needs_action']);
        $this->assertSame(1, $props['counts']['waiting_customer']);
    }

    public function test_the_queue_puts_whoever_has_waited_longest_first(): void
    {
        $old = $this->intake()->record(email: 'old@unknown.test', subject: 'Lama', body: 'A');
        $old->forceFill(['last_customer_reply_at' => now()->subDays(4)])->save();

        $new = $this->intake()->record(email: 'new@unknown.test', subject: 'Baru', body: 'B');
        $new->forceFill([
            'last_customer_reply_at' => now()->subMinutes(5),
            'priority' => SupportTicket::PRIORITY_URGENT,
        ])->save();

        $props = $this->asOperator()->get(route('platform.support'))->viewData('page')['props'];

        // Deliberately not priority-first: an urgent ticket raised a minute
        // ago must not push a normal one ignored for four days down the list.
        $this->assertSame($old->reference, $props['tickets'][0]['reference']);
    }

    public function test_ticket_age_measures_how_long_we_have_kept_them_waiting(): void
    {
        $ticket = $this->intake()->record(email: 'sari@yardworks.test', subject: 'Umur', body: 'A');
        $ticket->forceFill(['last_customer_reply_at' => now()->subHours(30)])->save();

        $this->assertSame(30, $ticket->fresh()->ageInHours());

        // Answering it stops the clock, because the wait is no longer ours.
        $ticket->fresh()->recordSupportReply('Dijawab.', $this->operator);
        $this->assertNull($ticket->fresh()->ageInHours());
    }

    /* ==================================================================
     * Context, separation and access
     * ================================================================== */

    public function test_the_ticket_carries_the_same_subscription_state_the_customer_sees(): void
    {
        // The reason this is a console page and not a mail client: an
        // operator answering "why can I not save anything?" must see the same
        // answer the customer's Billing page gives.
        Subscription::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->update(['ends_at' => now()->subDays(20)]);

        $ticket = $this->intake()->record(email: 'sari@yardworks.test', subject: 'Tidak bisa simpan', body: 'Kenapa?');

        $props = $this->asOperator()
            ->get(route('platform.support.show', $ticket->id))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame(Subscription::LIFECYCLE_LAPSED, $props['subscription']['lifecycle_state']);
        $this->assertFalse($props['subscription']['allows_writes']);
    }

    public function test_support_tickets_never_reach_the_operator_notification_feed(): void
    {
        // Separate surfaces on purpose (ADR 042): putting a customer's
        // question inside the platform's own event feed buries it.
        $ticket = $this->intake()->record(email: 'sari@yardworks.test', subject: 'Terpisah', body: 'A');
        $ticket->recordSupportReply('Dijawab.', $this->operator);

        $this->assertSame(
            0,
            Notification::withoutGlobalScopes()->where('user_id', $this->operator->id)->count()
        );
    }

    public function test_a_tenant_administrator_cannot_reach_the_support_console(): void
    {
        $ticket = $this->intake()->record(email: 'sari@yardworks.test', subject: 'Privat', body: 'A');

        Auth::forgetGuards();
        $this->flushSession();

        $this->actingAs($this->customer->fresh())
            ->get(route('platform.support'))
            ->assertForbidden();

        Auth::forgetGuards();
        $this->flushSession();

        // Not even their own ticket: this queue is the operator's, and a
        // customer reading it would see other customers' conversations.
        $this->actingAs($this->customer->fresh())
            ->get(route('platform.support.show', $ticket->id))
            ->assertForbidden();
    }

    public function test_only_a_platform_operator_can_own_a_ticket(): void
    {
        $ticket = $this->intake()->record(email: 'sari@yardworks.test', subject: 'Assign', body: 'A');

        $this->asOperator()
            ->put(route('platform.support.update', $ticket->id), ['assigned_to' => $this->customer->id])
            ->assertStatus(422);

        $this->assertNull($ticket->fresh()->assigned_to);

        $this->asOperator()
            ->put(route('platform.support.update', $ticket->id), ['assigned_to' => $this->operator->id])
            ->assertRedirect();

        $this->assertSame($this->operator->id, $ticket->fresh()->assigned_to);
    }

    public function test_an_unsent_reply_stays_visibly_unsent(): void
    {
        // A mail failure must lose the message from the customer's inbox, not
        // from the ticket -- and must never look like an answer that landed.
        $ticket = $this->intake()->record(email: 'sari@yardworks.test', subject: 'Gagal kirim', body: 'A');

        $message = $ticket->recordSupportReply('Belum terkirim.', $this->operator);

        $this->assertNull($message->sent_at);
        $this->assertSame(SupportTicket::STATUS_WAITING_CUSTOMER, $ticket->fresh()->status);
    }
}
