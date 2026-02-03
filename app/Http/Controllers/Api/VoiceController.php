<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SpeechToTextRequest;
use App\Http\Requests\TextToSpeechRequest;
use App\Services\OpenAIVoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class VoiceController extends Controller
{
    public function __construct(
        protected OpenAIVoiceService $voiceService
    ) {}

    /**
     * Convert text to speech. Returns MP3 audio.
     */
    public function textToSpeech(TextToSpeechRequest $request): Response|JsonResponse
    {
        try {
            $audio = $this->voiceService->textToSpeech($request->validated('text'));

            return response($audio, 200, [
                'Content-Type' => 'audio/mpeg',
                'Content-Disposition' => 'inline; filename="speech.mp3"',
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Voice TTS failed', ['message' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Text-to-speech failed. Please try again.',
            ], 500);
        }
    }

    /**
     * Transcribe audio to text. Accepts multipart audio file.
     */
    public function speechToText(SpeechToTextRequest $request): JsonResponse
    {
        try {
            $text = $this->voiceService->speechToText($request->file('audio'));

            return response()->json([
                'success' => true,
                'data' => [
                    'text' => $text,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Voice STT failed', ['message' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Speech-to-text failed. Please try again.',
            ], 500);
        }
    }
}
