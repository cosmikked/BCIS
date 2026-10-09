<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Security\Actions\LogoutUser;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** POST /logout. Any signed-in user may end their own session (route list: authenticated_routes). */
final class LogoutController extends Controller
{
    public function __invoke(Request $request, LogoutUser $logout): RedirectResponse
    {
        $user = $request->user();

        $logout->handle($user instanceof User ? $user : null, $request->session());

        return redirect()->route('login');
    }
}
