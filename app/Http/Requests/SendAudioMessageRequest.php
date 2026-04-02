<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendAudioMessageRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'audio' => ['required', 'file', 'mimes:webm,ogg,mp3,wav,m4a', 'max:10240'],
        ];
    }
}
