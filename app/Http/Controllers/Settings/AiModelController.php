<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AiModelController extends Controller
{
    /**
     * Available AI model options.
     *
     * @return list<array{value: string, label: string, description: string, icon: string}>
     */
    public static function availableModels(): array
    {
        return [
            [
                'value' => 'gemini-2.0-flash',
                'label' => 'Recomendado',
                'description' => 'Gemini 2.0 Flash — melhor em cálculos e mais barato',
                'icon' => 'brain',
            ],
            [
                'value' => 'gpt-4o-mini',
                'label' => 'Alternativo',
                'description' => 'GPT-4o Mini — estilo de resposta diferente',
                'icon' => 'zap',
            ],
        ];
    }

    /**
     * Show the AI model settings page.
     */
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/AiModel', [
            'currentModel' => $user->profile?->preferred_ai_model ?? 'gemini-2.0-flash',
            'availableModels' => static::availableModels(),
        ]);
    }

    /**
     * Update the user's preferred AI model.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'preferred_ai_model' => ['required', 'string', Rule::in(array_column(static::availableModels(), 'value'))],
        ]);

        /** @var User $user */
        $user = $request->user();

        if ($user->profile) {
            $user->profile->update(['preferred_ai_model' => $validated['preferred_ai_model']]);
        } else {
            $user->profile()->create([
                'gender' => 'não informado',
                'birth_date' => now()->subYears(30)->toDateString(),
                'height_cm' => 170,
                'goal' => 'manter',
                'activity_level' => 'moderado',
                'preferred_ai_model' => $validated['preferred_ai_model'],
            ]);
        }

        return to_route('ai-model.edit');
    }
}
