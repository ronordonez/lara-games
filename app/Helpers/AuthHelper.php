<?php

namespace App\Helpers;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class AuthHelper
{
    public static function getAuthenticatedUser()
    {
        $user = Auth::guard('api')->user();
        if (!$user) {
            abort(response()->json(['error' => 'Usuario no autenticado'], 401));
        }
        return $user;
    }

    // Verifica si el usuario autenticado (o el provisto) tiene un permiso por slug
    public static function userHasPermission(string $permissionSlug, ?User $user = null): bool
    {
        $user = $user ?: self::getAuthenticatedUser();

        $roles = $user->roles; // el usuario tiene un rol

        foreach ($roles as $role) {
            if ($role->permissions()
                ->where('slug', $permissionSlug)
                ->exists()) {
                return true;
            }
        }

        return false;
    }

    // Lanza un 403 si el usuario no tiene el permiso
    public static function authorizePermission(string $permissionSlug): void
    {
        if (!self::userHasPermission($permissionSlug)) {
            abort(response()->json([
                'error' => 'Permisos insuficientes',
                'message' => 'No tiene permisos para realizar esta acción',
            ], 403));
        }
    }
}
