<?php

namespace App\Models\Contracts;

use App\Http\Middleware\EnsureCanvasVisible;
use App\Models\Concerns\IsSharedCanvas;

/**
 * Marker for models using {@see IsSharedCanvas}; lets the URL guard
 * ({@see EnsureCanvasVisible}) recognise any of them in a route.
 */
interface SharedCanvas {}
