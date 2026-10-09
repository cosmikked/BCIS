<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Models\User;

/**
 * Filters config/navigation.php down to what a user may see (FR-UI-010).
 * Presentation only: the server still checks every route and action.
 */
final class NavigationMenu
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function visibleFor(?User $user, array $items): array
    {
        $visible = [];

        foreach ($items as $item) {
            if (isset($item['children'])) {
                $children = self::visibleFor($user, $item['children']);

                if ($children !== []) {
                    $visible[] = [...$item, 'children' => $children];
                }

                continue;
            }

            $permission = $item['permission'] ?? null;

            if ($permission === null || ($user !== null && $user->hasPermission((string) $permission))) {
                $visible[] = $item;
            }
        }

        return $visible;
    }
}
