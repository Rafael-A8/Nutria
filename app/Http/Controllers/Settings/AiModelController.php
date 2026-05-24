<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AiModel;
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
        return AiModel::options();
    }

    /**
     * Show the AI model settings page.
     */
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/AiModel', [
            'currentModel' => $user->profile?->preferred_ai_model ?? AiModel::default()->value,
            'availableModels' => static::availableModels(),
        ]);
    }

    /**
     * Update the user's preferred AI model.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'preferred_ai_model' => ['required', 'string', Rule::in(array_column(AiModel::options(), 'value'))],
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
