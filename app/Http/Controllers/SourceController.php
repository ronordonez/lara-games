<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Services\SourceManager;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SourceController extends Controller
{
    protected $sourceManager;

    public function __construct(SourceManager $sourceManager)
    {
        $this->sourceManager = $sourceManager;
    }

    /**
     * Listar fuentes del usuario
     */
    public function index(Request $request)
    {
        try {
            $query = Source::with(['user', 'winners'])
                ->where('user_id', auth('api')->id());

            // Filtrar por tipo
            if ($request->has('source_type')) {
                $query->where('source_type', $request->source_type);
            }

            // Filtrar por estado
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $sources = $query->orderBy('created_at', 'desc')->paginate(15);

            return response()->json([
                'success' => true,
                'sources' => $sources,
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Error al listar fuentes: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al listar fuentes',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Crear nueva fuente
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'source_type' => 'required|in:facebook,youtube,instagram,twitter,tiktok,manual_list,csv,api',
            'source_config' => 'required|array',
            'interaction_type' => 'nullable|in:comment,like,share,subscribe,follow,any',
            'filters' => 'nullable|array',
            'cache_ttl_hours' => 'nullable|integer|min:1|max:72',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            // Validar configuración según el tipo
            $provider = $this->sourceManager->getProvider($request->source_type);

            if (!$provider->validateConfig($request->source_config)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Configuración inválida para el tipo de fuente seleccionado',
                ], Response::HTTP_BAD_REQUEST);
            }

            $source = Source::create([
                'user_id' => auth('api')->id(),
                'name' => $request->name,
                'description' => $request->description,
                'source_type' => $request->source_type,
                'source_config' => $request->source_config,
                'interaction_type' => $request->interaction_type ?? 'comment',
                'filters' => $request->filters,
                'cache_ttl_hours' => $request->cache_ttl_hours ?? 6,
                'status' => 'active',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Fuente creada exitosamente',
                'source' => $source,
            ], Response::HTTP_CREATED);

        } catch (Exception $e) {
            Log::error('Error al crear fuente: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al crear fuente',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Ver detalles de una fuente
     */
    public function show($id)
    {
        try {
            $source = Source::with(['user', 'winners'])
                ->where('user_id', auth('api')->id())
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'source' => $source,
                'has_fresh_cache' => $source->hasFreshCache(),
                'cache_expires_in_minutes' => $source->cache_expires_at
                    ? now()->diffInMinutes($source->cache_expires_at, false)
                    : null,
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Error al obtener fuente: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Fuente no encontrada',
                'error' => $e->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Actualizar fuente
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'filters' => 'nullable|array',
            'cache_ttl_hours' => 'nullable|integer|min:1|max:72',
            'status' => 'sometimes|in:active,completed,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $source = Source::where('user_id', auth('api')->id())->findOrFail($id);

            $source->update($request->only([
                'name',
                'description',
                'filters',
                'cache_ttl_hours',
                'status',
            ]));

            // Si cambió los filtros, limpiar cache para forzar re-filtrado
            if ($request->has('filters')) {
                $source->clearCache();
            }

            return response()->json([
                'success' => true,
                'message' => 'Fuente actualizada exitosamente',
                'source' => $source->fresh(),
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Error al actualizar fuente: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar fuente',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Eliminar fuente
     */
    public function destroy($id)
    {
        try {
            $source = Source::where('user_id', auth('api')->id())->findOrFail($id);

            $source->clearCache();
            $source->delete();

            return response()->json([
                'success' => true,
                'message' => 'Fuente eliminada exitosamente',
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Error al eliminar fuente: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar fuente',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Sincronizar participantes desde la fuente
     */
    public function sync($id, Request $request)
    {
        Log::info('Sincronizacion',$request->all());

        try {
            $source = Source::where('user_id', auth('api')->id())->findOrFail($id);

            // Compatibilidad: aceptar tanto `force_refresh` como `force` en el body/query
            $forceRefresh = $request->boolean('force_refresh', $request->boolean('force', false));

            // Permitir sobreescribir el interaction_type en la llamada a sync
            $overrideInteractionType = $request->input('interaction_type', null);

            $participants = $this->sourceManager->syncParticipants($source, $forceRefresh, $overrideInteractionType);

            return response()->json([
                'success' => true,
                'message' => 'Participantes sincronizados exitosamente',
                'stats' => [
                    'total_raw_participants' => $source->total_raw_participants,
                    'total_valid_participants' => $source->total_valid_participants,
                    'participants_count' => count($participants),
                    'cache_expires_at' => $source->cache_expires_at,
                    'used_cache' => !$forceRefresh && $source->hasFreshCache(),
                    'force' => $forceRefresh,
                    'requested_interaction_type' => $overrideInteractionType ?? $source->interaction_type,
                ],
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Error al sincronizar: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al sincronizar participantes',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Obtener participantes (con filtros opcionales en runtime)
     */
    public function participants($id, Request $request)
    {
        try {
            $source = Source::where('user_id', auth('api')->id())->findOrFail($id);

            // Filtros adicionales en runtime
            $runtimeFilters = [];
            if ($request->has('search')) {
                $runtimeFilters['search'] = $request->search;
            }
            if ($request->has('interaction_type')) {
                $runtimeFilters['interaction_type'] = $request->interaction_type;
            }
            if ($request->has('verified')) {
                $runtimeFilters['verified'] = $request->boolean('verified');
            }

            $participants = $this->sourceManager->getFilteredParticipants($source, $runtimeFilters);

            // Paginación manual
            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 20);
            $total = count($participants);
            $offset = ($page - 1) * $perPage;
            $paginated = array_slice($participants, $offset, $perPage);

            return response()->json([
                'success' => true,
                'participants' => $paginated,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => ceil($total / $perPage),
                ],
                'cache_info' => [
                    'has_fresh_cache' => $source->hasFreshCache(),
                    'cache_expires_at' => $source->cache_expires_at,
                    'last_sync_at' => $source->last_sync_at,
                ],
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Error al obtener participantes: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener participantes',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Obtener estadísticas de la fuente
     */
    public function stats($id)
    {
        try {
            $source = Source::where('user_id', auth('api')->id())->findOrFail($id);

            $stats = $this->sourceManager->getSourceStats($source);

            return response()->json([
                'success' => true,
                'stats' => array_merge($stats, [
                    'total_valid_participants' => $source->total_valid_participants,
                    'total_winners' => $source->winners()->count(),
                    'last_sync_at' => $source->last_sync_at,
                ]),
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Error al obtener estadísticas: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Limpiar cache de una fuente
     */
    public function clearCache($id)
    {
        try {
            $source = Source::where('user_id', auth('api')->id())->findOrFail($id);

            $source->clearCache();

            return response()->json([
                'success' => true,
                'message' => 'Cache limpiado exitosamente',
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Error al limpiar cache: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al limpiar cache',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
