<?php

declare(strict_types=1);

namespace App\Domain\Repositories;

use App\Domain\Entities\DebateSession;

interface DebateSessionRepositoryInterface
{
    public function findById(int $id): ?DebateSession;
    public function save(DebateSession $session): DebateSession;
}
