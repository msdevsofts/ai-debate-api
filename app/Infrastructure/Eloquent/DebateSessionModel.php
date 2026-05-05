<?php

declare(strict_types=1);

namespace App\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $topic
 * @property string|null $initial_ai
 * @property string|null $discord_channel_id
 * @property int $current_turn
 * @property int $max_turns
 * @property string|null $dify_conversation_id
 * @property string $status
 * @property string|null $current_turn_id
 */
class DebateSessionModel extends Model
{
    protected $table = 'debate_sessions';

    protected $fillable = [
        'topic',
        'initial_ai',
        'discord_channel_id',
        'discord_webhook_url',
        'current_turn',
        'max_turns',
        'dify_conversation_id',
        'status',
        'current_turn_id',
    ];
}
