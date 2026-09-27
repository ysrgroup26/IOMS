<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * v2.80.0 -- A CUSTOMER QUESTION AS A PIECE OF WORK.
 *
 * Read the owning migration first: it records why this table is
 * platform-owned rather than tenant-scoped, and why that is safe.
 *
 * The status rules live here rather than in a controller, because they are
 * the model's own behaviour and there will eventually be more than one way
 * a message arrives (an operator logging one today, mail ingestion later --
 * see docs/kb/backlog/Support Inbox.md). A rule that lives in one
 * controller is a rule the second entry point forgets.
 */
class SupportTicket extends Model
{
    /** Somebody is waiting for us. The default, and what a customer reply returns a ticket to. */
    public const STATUS_OPEN = 'open';

    /** We picked it up. Still ours. */
    public const STATUS_IN_PROGRESS = 'in_progress';

    /** We answered; the ball is theirs. Set automatically when support replies. */
    public const STATUS_WAITING_CUSTOMER = 'waiting_customer';

    /** Answered and believed done, but not yet filed away. */
    public const STATUS_RESOLVED = 'resolved';

    /** Finished. Not worked, not counted, still readable. */
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_WAITING_CUSTOMER,
        self::STATUS_RESOLVED, self::STATUS_CLOSED,
    ];

    /** Indonesian -- the only surface that reads these is Master Admin (ADR 040). */
    public const STATUS_LABELS = [
        self::STATUS_OPEN => 'Perlu dijawab',
        self::STATUS_IN_PROGRESS => 'Sedang ditangani',
        self::STATUS_WAITING_CUSTOMER => 'Menunggu pelanggan',
        self::STATUS_RESOLVED => 'Selesai',
        self::STATUS_CLOSED => 'Ditutup',
    ];

    /**
     * THE STATES THAT OWE SOMEBODY AN ANSWER. This is the definition the
     * default queue is built on: waiting_customer is deliberately absent,
     * because a ticket waiting on the customer is not our work.
     */
    public const NEEDS_ACTION = [self::STATUS_OPEN, self::STATUS_IN_PROGRESS];

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    public const PRIORITIES = [
        self::PRIORITY_LOW, self::PRIORITY_NORMAL, self::PRIORITY_HIGH, self::PRIORITY_URGENT,
    ];

    public const PRIORITY_LABELS = [
        self::PRIORITY_LOW => 'Rendah',
        self::PRIORITY_NORMAL => 'Normal',
        self::PRIORITY_HIGH => 'Tinggi',
        self::PRIORITY_URGENT => 'Mendesak',
    ];

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_MANUAL = 'manual';

    protected $fillable = [
        'reference', 'tenant_id', 'requester_email', 'requester_name', 'subject',
        'status', 'priority', 'assigned_to', 'channel',
        'last_customer_reply_at', 'last_support_reply_at', 'resolved_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_customer_reply_at' => 'datetime',
            'last_support_reply_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /* ==================================================================
     * Relationships
     * ================================================================== */

    public function messages()
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('created_at');
    }

    /** Null when the sender has not been recognised yet. That is a queue item, not an error. */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /* ==================================================================
     * Queues
     * ================================================================== */

    /** Everything that owes somebody an answer. */
    public function scopeNeedsAction(Builder $query): Builder
    {
        return $query->whereIn('status', self::NEEDS_ACTION);
    }

    /**
     * The queue order: whoever has been waiting longest for a reply comes
     * first, then priority. Deliberately NOT priority-first -- an urgent
     * ticket raised a minute ago should not push a normal one that has been
     * ignored for three days further down.
     */
    public function scopeQueueOrder(Builder $query): Builder
    {
        return $query
            ->orderByRaw('COALESCE(last_customer_reply_at, created_at) ASC')
            ->orderBy('id');
    }

    /** Senders IOMS could not place. They need a human to associate them. */
    public function scopeUnidentified(Builder $query): Builder
    {
        return $query->whereNull('tenant_id');
    }

    /* ==================================================================
     * Derived facts -- never stored
     * ================================================================== */

    /**
     * How long this ticket has been waiting on US, in whole hours.
     *
     * Measured from the last CUSTOMER message (or its creation, for a ticket
     * that has none yet), not from creation, because a three-week
     * conversation answered an hour ago is not three weeks old as work. Null
     * once the ticket no longer owes anybody anything -- an age on a closed
     * ticket is a number that means nothing, the same rule material request
     * ageing already follows.
     */
    public function ageInHours(): ?int
    {
        if (! in_array($this->status, self::NEEDS_ACTION, true)) {
            return null;
        }

        $since = $this->last_customer_reply_at ?? $this->created_at;

        return $since ? (int) $since->diffInHours(now()) : null;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function priorityLabel(): string
    {
        return self::PRIORITY_LABELS[$this->priority] ?? $this->priority;
    }

    public function isIdentified(): bool
    {
        return $this->tenant_id !== null;
    }

    /* ==================================================================
     * The state machine
     * ================================================================== */

    /**
     * A message FROM the customer. It always reopens the ticket, including a
     * resolved or closed one: a customer writing again is the clearest
     * possible statement that the matter is not finished, and making them
     * open a second ticket to say so loses the history of the first.
     */
    public function recordCustomerMessage(string $body, ?string $authorName = null): SupportTicketMessage
    {
        $message = $this->messages()->create([
            'direction' => SupportTicketMessage::DIRECTION_INBOUND,
            'author_name' => $authorName ?? $this->requester_name,
            'author_email' => $this->requester_email,
            'body' => $body,
        ]);

        $this->forceFill([
            'status' => self::STATUS_OPEN,
            'last_customer_reply_at' => $message->created_at,
            'resolved_at' => null,
            'closed_at' => null,
        ])->save();

        return $message;
    }

    /**
     * A reply FROM support. Moves the ticket to waiting_customer, because
     * the work is now theirs. `sent_at` is stamped by the caller once the
     * mail actually went out -- an unsent outbound message stays visibly
     * unsent rather than silently counting as an answer.
     */
    public function recordSupportReply(string $body, User $author): SupportTicketMessage
    {
        $message = $this->messages()->create([
            'direction' => SupportTicketMessage::DIRECTION_OUTBOUND,
            'author_id' => $author->id,
            'author_name' => $author->name,
            'author_email' => config('ioms.emails.support'),
            'body' => $body,
        ]);

        $this->forceFill([
            'status' => self::STATUS_WAITING_CUSTOMER,
            'last_support_reply_at' => $message->created_at,
            'resolved_at' => null,
            'closed_at' => null,
        ])->save();

        return $message;
    }

    /** An operator setting the status by hand. Keeps the terminal timestamps honest. */
    public function moveTo(string $status): void
    {
        if (! in_array($status, self::STATUSES, true)) {
            return;
        }

        $this->forceFill([
            'status' => $status,
            'resolved_at' => $status === self::STATUS_RESOLVED ? ($this->resolved_at ?? now()) : null,
            'closed_at' => $status === self::STATUS_CLOSED ? ($this->closed_at ?? now()) : null,
        ])->save();
    }

    /**
     * Sequential, human-quotable, and generated from the table rather than
     * from a date, because a support reference is read aloud on the phone.
     */
    public static function generateReference(): string
    {
        $next = (int) static::query()->max('id') + 1;

        return 'TKT-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
