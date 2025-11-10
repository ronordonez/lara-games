<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Models\Winner;
use App\Services\SourceManager;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class RaffleController extends Controller
{
    protected $sourceManager;

    public function __construct(SourceManager $sourceManager)
    {
        $this->sourceManager = $sourceManager;
    }

    /**
     * Realizar sorteo y guardar ganador(es)
     */
    public function draw(Request $request, $sourceId)
    {
        $validator = Validator::make($request->all(), [
            'winners_count' => 'nullable|integer|min:1|max:100',
            'filters' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $source = Source::where('user_id', auth('api')->id())->findOrFail($sourceId);

            if ($source->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'La fuente debe estar activa para realizar sorteos',
                ], Response::HTTP_BAD_REQUEST);
            }

            $winnersCount = $request->input('winners_count', 1);
            $runtimeFilters = $request->input('filters', []);

            // Obtener participantes filtrados
            $participants = $this->sourceManager->getFilteredParticipants($source, $runtimeFilters);

            if (empty($participants)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay participantes válidos para el sorteo',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Realizar sorteo
            $selectedWinners = $this->sourceManager->drawWinners($source, $winnersCount, $runtimeFilters);

            // Guardar ganadores en BD
            $savedWinners = [];

            DB::beginTransaction();
            try {
                foreach ($selectedWinners as $index => $winner) {
                    $savedWinner = Winner::create([
                        'user_id' => auth('api')->id(),
                        'source_id' => $source->id,
                        'winner_name' => $winner['name'],
                        'winner_identifier' => $winner['identifier'],
                        'winner_profile_url' => $winner['profile_url'],
                        'winner_avatar' => $winner['avatar'],
                        'winner_contact' => null,
                        'winner_content' => $winner['content'],
                        'winner_metadata' => [
                            'interaction_type' => $winner['interaction_type'],
                            'interaction_date' => $winner['interaction_date'],
                            'likes_count' => $winner['likes_count'],
                            'follower_count' => $winner['follower_count'],
                            'verified' => $winner['verified'],
                            'metadata' => $winner['metadata'] ?? [],
                        ],
                        'total_participants_at_draw' => count($participants),
                        'filters_applied' => array_merge($source->filters ?? [], $runtimeFilters),
                        'notes' => $request->input('notes'),
                        'raffle_method' => 'random',
                        'position' => $index + 1,
                    ]);

                    $savedWinners[] = $savedWinner;
                }

                DB::commit();

                // Opcional: Marcar fuente como completada si se desea
                if ($request->boolean('complete_source', false)) {
                    $source->update(['status' => 'completed']);
                }

            } catch (Exception $e) {
                DB::rollBack();
                throw $e;
            }

            return response()->json([
                'success' => true,
                'message' => 'Sorteo realizado exitosamente',
                'winners' => $savedWinners,
                'stats' => [
                    'total_participants' => count($participants),
                    'winners_selected' => count($savedWinners),
                ],
            ], Response::HTTP_CREATED);

        } catch (Exception $e) {
            Log::error('Error al realizar sorteo: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al realizar sorteo',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Listar ganadores de una fuente
     */
    public function winners($sourceId)
    {
        try {
            $source = Source::where('user_id', auth('api')->id())->findOrFail($sourceId);

            $winners = Winner::where('source_id', $source->id)
                ->orderBy('created_at', 'desc')
                ->orderBy('position', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'winners' => $winners,
                'total' => $winners->count(),
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Error al listar ganadores: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al listar ganadores',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Listar todos los ganadores del usuario
     */
    public function index(Request $request)
    {
        try {
            $query = Winner::with(['source'])
                ->where('user_id', auth('api')->id());

            // Filtrar por fuente
            if ($request->has('source_id')) {
                $query->where('source_id', $request->source_id);
            }

            // Filtrar por fecha
            if ($request->has('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->has('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            $winners = $query->orderBy('created_at', 'desc')->paginate(20);

            return response()->json([
                'success' => true,
                'winners' => $winners,
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Error al listar ganadores: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al listar ganadores',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Ver detalles de un ganador
     */
    public function show($id)
    {
        try {
            $winner = Winner::with(['source', 'user'])
                ->where('user_id', auth('api')->id())
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'winner' => $winner,
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Error al obtener ganador: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ganador no encontrado',
                'error' => $e->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Actualizar notas de un ganador
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string',
            'winner_contact' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $winner = Winner::where('user_id', auth('api')->id())->findOrFail($id);

            $winner->update($request->only(['notes', 'winner_contact']));

            return response()->json([
                'success' => true,
                'message' => 'Ganador actualizado exitosamente',
                'winner' => $winner->fresh(),
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Error al actualizar ganador: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar ganador',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Eliminar un ganador
     */
    public function destroy($id)
    {
        try {
            $winner = Winner::where('user_id', auth('api')->id())->findOrFail($id);

            $winner->delete();

            return response()->json([
                'success' => true,
                'message' => 'Ganador eliminado exitosamente',
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            Log::error('Error al eliminar ganador: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar ganador',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
