<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Source;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FacebookOAuthController extends Controller
{
    protected $apiVersion;
    protected $baseUrl;

    public function __construct()
    {
        $this->apiVersion = config('services.facebook.api_version', 'v21.0');
        $this->baseUrl = "https://graph.facebook.com/{$this->apiVersion}";
    }

    /**
     * Iniciar flujo OAuth de Facebook
     * GET /api/facebook/oauth/redirect
     */
    public function redirectToFacebook(Request $request)
    {
        $appId = config('services.facebook.app_id');
        $redirectUri = config('services.facebook.redirect_uri', config('app.url') . '/api/facebook/oauth/callback');

        if (empty($appId)) {
            return response()->json([
                'success' => false,
                'message' => 'Facebook App ID no configurado. Configure FACEBOOK_APP_ID en .env',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Permisos necesarios para acceder a páginas y publicaciones
        $permissions = [
            'email',
            'public_profile',
            'pages_show_list',           // Ver lista de páginas
            'pages_read_engagement',     // Leer engagement de páginas
            'pages_read_user_content',   // Leer contenido de páginas
            'pages_manage_posts',        // Gestionar publicaciones (opcional)
        ];

        // Construir URL de autorización
        $authUrl = "https://www.facebook.com/v21.0/dialog/oauth?" . http_build_query([
            'client_id' => $appId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(',', $permissions),
            'response_type' => 'code',
            'state' => csrf_token(),
        ]);

        // Si es una petición desde navegador, redirigir directamente
        if ($request->query('direct') === '1' || str_contains($request->header('Accept', ''), 'text/html')) {
            return redirect($authUrl);
        }

        // Si es SPA o API, devolver la URL
        return response()->json([
            'success' => true,
            'url' => $authUrl,
            'permissions' => $permissions,
        ], Response::HTTP_OK);
    }

    /**
     * Callback de OAuth de Facebook
     * GET /api/facebook/oauth/callback
     */
    public function handleFacebookCallback(Request $request)
    {
        try {
            // Verificar errores
            if ($request->has('error')) {
                Log::error('Facebook OAuth error: ' . $request->query('error'));
                return response()->json([
                    'success' => false,
                    'message' => 'Error de autorización: ' . $request->query('error_description', $request->query('error')),
                ], Response::HTTP_BAD_REQUEST);
            }

            if (!$request->has('code')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Código de autorización no recibido',
                ], Response::HTTP_BAD_REQUEST);
            }

            $code = $request->query('code');

            // Intercambiar código por token de acceso
            $tokenData = $this->exchangeCodeForToken($code);

            if (!$tokenData || !isset($tokenData['access_token'])) {
                throw new Exception('No se pudo obtener el token de acceso');
            }

            $accessToken = $tokenData['access_token'];

            // Obtener información del usuario de Facebook
            $fbUser = $this->getFacebookUserInfo($accessToken);

            // Buscar o crear usuario en la base de datos
            $user = auth('api')->user();

            if (!$user) {
                // Si no hay usuario autenticado, buscar por email o crear uno nuevo
                $user = User::where('email', $fbUser['email'] ?? null)->first();

                if (!$user && isset($fbUser['email'])) {
                    $user = User::create([
                        'name' => $fbUser['name'] ?? 'Usuario Facebook',
                        'email' => $fbUser['email'],
                        'password' => bcrypt(uniqid()),
                        'email_verified_at' => now(),
                        'status' => true,
                    ]);
                }
            }

            if (!$user) {
                throw new Exception('No se pudo identificar al usuario');
            }

            // Guardar token de Facebook en el usuario
            $user->update([
                'facebook_token' => $accessToken,
                'facebook_token_expires' => isset($tokenData['expires_in'])
                    ? now()->addSeconds($tokenData['expires_in'])
                    : null,
                'facebook_user_id' => $fbUser['id'] ?? null,
            ]);

            // Generar token JWT
            $jwtToken = auth('api')->login($user);
            $timeExpires = auth('api')->factory()->getTTL() * 60;

            // Si es petición del navegador, mostrar página de éxito
            if (str_contains($request->header('Accept', ''), 'text/html')) {
                return response()->view('auth.facebook-success', [
                    'user' => $user,
                    'token' => $jwtToken,
                    'expires_in' => $timeExpires,
                    'facebook_token' => $accessToken,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Autenticación con Facebook exitosa',
                'user' => $user,
                'access_token' => $jwtToken,
                'token_type' => 'bearer',
                'expires_in' => $timeExpires,
                'facebook_token' => $accessToken,
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Error en callback de Facebook: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al autenticar con Facebook',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Obtener páginas del usuario autenticado
     * GET /api/facebook/pages
     */
    public function getPages(Request $request)
    {
        try {
            $user = auth('api')->user();
            $accessToken = $request->input('access_token') ?? $user->facebook_token;

            if (!$accessToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token de Facebook no encontrado. Por favor, autentíquese primero.',
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Obtener páginas del usuario
            $response = Http::get("{$this->baseUrl}/me/accounts", [
                'access_token' => $accessToken,
                'fields' => 'id,name,access_token,category,fan_count,picture',
            ]);

            if (!$response->successful()) {
                throw new Exception('Error al obtener páginas: ' . $response->body());
            }

            $data = $response->json();
            $pages = $data['data'] ?? [];

            // Cachear páginas por 1 hora
            Cache::put("facebook_pages_{$user->id}", $pages, now()->addHour());

            return response()->json([
                'success' => true,
                'message' => 'Páginas obtenidas exitosamente',
                'pages' => $pages,
                'total' => count($pages),
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Error al obtener páginas de Facebook: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener páginas de Facebook',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Obtener publicaciones de una página
     * GET /api/facebook/pages/{pageId}/posts
     */
    public function getPagePosts(Request $request, string $pageId)
    {
        try {
            $user = auth('api')->user();
            $pageAccessToken = $request->input('page_access_token');
            $limit = $request->input('limit', 25);
            $since = $request->input('since'); // Formato: YYYY-MM-DD
            $until = $request->input('until'); // Formato: YYYY-MM-DD

            if (!$pageAccessToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Se requiere el page_access_token de la página',
                ], Response::HTTP_BAD_REQUEST);
            }

            $params = [
                'access_token' => $pageAccessToken,
                'fields' => 'id,message,created_time,permalink_url,full_picture,reactions.summary(true),comments.summary(true),shares',
                'limit' => $limit,
            ];

            if ($since) {
                $params['since'] = strtotime($since);
            }

            if ($until) {
                $params['until'] = strtotime($until);
            }

            $response = Http::get("{$this->baseUrl}/{$pageId}/posts", $params);

            if (!$response->successful()) {
                throw new Exception('Error al obtener publicaciones: ' . $response->body());
            }

            $data = $response->json();
            $posts = $data['data'] ?? [];

            // Formatear publicaciones
            $formattedPosts = array_map(function ($post) use ($pageId) {
                return [
                    'id' => $post['id'] ?? null,
                    'page_id' => $pageId,
                    'message' => $post['message'] ?? null,
                    'created_time' => $post['created_time'] ?? null,
                    'permalink_url' => $post['permalink_url'] ?? null,
                    'picture' => $post['full_picture'] ?? null,
                    'reactions_count' => $post['reactions']['summary']['total_count'] ?? 0,
                    'comments_count' => $post['comments']['summary']['total_count'] ?? 0,
                    'shares_count' => $post['shares']['count'] ?? 0,
                ];
            }, $posts);

            return response()->json([
                'success' => true,
                'message' => 'Publicaciones obtenidas exitosamente',
                'posts' => $formattedPosts,
                'total' => count($formattedPosts),
                'paging' => $data['paging'] ?? null,
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Error al obtener publicaciones de Facebook: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener publicaciones',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Crear source desde una publicación de Facebook
     * POST /api/facebook/sources/create
     */
    public function createSourceFromPost(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'page_id' => 'required|string',
                'page_name' => 'required|string',
                'page_access_token' => 'required|string',
                'post_id' => 'required|string',
                'post_url' => 'required|string',
                'interaction_type' => 'required|in:comments,likes,reactions,all',
                'filters' => 'nullable|array',
                'cache_ttl_hours' => 'nullable|integer|min:1|max:168',
            ]);

            $user = auth('api')->user();

            // Normalizar interaction_type para que coincida con el enum en la BD
            // Migración define: comment, like, share, subscribe, follow, any
            $incomingInteraction = $validated['interaction_type'];
            $map = [
                'comments' => 'comment',
                'comment' => 'comment',
                'likes' => 'like',
                'reactions' => 'like',
                'like' => 'like',
                'share' => 'share',
                'shares' => 'share',
                'subscribe' => 'subscribe',
                'follow' => 'follow',
                'all' => 'any',
                'any' => 'any',
            ];

            $interaction = $map[strtolower($incomingInteraction)] ?? $incomingInteraction;

            // Crear la fuente
            $source = Source::create([
                'user_id' => $user->id,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? "Publicación de Facebook: {$validated['page_name']}",
                'source_type' => 'facebook',
                'source_config' => [
                    'page_id' => $validated['page_id'],
                    'page_name' => $validated['page_name'],
                    'page_access_token' => $validated['page_access_token'],
                    'post_id' => $validated['post_id'],
                    'post_url' => $validated['post_url'],
                ],
                'interaction_type' => $interaction,
                'filters' => $validated['filters'] ?? [],
                'cache_ttl_hours' => $validated['cache_ttl_hours'] ?? 24,
                'status' => 'active',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Source creado exitosamente',
                'source' => $source,
            ], Response::HTTP_CREATED);

        } catch (Exception $e) {
            Log::error('Error al crear source de Facebook: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al crear source',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Sincronizar participantes desde Facebook
     * POST /api/facebook/sources/{sourceId}/sync
     */
    public function syncParticipants(Request $request, int $sourceId)
    {
        try {
            $user = auth('api')->user();

            // Si no hay usuario autenticado, devolver 401 (evita redirecciones a route 'login')
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'No autenticado. Proporcione un token JWT en Authorization: Bearer <token>',
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Buscar la fuente; usar first() para poder devolver 404 JSON amigable
            $source = Source::where('id', $sourceId)
                ->where('user_id', $user->id)
                ->where('source_type', 'facebook')
                ->first();

            if (!$source) {
                return response()->json([
                    'success' => false,
                    'message' => 'Source no encontrada o no pertenece al usuario',
                ], Response::HTTP_NOT_FOUND);
            }

            $postId = $source->getConfigValue('post_id');
            $pageAccessToken = $source->getConfigValue('page_access_token');
            $interactionType = $source->interaction_type;

            if (!$postId || !$pageAccessToken) {
                throw new Exception('Configuración de la fuente incompleta');
            }

            $participants = [];

            // Obtener participantes según el tipo de interacción
            if (in_array($interactionType, ['comment', 'all'])) {
                $comments = $this->fetchAllComments($postId, $pageAccessToken);
                Log::info('Comentarios obtenidos: ' . count($comments));
                Log::info($comments);
                $participants = array_merge($participants, $comments);
            }

            if (in_array($interactionType, ['like', 'reaction', 'all'])) {
                $reactions = $this->fetchAllReactions($postId, $pageAccessToken);
                $participants = array_merge($participants, $reactions);
            }

            // NOTA: No eliminar duplicados antes de aplicar filtros.
            // Algunos filtros (p. ej. contains_text) deben comprobar todos los
            // comentarios de un usuario; si deduplicamos ahora se pierde la segunda
            // interacción que podría cumplir el filtro. Aplicamos filtros sobre
            // el conjunto completo y luego deduplicamos por usuario seleccionando
            // la mejor interacción.

            // Aplicar filtros sobre todas las interacciones
            $filteredParticipants = $source->applyFilters($participants);

            // Después de filtrar, deduplicar por identifier y escoger la mejor
            // entrada por usuario (más likes, luego más reciente)
            $groups = [];
            foreach ($filteredParticipants as $p) {
                $identifier = $p['identifier'] ?? ($p['user_id'] ?? ($p['name'] ?? null));
                if (!$identifier) {
                    continue;
                }

                if (!isset($groups[$identifier])) {
                    $groups[$identifier] = $p;
                    continue;
                }

                $current = $groups[$identifier];
                $currLikes = intval($current['likes_count'] ?? 0);
                $pLikes = intval($p['likes_count'] ?? 0);

                if ($pLikes > $currLikes) {
                    $groups[$identifier] = $p;
                    continue;
                }

                if ($pLikes === $currLikes) {
                    $currDate = !empty($current['interaction_date']) ? \Carbon\Carbon::parse($current['interaction_date']) : null;
                    $pDate = !empty($p['interaction_date']) ? \Carbon\Carbon::parse($p['interaction_date']) : null;
                    if ($pDate && $currDate) {
                        if ($pDate->gt($currDate)) {
                            $groups[$identifier] = $p;
                        }
                    } elseif ($pDate && !$currDate) {
                        $groups[$identifier] = $p;
                    }
                }
            }

            $filteredParticipants = array_values($groups);

            // Guardar en cache
            $source->cacheParticipants($filteredParticipants);

            return response()->json([
                'success' => true,
                'message' => 'Participantes sincronizados exitosamente',
                'total_raw' => count($participants),
                'total_valid' => count($filteredParticipants),
                'source' => $source->fresh(),
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Error al sincronizar participantes de Facebook: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al sincronizar participantes',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Métodos auxiliares privados
     */
    private function exchangeCodeForToken(string $code): ?array
    {
        $appId = config('services.facebook.app_id');
        $appSecret = config('services.facebook.app_secret');
        $redirectUri = config('services.facebook.redirect_uri', config('app.url') . '/api/facebook/oauth/callback');

        $response = Http::get("{$this->baseUrl}/oauth/access_token", [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        if (!$response->successful()) {
            Log::error('Error al intercambiar código por token: ' . $response->body());
            return null;
        }

        return $response->json();
    }

    private function getFacebookUserInfo(string $accessToken): array
    {
        $response = Http::get("{$this->baseUrl}/me", [
            'access_token' => $accessToken,
            'fields' => 'id,name,email,picture',
        ]);

        if (!$response->successful()) {
            throw new Exception('Error al obtener información del usuario');
        }

        return $response->json();
    }

    private function fetchAllComments(string $postId, string $accessToken): array
    {
        $allComments = [];
        $url = "{$this->baseUrl}/{$postId}/comments";
        $params = [
            'access_token' => $accessToken,
            'fields' => 'id,from,message,created_time,like_count',
            'limit' => 100,
        ];

        do {
            $response = Http::get($url, $params);
            if (!$response->successful()) {
                Log::warning("Error al obtener comentarios: {$response->body()}");
                break;
            }

            $data = $response->json();
            $comments = $data['data'] ?? [];

            foreach ($comments as $comment) {
                $allComments[] = [
                    'identifier' => $comment['from']['id'] ?? uniqid('fb_'),
                    'user_id' => $comment['from']['id'] ?? '',
                    'name' => $comment['from']['name'] ?? 'Usuario',
                    'interaction_type' => 'comment',
                    'content' => $comment['message'] ?? '',
                    'interaction_date' => $comment['created_time'] ?? now(),
                    'likes_count' => $comment['like_count'] ?? 0,
                    'profile_url' => isset($comment['from']['id'])
                        ? "https://facebook.com/{$comment['from']['id']}"
                        : null,
                ];
            }

            // Paginación
            $url = $data['paging']['next'] ?? null;
            $params = []; // Los parámetros ya vienen en la URL next

        } while ($url);

        return $allComments;
    }

    private function fetchAllReactions(string $postId, string $accessToken): array
    {
        $allReactions = [];
        $url = "{$this->baseUrl}/{$postId}/reactions";
        $params = [
            'access_token' => $accessToken,
            'fields' => 'id,name,type',
            'limit' => 100,
        ];

        do {
            $response = Http::get($url, $params);
            if (!$response->successful()) {
                Log::warning("Error al obtener reacciones: {$response->body()}");
                break;
            }

            $data = $response->json();
            $reactions = $data['data'] ?? [];

            foreach ($reactions as $reaction) {
                $allReactions[] = [
                    'identifier' => $reaction['id'] ?? uniqid('fb_'),
                    'user_id' => $reaction['id'] ?? '',
                    'name' => $reaction['name'] ?? 'Usuario',
                    'interaction_type' => $reaction['type'] ?? 'like',
                    'content' => '',
                    'interaction_date' => now(),
                    'likes_count' => 0,
                    'profile_url' => isset($reaction['id'])
                        ? "https://facebook.com/{$reaction['id']}"
                        : null,
                ];
            }

            // Paginación
            $url = $data['paging']['next'] ?? null;
            $params = [];

        } while ($url);

        return $allReactions;
    }
}
