<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamQuestionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scenario' => $this->scenario, // Clinical scenario/vignette (optional)
            'stem' => $this->stem, // The actual question text
            'options' => [
                'A' => $this->option_a,
                'B' => $this->option_b,
                'C' => $this->option_c,
                'D' => $this->option_d,
                'E' => $this->option_e,
            ],
            'has_image' => $this->has_image,
            'image_url' => $this->image_url,
            'topic' => $this->topic,
            'subtopic' => $this->subtopic,
            'difficulty' => $this->difficulty,
            // Don't include correct_option or explanation in exam view
        ];
    }
}
