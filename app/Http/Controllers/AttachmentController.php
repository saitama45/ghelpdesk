<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Download an attachment with its original filename.
     */
    public function download(Request $request): StreamedResponse
    {
        $path = $request->query('path');
        $name = $request->query('name', 'attachment');

        if (!$path) {
            abort(404, 'File not found');
        }

        // Clean path to prevent directory traversal
        $path = str_replace(['../', './'], '', $path);
        
        if (!Storage::disk('public')->exists($path)) {
            abort(404, 'File not found on disk');
        }

        return Storage::disk('public')->download($path, $name);
    }
}
