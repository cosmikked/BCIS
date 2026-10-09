<?php

declare(strict_types=1);

use App\Domain\Security\Actions\ChangeOwnPassword;
use App\Domain\Security\Actions\CreateUser;
use App\Domain\Security\Actions\ResetUserPassword;
use App\Domain\Security\RoleCode;
use App\Livewire\Security\Login;
use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Support\Users;

// FR-SEC-011, INV-24: no password, token or secret appears in any log file.

uses(RefreshDatabase::class);

const SECRET_TYPED = 'LogScan-Typed-Pw-7731';
const SECRET_CONTEXT = 'LogScan-Context-Pw-4412';
const SECRET_MESSAGE = 'LogScan-Message-Pw-9921';
const SECRET_ARGUMENT = 'LogScan-Argument-Pw-5513';
const SECRET_TOKEN = 'LogScan-Token-Value-2208';

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->withoutVite();

    // The suite normally logs nowhere (phpunit.xml). Turn the real JSON channel on, in a scratch folder.
    $this->logDir = storage_path('framework/testing/logscan-'.uniqid());
    File::ensureDirectoryExists($this->logDir);
    config(['logging.default' => 'json', 'logging.channels.json.path' => $this->logDir.'/bcis.log']);
    app('log')->forgetChannel('json');
});

afterEach(function (): void {
    File::deleteDirectory($this->logDir);
});

function failsWithArgument(string $password): never
{
    throw new RuntimeException('Something broke while handling a request');
}

function loggedText(string $dir): string
{
    return collect(File::files($dir))->map(fn ($f) => File::get($f->getPathname()))->implode("\n");
}

it('keeps typed passwords, secrets in context, message text and stack-trace arguments out of the log', function (): void {
    // 1. A wrong password typed at sign-in.
    Users::make('maria');
    Livewire::test(Login::class)->set('username', 'maria')->set('password', SECRET_TYPED)->call('login');

    // 2. Secrets passed to the logger as context, in several shapes.
    Log::warning('Sign-in rejected', ['username' => 'maria', 'password' => SECRET_CONTEXT, 'nested' => ['api_token' => SECRET_TOKEN]]);

    // 3. A secret inside an exception message, as a database error can contain.
    report(new RuntimeException('SQL failed: update users set password=\''.SECRET_MESSAGE.'\' where id = 1'));

    // 4. A secret that is only a function argument in the stack trace.
    try {
        failsWithArgument(SECRET_ARGUMENT);
    } catch (Throwable $e) {
        report($e);
    }

    $text = loggedText($this->logDir);

    // Control: the log is being written, so an empty scan would mean nothing.
    expect($text)->toContain('Sign-in rejected')->toContain('Something broke while handling a request');

    foreach ([SECRET_TYPED, SECRET_CONTEXT, SECRET_MESSAGE, SECRET_ARGUMENT, SECRET_TOKEN] as $secret) {
        expect($text)->not->toContain($secret);
    }
});

it('keeps stack-trace arguments switched off for the whole app', function (): void {
    expect(ini_get('zend.exception_ignore_args'))->toBe('1');
});

it('keeps the one-time and changed passwords out of the log through the admin flows', function (): void {
    $owner = Users::make('owner', RoleCode::Owner);
    $viewerRole = Role::query()->where('code', 'VIEWER')->value('id');

    $created = app(CreateUser::class)->handle($owner, 'new.hire', 'New Hire', [$viewerRole]);
    $reset = app(ResetUserPassword::class)->handle($owner, $created->user->id, 'Forgot password');
    $maria = Users::make('maria');
    app(ChangeOwnPassword::class)->handle($maria, Users::PASSWORD, 'LogScan-New-Passphrase-0042', 'LogScan-New-Passphrase-0042', '127.0.0.1');

    // Provoke a logged error in the middle of it all.
    report(new RuntimeException('failure after the flows'));

    $text = loggedText($this->logDir);

    expect($text)->toContain('failure after the flows');
    foreach ([$created->temporaryPassword, $reset, Users::PASSWORD, 'LogScan-New-Passphrase-0042'] as $secret) {
        expect($text)->not->toContain($secret);
    }
});

it('finds no test password in any real log file under storage/logs after the suite has run', function (): void {
    // Everything the test suite types or generates. If any of it reached a real log file, this fails.
    $secrets = [Users::PASSWORD, SECRET_TYPED, SECRET_CONTEXT, SECRET_MESSAGE, SECRET_ARGUMENT, SECRET_TOKEN,
        'a-brand-new-long-passphrase', 'owner-test-secret', 'demo-test-secret', 'changed-by-owner', 'wrong-password-here'];

    // The suite itself logs nowhere (phpunit.xml), so usually there is nothing to find;
    // a developer's own log files from earlier runs are scanned too.
    $found = [];
    foreach (File::glob(storage_path('logs/*.log')) as $file) {
        $content = File::get($file);

        foreach ($secrets as $secret) {
            if (str_contains($content, $secret)) {
                $found[] = basename($file).' contains '.$secret;
            }
        }
    }

    expect($found)->toBe([]);
});
