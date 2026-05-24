<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Services\WeightLogService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class UpdateProfileTool implements Tool
{
    public function __construct(protected User $user) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Updates the user\'s nutritional profile (gender, birth date, height, goal, activity level). Use when the user provides personal data like height, gender, goal or activity level. If the user provides weight, use register_weight instead of this tool.';
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'gender' => $schema->string()->description('User gender: masculino, feminino or outro.')->required(),
            'birth_date' => $schema->string()->description('Birth date in YYYY-MM-DD format.')->required(),
            'height_cm' => $schema->integer()->description('Height in centimeters (e.g.: 175).')->required(),
            'goal' => $schema->string()->description('Goal: perder_peso, manter_peso, ganhar_massa.')->required(),
            'activity_level' => $schema->string()->description('Activity level: sedentario, leve, moderado, ativo, muito_ativo.')->required(),
            'weight_kg' => $schema->number()->description('Current weight in kg. If provided, it will be logged in the weight history.')->required(),
        ];
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $profileData = array_filter([
            'gender' => $request['gender'] ?? null,
            'birth_date' => $request['birth_date'] ?? null,
            'height_cm' => $request['height_cm'] ?? null,
            'goal' => $request['goal'] ?? null,
            'activity_level' => $request['activity_level'] ?? null,
        ]);

        if (! empty($profileData)) {
            $this->user->profile()->updateOrCreate(
                ['user_id' => $this->user->id],
                $profileData,
            );
        }

        $weightKg = $request['weight_kg'] ?? null;
        if ($weightKg) {
            $weightLogService = new WeightLogService;
            $weightLogService->log($this->user, (float) $weightKg);
        }

        $updated = array_keys($profileData);
        if ($weightKg) {
            $updated[] = 'peso';
        }

        $this->user->refresh();

        return 'Perfil atualizado: '.implode(', ', $updated).'.';
    }
}
