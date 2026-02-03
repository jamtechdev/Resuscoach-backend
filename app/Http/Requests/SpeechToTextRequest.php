<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SpeechToTextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'audio' => ['required', 'file', 'mimes:mp3,mp4,mpeg,mpga,m4a,wav,webm,ogg,flac', 'max:26214400'], // 25MB
        ];
    }

    public function messages(): array
    {
        return [
            'audio.required' => 'Audio file is required.',
            'audio.file' => 'The uploaded file is invalid.',
            'audio.mimes' => 'Audio must be mp3, mp4, mpeg, mpga, m4a, wav, webm, ogg, or flac.',
            'audio.max' => 'Audio file must not exceed 25 MB.',
        ];
    }
}
