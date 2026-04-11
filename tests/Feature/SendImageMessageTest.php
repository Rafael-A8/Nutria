<?php

use App\Ai\Agents\NutritionistAgent;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    $this->user = User::factory()->create();
});

it('requires at least one image', function () {
    $this->actingAs($this->user)
        ->postJson('/chat/send-image', ['message' => 'test'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('images');
});

it('validates image file types', function () {
    $this->actingAs($this->user)
        ->postJson('/chat/send-image', [
            'images' => [UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('images.0');
});

it('limits to max 5 images', function () {
    $images = [];
    for ($i = 0; $i < 6; $i++) {
        $images[] = UploadedFile::fake()->image("photo{$i}.jpg", 100, 100);
    }

    $this->actingAs($this->user)
        ->postJson('/chat/send-image', ['images' => $images])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('images');
});

it('sends image message and stores images', function () {
    NutritionistAgent::fake(['Analisei sua refeição.']);

    $images = [
        UploadedFile::fake()->image('almoco.jpg', 400, 300),
        UploadedFile::fake()->image('sobremesa.png', 200, 200),
    ];

    $response = $this->actingAs($this->user)
        ->postJson('/chat/send-image', [
            'message' => 'Almocei isso',
            'images' => $images,
        ])
        ->assertOk()
        ->assertJsonStructure(['reply', 'imagePaths']);

    expect($response->json('reply'))->toBe('Analisei sua refeição.');
    expect($response->json('imagePaths'))->toHaveCount(2);

    foreach ($response->json('imagePaths') as $path) {
        Storage::disk('public')->assertExists($path);
    }

    $this->assertDatabaseHas('chat_messages', [
        'user_id' => $this->user->id,
        'role' => 'user',
        'content' => 'Almocei isso',
    ]);

    $this->assertDatabaseHas('chat_messages', [
        'user_id' => $this->user->id,
        'role' => 'assistant',
        'content' => 'Analisei sua refeição.',
    ]);
});

it('sends image without text', function () {
    NutritionistAgent::fake(['Vejo arroz e feijão.']);

    $response = $this->actingAs($this->user)
        ->postJson('/chat/send-image', [
            'images' => [UploadedFile::fake()->image('prato.jpg', 400, 300)],
        ])
        ->assertOk();

    expect($response->json('reply'))->toBe('Vejo arroz e feijão.');

    $userMessage = ChatMessage::where('user_id', $this->user->id)
        ->where('role', 'user')
        ->first();

    expect($userMessage->content)->toBe('Enviou 1 imagem');
    expect($userMessage->image_paths)->toHaveCount(1);
});

it('requires authentication', function () {
    $this->postJson('/chat/send-image', [
        'images' => [UploadedFile::fake()->image('test.jpg')],
    ])->assertUnauthorized();
});
