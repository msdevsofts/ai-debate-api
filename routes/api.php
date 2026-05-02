<?php

use App\Http\Middleware\VerifyDiscordSignature;
use App\Presentation\Http\Controllers\DiscordInteractionController;
use App\Presentation\Http\Controllers\DiscordMessageController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/discord/interactions', [DiscordInteractionController::class, 'handle'])
    ->middleware(VerifyDiscordSignature::class);
Route::post('/discord/messages', [DiscordMessageController::class, 'handle']);
