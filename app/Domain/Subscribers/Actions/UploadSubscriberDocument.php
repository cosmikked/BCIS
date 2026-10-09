<?php

declare(strict_types=1);

namespace App\Domain\Subscribers\Actions;

use App\Domain\Security\AuditAction;
use App\Domain\Security\AuditLogger;
use App\Models\Subscriber;
use App\Models\SubscriberDocument;
use App\Models\User;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Attaches a document to a subscriber (FR-SUB-010). The file is judged by its
 * CONTENT, never by its name or by the type the browser claims: a renamed .exe,
 * script, HTML or SVG file is refused however it is called. Only PDF, JPEG and
 * PNG are accepted, up to the configured size.
 *
 * The file is stored on the private "documents" disk under a generated name, so
 * the name the user typed can never become a path. It is served only through
 * SubscriberDocumentController. The database row and the audit row are written
 * in one transaction; if that fails, the stored file is removed again.
 */
final class UploadSubscriberDocument
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws ValidationException */
    public function handle(User $actor, int $subscriberId, UploadedFile $file): SubscriberDocument
    {
        Gate::forUser($actor)->authorize('subscriber.manage');

        $path = $file->isValid() ? $file->getRealPath() : false;
        if ($path === false) {
            throw $this->refuse('The file could not be read. Try uploading it again.');
        }

        $bytes = filesize($path);
        $maxKb = (int) config('bcis.documents.max_kb');
        if ($bytes === false || $bytes === 0) {
            throw $this->refuse('The file is empty.');
        }
        if ($bytes > $maxKb * 1024) {
            throw $this->refuse('The file is larger than '.$this->limitLabel($maxKb).'.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $types = config('bcis.documents.types');
        $extension = is_string($mime) && is_array($types) ? ($types[$mime] ?? null) : null;
        if ($extension === null) {
            throw $this->refuse('Only PDF, JPG and PNG files are accepted. The file content is checked, not its name.');
        }

        // An image must also decode as an image, not only start with the right bytes.
        if (str_starts_with($mime, 'image/') && @getimagesize($path) === false) {
            throw $this->refuse('The image is damaged or is not a real picture.');
        }

        $disk = Storage::disk('documents');
        $storedName = Str::uuid()->toString().'.'.$extension;
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw $this->refuse('The file could not be read. Try uploading it again.');
        }

        try {
            $disk->writeStream($storedName, $stream);
        } finally {
            fclose($stream);
        }

        try {
            return DB::transaction(function () use ($actor, $subscriberId, $storedName, $file, $mime, $bytes, $path, $extension): SubscriberDocument {
                $subscriber = Subscriber::query()->whereKey($subscriberId)->lockForUpdate()->firstOrFail();

                $document = SubscriberDocument::query()->create([
                    'subscriber_id' => $subscriber->id,
                    'file_path' => $storedName,
                    'original_name' => $this->displayName($file->getClientOriginalName(), $extension),
                    'mime_type' => $mime,
                    'size_bytes' => $bytes,
                    'sha256' => hash_file('sha256', $path),
                    'uploaded_by' => $actor->id,
                ]);

                $this->audit->record(AuditAction::SubscriberDocumentUploaded, $actor, 'subscriber', $subscriber->id,
                    newValues: [
                        'document_id' => $document->id,
                        'original_name' => $document->original_name,
                        'mime_type' => $mime,
                        'size_bytes' => $bytes,
                        'sha256' => $document->sha256,
                    ],
                );

                return $document;
            });
        } catch (Throwable $e) {
            // No row means no way to reach the file, so do not leave it behind.
            $disk->delete($storedName);

            throw $e;
        }
    }

    private function refuse(string $message): ValidationException
    {
        return ValidationException::withMessages(['upload' => $message]);
    }

    private function limitLabel(int $kb): string
    {
        return $kb >= 1024 ? rtrim(rtrim(number_format($kb / 1024, 1), '0'), '.').' MB' : $kb.' KB';
    }

    /**
     * The name shown in the document list: the uploaded name without any path or
     * control characters, with the extension of the DETECTED type so the label
     * never contradicts the content.
     */
    private function displayName(string $uploaded, string $extension): string
    {
        $base = pathinfo(str_replace('\\', '/', $uploaded), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $base));
        $base = mb_substr($base, 0, 150);

        return ($base === '' ? 'document' : $base).'.'.$extension;
    }
}
