<?php

namespace App\Filament\Resources\QuestionResource\Pages;

use App\Filament\Resources\QuestionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateQuestion extends CreateRecord
{
    protected static string $resource = QuestionResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (!empty($data['image_upload'])) {
            // Store a relative path so URLs remain valid across environments.
            $data['image_url'] = 'storage/' . ltrim($data['image_upload'], '/');
        }
        unset($data['image_upload']);
        return $data;
    }
}
