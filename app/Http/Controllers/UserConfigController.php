<?php

namespace App\Http\Controllers;

use App\Models\UserConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class UserConfigController extends Controller
{
    public function show(Request $request, string $key)
    {
        $config = UserConfig::query()
            ->where('user_id', auth('api')->id())
            ->where('config_key', $key)
            ->first();

        if (!$config) {
            return response()->json([
                'success' => false,
                'message' => 'Configuración no encontrada',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'success' => true,
            'key' => $config->config_key,
            'config' => $config->payload,
        ], Response::HTTP_OK);
    }

    public function upsert(Request $request, string $key)
    {
        $validator = Validator::make($request->all(), [
            'config' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $config = UserConfig::updateOrCreate(
            [
                'user_id' => auth('api')->id(),
                'config_key' => $key,
            ],
            [
                'payload' => $request->input('config'),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Configuración guardada correctamente',
            'key' => $config->config_key,
            'config' => $config->payload,
        ], Response::HTTP_OK);
    }
}
