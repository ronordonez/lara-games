<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Laravel\Socialite\Facades\Socialite;
use Tymon\JWTAuth\Facades\JWTAuth;

use Exception;

class AuthController extends Controller
{
    /**
     * Registrar un nuevo usuario
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => bcrypt($request->password),
                'status' => true,
            ]);

            // Generar token JWT
            $token = auth('api')->login($user);
            $timeExpires = auth('api')->factory()->getTTL() * 60;

            return response()->json([
                'success' => true,
                'message' => 'Usuario registrado exitosamente',
                'user' => $user,
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => $timeExpires,
            ], Response::HTTP_CREATED);

        } catch (Exception $e) {
            Log::error('Error al registrar usuario: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al registrar usuario',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

     public function login(Request $request)
    {
        Log::info('Intento de inicio de sesión para el correo: ' . $request->input('email'));
        // Validamos la entrada del usuario
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        // Verificamos si el usuario existe
        $user = User::where('email', $credentials['email'])->first();
        // Si el usuario no existe, retornar un mensaje
        if (!$user) {
            return response()->json(['message' => 'El usuario no existe.'], Response::HTTP_NOT_FOUND);
        }

        // Verificar si el usuario está bloqueado
        if ($user->is_locked) {
            // Comprobar si la hora de bloqueo ha pasado
            if ($user->lockout_time && now()->lessThan($user->lockout_time)) {
                return response()->json(['message' => 'El usuario está bloqueado. Intente de nuevo más tarde.'], 403);
            } else {
                // Desbloquear al usuario después de 1 hora
                $user->is_locked = false;
                $user->login_attempts = 0; // Reiniciar los intentos de inicio de sesión
                $user->lockout_time = null; // Limpiar la hora de bloqueo
                $user->save();
            }
        }

        // Verificar la contraseña
        if (!Hash::check($credentials['password'], $user->password)) {
            // Registrar la dirección IP del intento fallido
            $user->last_ip_address = $request->ip();
            $user->increment('login_attempts');

            // Si se alcanzan 3 intentos fallidos, bloquear al usuario
            if ($user->login_attempts >= 3) {
                $user->is_locked = true;
                $user->lockout_time = now()->addHour(); // Bloquear durante 1 hora
                $user->save();
                return response()->json(['message' => 'El usuario ha sido bloqueado por demasiados intentos fallidos.'], Response::HTTP_FORBIDDEN);
            }

            return response()->json(['message' => 'Contraseña incorrecta. Intento fallido ' . $user->login_attempts], Response::HTTP_FORBIDDEN);
        }

        // Reiniciar los intentos de inicio de sesión después de un inicio exitoso
        $user->login_attempts = 0;
        $user->last_ip_address = null; // Limpiar la dirección IP

        $user->save();

        $token = auth('api')->claims(['user' => $user])->attempt($credentials);


        $timeExpires = auth('api')->factory()->getTTL() * 60;
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $timeExpires,
        ], Response::HTTP_OK);
    }

    /**
     * Redireccionar a Google OAuth
     */
    public function redirectToGoogle()
    {
        Log::info('Iniciando redirección a Google OAuth');

        // Verificar que la configuración de Google esté presente
        $clientId = config('services.google.client_id');
        $clientSecret = config('services.google.client_secret');

        if (empty($clientId) || empty($clientSecret)) {
            $msg = 'Faltan variables de configuración de Google OAuth. Por favor configure GOOGLE_CLIENT_ID y GOOGLE_CLIENT_SECRET en el archivo .env y ejecute php artisan config:clear';
            Log::error('Google OAuth configuración incompleta: ' . $msg);
            return response()->json([
                'success' => false,
                'message' => $msg,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            // Si la petición viene desde un navegador y desea redireccionar directamente,
            // se puede pasar ?direct=1. Si es una SPA, se devuelve la URL para que el frontend la abra.
            $driver = Socialite::driver('google')->stateless();

            // Si el cliente pidió una redirección directa en el navegador, retornamos el RedirectResponse
            if (request()->query('direct') === '1' || str_contains(request()->header('Accept', ''), 'text/html')) {
                return $driver->redirect();
            }

            $url = $driver->redirect()->getTargetUrl();

            return response()->json([
                'success' => true,
                'url' => $url
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Error al redireccionar a Google: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al iniciar autenticación con Google',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Manejar callback de Google OAuth
     */
    public function handleGoogleCallback(Request $request)
    {
        try {
            // Comprobar parámetros requeridos
            if ($request->has('error')) {
                Log::error('Google OAuth returned error: ' . $request->query('error'));
                return response()->json([
                    'success' => false,
                    'message' => 'Google OAuth error: ' . $request->query('error_description', $request->query('error'))
                ], Response::HTTP_BAD_REQUEST);
            }

            if (!$request->has('code')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Falta el parámetro de autorización (code). Inicie el flujo en /api/auth/google o abra la URL que devuelve ese endpoint.'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Obtener información del usuario desde Google
            $googleUser = Socialite::driver('google')->stateless()->user();

            // Buscar o crear usuario
            $user = User::where('email', $googleUser->getEmail())->first();

            if (!$user) {
                // Crear nuevo usuario si no existe
                $user = User::create([
                    'name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'password' => bcrypt(uniqid()), // Password aleatorio
                    'email_verified_at' => now(),
                    'status' => true,
                ]);
            }

            // Actualizar avatar del usuario si viene de Google
            if ($googleUser->getAvatar() && !$user->avatar) {
                $user->avatar = $googleUser->getAvatar();
                $user->save();
            }

            // Generar token JWT
            $token = auth('api')->login($user);


            $timeExpires = auth('api')->factory()->getTTL() * 60;

            // Si la petición viene del navegador, mostrar página HTML con el token
            if (str_contains($request->header('Accept', ''), 'text/html')) {
                $htmlResponse = view('auth.google-success', [
                    'user' => $user,
                    'token' => $token,
                    'expires_in' => $timeExpires,
                ])->render();

                return response($htmlResponse, 200)->header('Content-Type', 'text/html');
            }

            return response()->json([
                'success' => true,
                'message' => 'Autenticación exitosa con Google',
                'user' => $user,
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => $timeExpires,
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Error en callback de Google: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al autenticar con Google',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Obtener información del usuario autenticado
     */
    public function me(Request $request)
    {
        Log::info('Obteniendo información del usuario autenticado');
        try {
            $user = auth('api')->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], Response::HTTP_UNAUTHORIZED);
            }

            return response()->json([
                'success' => true,
                'user' => $user
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Error al obtener usuario: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener información del usuario',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function logout(Request $request)
    {
        try {
            auth('api')->logout();

            return response()->json([
                'success' => true,
                'message' => 'Sesión cerrada exitosamente'
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Error al cerrar sesión: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cerrar sesión',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function sapi()
    {
        return response()->json(['message' => 'Sapi']);
    }

     public function buildMenuStructure(int $rolId)
    {
        // Get the list of permission IDs associated with the user's role
        $role = Role::with(['permissions'])->find($rolId);
        $userPermissions = $role->permissions->pluck('id')->toArray();

        // Obtain the menu groups with their items and children
        $menuGroups = MenuGroup::with(['items.children'])->get();

        // Initialize the menu structure
        $menuStructure = [];

        // Group the items by menu group
        foreach ($menuGroups as $group) {
            $groupMenu = [
                'label' => $group->label,
                'group' => true,
                'icon' => $group->icon,
                'items' => [],
            ];

            // Filter the group's items
            foreach ($group->items as $item) {
                // Skip permission check if item is a dropdown; otherwise, check permission
                $hasPermission = $item->dropdown || in_array($item->permission_id, $userPermissions);

                // Process only top-level items (items without a parent_id)
                if ($hasPermission && !$item->parent_id) {
                    // Initialize the menu item
                    $menuItem = [
                        'label' => $item->label,
                        'icon' => $item->icon,
                        'route' => $item->route,
                        'badge' => $item->badge,
                        'dropdown' => $item->dropdown,
                        'tooltip' => $item->tooltip,
                        'items' => [],
                    ];

                    // Only add children if they exist and have permissions
                    if ($item->dropdown) {
                        foreach ($item->children as $child) {
                            // Check permission for child items
                            $hasChildPermission = in_array($child->permission_id, $userPermissions);

                            // Add the child to the menu only if they have permission
                            if ($hasChildPermission) {
                                $menuItem['items'][] = [
                                    'label' => $child->label,
                                    'icon' => $child->icon,
                                    'route' => $child->route,
                                ];
                            }
                        }
                    }

                    // Only add the menu item if it has items or it's not a dropdown
                    if ($menuItem['items'] || !$item->dropdown) {
                        $groupMenu['items'][] = $menuItem;
                    }
                }
            }

            // Only add the group if it has items
            if (!empty($groupMenu['items'])) {
                $menuStructure[] = $groupMenu;
            }
        }

        return $menuStructure;
    }


    public function validatePermission(Request $request)
    {
        $permission = $request->validate([
            'user_id' => 'required',
            'slug' => 'required',

        ]);

        $User = User::find($request->user_id);

        if (!$User) {
            return response()->json(['message' => ' usuario no encontrado.'], Response::HTTP_NOT_FOUND);
        }
        $permission = Permission::where('slug', $request->slug)->first();

        if (!$permission) {
            return response()->json(['message' => 'Permiso no encontrado: '], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'success' => true,
            'message' => $permission->slug,
        ], Response::HTTP_OK);
    }
}
