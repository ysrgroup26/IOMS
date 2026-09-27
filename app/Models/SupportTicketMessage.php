<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * v2.80.0 -- one message in a support conversation.
 *
 * The direction is a column, not something inferred from `author_id` being
 * null, because a customer message logged by an operator and a message IOMS
 * generated itself would otherwise be indistinguishable -- and the direction
 * is what moves the ticket's status.
 */
class SupportTicketMessage extends Model
{
    /** From the customer. Reopens the ticket. */
    public const DIRECTION_INBOUND = 'inbound';

    /** From IOMS. Moves the ticket to waiting_customer. */
    public const DIRECTION_OUTBOUND = 'outbound';

    protected $fillable = [
        'support_ticket_id', 'direction', 'author_id', 'author_name', 'author_email', 'body', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function isFromCustomer(): bool
    {
        return $this->direction === self::DIRECTION_INBOUND;
    }
}
