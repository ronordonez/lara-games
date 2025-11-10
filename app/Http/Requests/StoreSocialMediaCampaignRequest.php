<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;

class StoreSocialMediaCampaignRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'platform' => ['required', 'in:facebook,youtube,instagram,twitter,tiktok'],
            'post_id' => ['required', 'string'],
            'post_url' => ['nullable', 'url', 'max:500'],
            'hashtags' => ['nullable', 'array'],
            'hashtags.*' => ['string', 'max:100'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la campaña es obligatorio.',
            'name.max' => 'El nombre no puede tener más de 255 caracteres.',

            'platform.required' => 'La plataforma es obligatoria.',
            'platform.in' => 'La plataforma debe ser: facebook, youtube, instagram, twitter o tiktok.',

            'post_id.required' => 'El ID de la publicación es obligatorio.',

            'post_url.url' => 'La URL de la publicación debe ser válida.',
            'post_url.max' => 'La URL no puede tener más de 500 caracteres.',

            'hashtags.array' => 'Los hashtags deben ser un arreglo.',
            'hashtags.*.string' => 'Cada hashtag debe ser una cadena de texto.',
            'hashtags.*.max' => 'Cada hashtag no puede tener más de 100 caracteres.',
        ];
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY)
        );
    }
}
