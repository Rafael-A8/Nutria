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
        return 'Atualiza o perfil nutricional do usuário (gênero, data de nascimento, altura, objetivo, nível de atividade). Use quando o usuário informar dados pessoais como altura, gênero, objetivo ou nível de atividade. Se o usuário informar o peso, use register_weight em vez desta tool.';
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'gender' => $schema->string()->description('Gênero do usuário: masculino, feminino ou outro.'),
            'birth_date' => $schema->string()->description('Data de nascimento no formato YYYY-MM-DD.'),
            'height_cm' => $schema->integer()->description('Altura em centímetros (ex: 175).'),
            'goal' => $schema->string()->description('Objetivo: perder_peso, manter_peso, ganhar_massa.'),
            'activity_level' => $schema->string()->description('Nível de atividade: sedentario, leve, moderado, ativo, muito_ativo.'),
            'weight_kg' => $schema->number()->description('Peso atual em kg. Se informado, será registrado no histórico de peso.'),
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
