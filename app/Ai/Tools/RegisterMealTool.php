<?php

namespace App\Ai\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class RegisterMealTool implements Tool
{
    public function __construct(protected User $user) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Usado SEMPRE que o usuário relatar que comeu algo. Estime as calorias e registre a refeição no banco.';
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'description' => $schema->string()->description('Descrição detalhada do que foi consumido.')->required(),
            'calories' => $schema->integer()->description('Calorias totais estimadas da refeição.')->required(),
        ];
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $meal = $this->user->meals()->create([
            'description' => $request['description'],
            'calories' => $request['calories'],
            'consumed_at' => Carbon::now(),
        ]);

        return "Refeição registrada com sucesso! ID: {$meal->id}. Descrição: \"{$meal->description}\". Calorias: {$meal->calories} kcal.";
    }
}
