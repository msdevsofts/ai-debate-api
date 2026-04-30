<?php

use App\Presentation\Http\Controllers\DiscordInteractionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/discord/interactions', [DiscordInteractionController::class, 'handle']);
