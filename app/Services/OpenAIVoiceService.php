<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIVoiceService
{
    private string $apiKey;

    private string $ttsModel;

    private string $ttsVoice;

    private string $whisperModel;

    private int $ttsCacheTtl;

    private int $connectTimeout;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key');
        $this->ttsModel = config('services.openai.tts_model', 'tts-1');
        $this->ttsVoice = config('services.openai.tts_voice', 'alloy');
        $this->whisperModel = config('services.openai.whisper_model', 'whisper-1');
        $this->ttsCacheTtl = (int) config('services.openai.tts_cache_ttl', 604800); // 7 days
        $this->connectTimeout = (int) config('services.openai.connect_timeout', 10);
    }

    private function ttsCacheKey(string $text): string
    {
        return 'voice_tts:' . md5($text . '|' . $this->ttsVoice . '|' . $this->ttsModel);
    }

    /**
     * Convert text to speech using OpenAI TTS API.
     * Returns raw audio bytes (MP3). Repeated same text is served from cache for instant response.
     */
    public function textToSpeech(string $text): string
    {
        if (empty($this->apiKey)) {
            Log::error('OpenAI API key is not configured');
            throw new \Exception('OpenAI API key is not configured.');
        }

        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('Text cannot be empty.');
        }

        if (mb_strlen($text) > 4096) {
            $text = mb_substr($text, 0, 4096);
        }

        $cacheKey = $this->ttsCacheKey($text);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])
                ->connectTimeout($this->connectTimeout)
                ->timeout(60)
                ->post('https://api.openai.com/v1/audio/speech', [
                    'model' => $this->ttsModel,
                    'input' => $text,
                    'voice' => $this->ttsVoice,
                    'response_format' => 'mp3',
                ]);

            if ($response->failed()) {
                Log::error('OpenAI TTS request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception('Text-to-speech request failed: ' . $response->body());
            }

            $body = $response->body();
            if ($this->ttsCacheTtl > 0) {
                Cache::put($cacheKey, $body, $this->ttsCacheTtl);
            }

            return $body;
        } catch (\Exception $e) {
            Log::error('OpenAI TTS error', ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Transcribe audio to text using OpenAI Whisper API.
     * Accepts file path (string) or Illuminate\Http\UploadedFile.
     */
    public function speechToText(string|\Illuminate\Http\UploadedFile $audioFile): string
    {
        if (empty($this->apiKey)) {
            Log::error('OpenAI API key is not configured');
            throw new \Exception('OpenAI API key is not configured.');
        }

        try {
            if ($audioFile instanceof \Illuminate\Http\UploadedFile) {
                $contents = $audioFile->get();
                $filename = $audioFile->getClientOriginalName() ?: 'audio.mp3';
            } else {
                $contents = file_get_contents($audioFile);
                $filename = pathinfo($audioFile, PATHINFO_BASENAME) ?: 'audio.mp3';
            }

            if ($contents === false || $contents === '') {
                throw new \InvalidArgumentException('Audio file is empty or unreadable.');
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])
                ->connectTimeout($this->connectTimeout)
                ->timeout(60)
                ->attach('file', $contents, $filename)
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => $this->whisperModel,
                    'response_format' => 'json',
                ]);

            if ($response->failed()) {
                Log::error('OpenAI Whisper request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception('Speech-to-text request failed: ' . $response->body());
            }

            $data = $response->json();
            $text = $data['text'] ?? '';

            return trim($text);
        } catch (\Exception $e) {
            Log::error('OpenAI Whisper error', ['message' => $e->getMessage()]);
            throw $e;
        }
    }
}
