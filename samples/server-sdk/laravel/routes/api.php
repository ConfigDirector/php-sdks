<?php

declare(strict_types=1);

use App\Http\Controllers\ConfigsController;
use Illuminate\Support\Facades\Route;

Route::get('/configs', ConfigsController::class);
