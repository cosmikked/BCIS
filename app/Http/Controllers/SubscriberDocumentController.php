<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SubscriberDocument;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way to read a subscriber document (FR-SUB-010). Stored files sit on
 * the private "documents" disk, which has no URL, so a guessed path cannot
 * reach them. The route also needs subscriber.view; the check is repeated here.
 *
 * The response uses the content type recorded when the file was accepted, forces
 * a download instead of showing it in the page, and tells the browser not to
 * guess a different type (nosniff).
 */
final class SubscriberDocumentController extends Controller
{
    public function __invoke(SubscriberDocument $document): StreamedResponse
    {
        Gate::authorize('subscriber.view');

        $disk = Storage::disk('documents');
        abort_unless($disk->exists($document->file_path), 404);

        return $disk->download($document->file_path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
