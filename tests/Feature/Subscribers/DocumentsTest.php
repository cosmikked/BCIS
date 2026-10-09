<?php

declare(strict_types=1);

use App\Domain\Security\RoleCode;
use App\Domain\Subscribers\Actions\UploadSubscriberDocument;
use App\Livewire\Subscribers\SubscriberProfile;
use App\Models\AuditLog;
use App\Models\SubscriberDocument;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\SubscriberData;
use Tests\Support\Users;

// FR-SUB-010, INV-17, AT-10

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->withoutVite();
    // Documents go to a throwaway disk; Livewire's temporary uploads to another.
    Storage::fake('documents');
    Storage::fake('local');
    $this->admin = Users::make('admin', RoleCode::Administrator);
    $this->subscriber = SubscriberData::subscriber($this->admin);
});

function pdfFile(string $name = 'id-card.pdf'): File
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
}

function imageFile(string $name, string $format): File
{
    $image = imagecreatetruecolor(8, 8);
    ob_start();
    $format === 'png' ? imagepng($image) : imagejpeg($image);

    return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
}

function upload(User $actor, int $subscriberId, UploadedFile $file): SubscriberDocument
{
    return app(UploadSubscriberDocument::class)->handle($actor, $subscriberId, $file);
}

describe('accepting a document', function (): void {
    it('stores a PDF under a generated name and records what it is (FR-SUB-010)', function (): void {
        $file = pdfFile('Scan of ID.pdf');

        $document = upload($this->admin, $this->subscriber->id, $file);

        expect($document->file_path)->toMatch('/^[0-9a-f-]{36}\.pdf$/')
            ->and($document->original_name)->toBe('Scan of ID.pdf')
            ->and($document->mime_type)->toBe('application/pdf')
            ->and($document->size_bytes)->toBe($file->getSize())
            ->and($document->sha256)->toBe(hash_file('sha256', $file->getRealPath()))
            ->and($document->uploaded_by)->toBe($this->admin->id);

        Storage::disk('documents')->assertExists($document->file_path);
    });

    it('accepts JPEG and PNG pictures', function (string $format, string $mime, string $extension): void {
        $document = upload($this->admin, $this->subscriber->id, imageFile("photo.{$format}", $format));

        expect($document->mime_type)->toBe($mime)->and($document->file_path)->toEndWith(".{$extension}");
    })->with([
        'png' => ['png', 'image/png', 'png'],
        'jpeg' => ['jpeg', 'image/jpeg', 'jpg'],
    ]);

    it('writes an audit row for the upload', function (): void {
        $document = upload($this->admin, $this->subscriber->id, pdfFile());

        $audit = AuditLog::query()->where('action', 'subscriber.document_uploaded')->sole();
        expect($audit->entity_type)->toBe('subscriber')->and($audit->entity_id)->toBe($this->subscriber->id)
            ->and($audit->actor_user_id)->toBe($this->admin->id)
            ->and($audit->new_values['document_id'])->toBe($document->id)
            ->and($audit->new_values['sha256'])->toBe($document->sha256);
    });

    it('never uses the uploaded name as a path, and labels the file by its real type', function (): void {
        $document = upload($this->admin, $this->subscriber->id, pdfFile('..\\..\\public\\evil.png'));

        expect($document->file_path)->not->toContain('evil')->not->toContain('/')->not->toContain('\\')
            ->and($document->original_name)->toBe('evil.pdf');
    });
});

describe('refusing a document by its content (FR-SUB-010)', function (): void {
    it('rejects a renamed program, script, page or vector file whatever its name says', function (string $name, string $content): void {
        $before = SubscriberDocument::query()->count();

        expect(fn () => upload($this->admin, $this->subscriber->id, UploadedFile::fake()->createWithContent($name, $content)))
            ->toThrow(ValidationException::class);

        expect(SubscriberDocument::query()->count())->toBe($before)
            ->and(Storage::disk('documents')->allFiles())->toBe([])
            ->and(AuditLog::query()->where('action', 'subscriber.document_uploaded')->count())->toBe(0);
    })->with([
        'exe called png' => ['photo.png', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00\xb8\x00\x00\x00\x00\x00\x00\x00@\x00".str_repeat("\x00", 64)],
        'exe called pdf' => ['invoice.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00".str_repeat("\x00", 64)],
        'php called jpg' => ['photo.jpg', '<?php system($_GET["c"]); ?>'],
        'html called pdf' => ['form.pdf', '<html><body><script>alert(1)</script></body></html>'],
        'svg called png' => ['logo.png', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
        'text called pdf' => ['notes.pdf', 'just some plain text, not a pdf'],
        'zip called pdf' => ['archive.pdf', "PK\x03\x04".str_repeat("\x00", 40)],
    ]);

    it('rejects a real exe with an exe name too', function (): void {
        expect(fn () => upload($this->admin, $this->subscriber->id, UploadedFile::fake()->createWithContent('setup.exe', "MZ\x90\x00".str_repeat("\x00", 60))))
            ->toThrow(ValidationException::class);
    });

    it('rejects a picture header with no picture behind it', function (): void {
        // Starts with the PNG signature, but is not a decodable image.
        expect(fn () => upload($this->admin, $this->subscriber->id, UploadedFile::fake()->createWithContent('broken.png', "\x89PNG\r\n\x1a\n".str_repeat('x', 50))))
            ->toThrow(ValidationException::class);

        expect(Storage::disk('documents')->allFiles())->toBe([]);
    });

    it('rejects an empty file and one over the size limit', function (): void {
        expect(fn () => upload($this->admin, $this->subscriber->id, UploadedFile::fake()->createWithContent('empty.pdf', '')))
            ->toThrow(ValidationException::class);

        config(['bcis.documents.max_kb' => 1]);
        $big = UploadedFile::fake()->createWithContent('big.pdf', "%PDF-1.4\n".str_repeat('0', 2048)."\n%%EOF\n");

        try {
            upload($this->admin, $this->subscriber->id, $big);
            $this->fail('An oversize file must be refused.');
        } catch (ValidationException $e) {
            expect($e->errors()['upload'][0])->toContain('larger than 1 KB');
        }

        expect(SubscriberDocument::query()->count())->toBe(0);
    });

    it('allows exactly the configured size', function (): void {
        $content = "%PDF-1.4\n".str_repeat('0', 1000)."\n%%EOF\n";
        config(['bcis.documents.max_kb' => (int) ceil(strlen($content) / 1024)]);

        expect(upload($this->admin, $this->subscriber->id, UploadedFile::fake()->createWithContent('ok.pdf', $content)))
            ->toBeInstanceOf(SubscriberDocument::class);
    });

    it('leaves no file behind when the database write fails', function (): void {
        expect(fn () => upload($this->admin, 999999, pdfFile()))->toThrow(ModelNotFoundException::class);

        expect(Storage::disk('documents')->allFiles())->toBe([]);
    });
});

describe('who may upload (AT-10)', function (): void {
    it('refuses roles without subscriber.manage', function (RoleCode $role): void {
        expect(fn () => upload(Users::make('someone', $role), $this->subscriber->id, pdfFile()))->toThrow(AuthorizationException::class);

        expect(SubscriberDocument::query()->count())->toBe(0)->and(Storage::disk('documents')->allFiles())->toBe([]);
    })->with([
        'cashier' => RoleCode::Cashier,
        'supervisor' => RoleCode::CollectionSupervisor,
        'auditor' => RoleCode::Auditor,
        'technician' => RoleCode::Technician,
        'viewer' => RoleCode::Viewer,
    ]);
});

describe('downloading through the controller (FR-SUB-010)', function (): void {
    it('serves the file to a user with subscriber.view as a download that is not sniffed', function (): void {
        $document = upload($this->admin, $this->subscriber->id, pdfFile('Scan of ID.pdf'));
        $cashier = Users::make('cash', RoleCode::Cashier);

        $response = $this->actingAs($cashier)->get(route('subscriber-documents.show', $document->id));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('Scan of ID.pdf');
        expect($response->headers->get('Content-Disposition'))->toContain('attachment');
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
        expect($response->streamedContent())->toBe(Storage::disk('documents')->get($document->file_path));
    });

    it('is refused for the Technician and for a guest', function (): void {
        $document = upload($this->admin, $this->subscriber->id, pdfFile());

        $this->actingAs(Users::make('tony', RoleCode::Technician))->get(route('subscriber-documents.show', $document->id))->assertForbidden();
        auth()->logout();
        $this->get(route('subscriber-documents.show', $document->id))->assertRedirect(route('login'));
    });

    it('returns 404 for an unknown document or a file that has gone missing', function (): void {
        $document = upload($this->admin, $this->subscriber->id, pdfFile());

        $this->actingAs($this->admin)->get(route('subscriber-documents.show', 999999))->assertNotFound();

        Storage::disk('documents')->delete($document->file_path);
        $this->actingAs($this->admin)->get(route('subscriber-documents.show', $document->id))->assertNotFound();
    });

    it('cannot be reached by a direct URL to the stored file', function (): void {
        $document = upload($this->admin, $this->subscriber->id, pdfFile());
        $name = $document->file_path;

        foreach (['/storage/'.$name, '/storage/subscriber-documents/'.$name, '/'.$name, '/subscriber-documents/'.$name, '/documents/'.$name, '/storage/app/private/subscriber-documents/'.$name] as $url) {
            // Laravel's own /storage/{path} route answers 403 to an unsigned request, the rest 404.
            // Either way the file is not delivered.
            $status = $this->actingAs($this->admin)->get($url)->getStatusCode();
            expect($status)->toBeIn([403, 404], "{$url} answered {$status}");
        }

        expect(file_exists(public_path($name)))->toBeFalse()
            ->and(file_exists(public_path('storage/'.$name)))->toBeFalse();
    });

    it('keeps the documents disk private by configuration', function (): void {
        $config = require base_path('config/filesystems.php');
        $disk = $config['disks']['documents'];
        $root = str_replace('\\', '/', $disk['root']);

        expect($disk['serve'])->toBeFalse()
            ->and($disk)->not->toHaveKey('url')
            ->and($root)->not->toContain('storage/app/public')
            ->and($root)->not->toStartWith(str_replace('\\', '/', public_path()));
    });
});

describe('the Documents tab', function (): void {
    function openDocuments(User $user, int $subscriberId)
    {
        return Livewire::actingAs($user)->test(SubscriberProfile::class, ['subscriber' => $subscriberId])->call('setTab', 'documents');
    }

    it('uploads through the screen and lists the file with a download link', function (): void {
        $component = openDocuments($this->admin, $this->subscriber->id)
            ->set('upload', pdfFile('contract.pdf'))
            ->call('uploadDocument')
            ->assertHasNoErrors()
            ->assertSee('contract.pdf uploaded')
            ->assertSet('upload', null);

        $document = SubscriberDocument::query()->sole();
        $component->assertSee(route('subscriber-documents.show', $document->id), false)->assertSee($this->admin->full_name);
        expect(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('shows the reason and keeps nothing when the file is refused', function (): void {
        openDocuments($this->admin, $this->subscriber->id)
            ->set('upload', UploadedFile::fake()->createWithContent('photo.png', "MZ\x90\x00".str_repeat("\x00", 60)))
            ->call('uploadDocument')
            ->assertHasErrors(['upload'])
            ->assertSet('upload', null);

        expect(SubscriberDocument::query()->count())->toBe(0)
            ->and(Storage::disk('documents')->allFiles())->toBe([])
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('asks for a file when none is chosen', function (): void {
        openDocuments($this->admin, $this->subscriber->id)->call('uploadDocument')->assertHasErrors(['upload']);
    });

    it('offers upload only to subscriber.manage, but listing to subscriber.view', function (): void {
        upload($this->admin, $this->subscriber->id, pdfFile('contract.pdf'));

        openDocuments(Users::make('cash', RoleCode::Cashier), $this->subscriber->id)
            ->assertSee('contract.pdf')->assertDontSee('Add a document');
        openDocuments($this->admin, $this->subscriber->id)->assertSee('Add a document');
    });

    it('refuses an upload attempt from a read-only role (AT-10)', function (): void {
        Livewire::actingAs(Users::make('cash', RoleCode::Cashier))->test(SubscriberProfile::class, ['subscriber' => $this->subscriber->id])
            ->set('upload', pdfFile())
            ->call('uploadDocument')
            ->assertForbidden();

        expect(SubscriberDocument::query()->count())->toBe(0);
    });
});

it('has no way to delete or replace a document through the routes (INV-19)', function (): void {
    $methods = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'subscriber-documents'))
        ->flatMap(fn ($route) => $route->methods())->unique()->sort()->values()->all();

    expect($methods)->toBe(['GET', 'HEAD']);
});
