<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RevisionQuestionResource extends JsonResource
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
            'scenario' => $this->scenario,
            'stem' => $this->stem,
            'options' => [
                'A' => $this->option_a,
                'B' => $this->option_b,
                'C' => $this->option_c,
                'D' => $this->option_d,
                'E' => $this->option_e,
            ],
            'topic' => $this->topic,
            'subtopic' => $this->subtopic,
            'difficulty' => $this->difficulty,
            'clinical_presentation' => $this->clinical_presentation,
            'condition_code' => $this->condition_code,
            'image_url' => $this->image_url,
            'has_image' => $this->has_image,
        ];
    }
}
