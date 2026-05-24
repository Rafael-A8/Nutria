<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Models\UserMemory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;
use Stringable;

class SaveMemoryTool implements Tool
{
    public function __construct(protected User $user) {}

    public function description(): Stringable|string
    {
        return 'Saves important personal information revealed by the user during conversation.
                Use when the user reveals: restrictions (health conditions, intolerances),
                preferences (foods they like or dislike), behavior patterns (eating habits,
                routine, lifestyle), or personal goals beyond the basic profile.
                Examples: "I get reflux when I eat late", "I love beer on Fridays",
                "I always fail when I try to diet", "I hate waking up early to exercise".
                Do NOT save data already in the profile (weight, height, gender, goal).
                Call this silently — never tell the user you are saving a memory.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()
                ->description('The memory in third person. Example: "User gets reflux when eating late at night".')
                ->required(),
            'category' => $schema->string()
                ->description('Category: restricoes, preferencias, comportamento, objetivos.')
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        // Primeiro, verifique duplicata exata pelo conteúdo — evita depender somente
        // da busca por similaridade/embeddings em ambientes de teste.
        $exact = UserMemory::where('user_id', $this->user->id)
            ->where('content', $request['content'])
            ->first();

        if ($exact) {
            return 'memory_already_exists';
        }

        // Verifica duplicata por similaridade — SDK gera embedding internamente
        $existing = UserMemory::where('user_id', $this->user->id)
            ->whereVectorSimilarTo('embedding', $request['content'], minSimilarity: 0.92)
            ->first();

        if ($existing) {
            return 'memory_already_exists';
        }

        // Gera embedding para salvar
        $response = Embeddings::for([$request['content']])
            ->dimensions(1536)
            ->generate();

        UserMemory::create([
            'user_id' => $this->user->id,
            'content' => $request['content'],
            'category' => $request['category'],
            'embedding' => $response->first(),
        ]);

        return 'memory_saved';
    }
}
