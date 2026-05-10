<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Services\WeightLogService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class RegisterWeightTool implements Tool
{
    public function __construct(protected User $user) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Registers the user\'s weight. Use when the user provides their current weight.';
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'weight_kg' => $schema->number()->description('Weight in kilograms.')->required(),
        ];
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $service = new WeightLogService;
        $weightLog = $service->log($this->user, $request['weight_kg']);

        return "Peso registrado: {$weightLog->weight_kg} kg em {$weightLog->logged_at->format('d/m/Y H:i')}.";
    }
}
