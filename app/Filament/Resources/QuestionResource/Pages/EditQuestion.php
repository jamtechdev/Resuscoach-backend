<?php

namespace App\Filament\Resources\QuestionResource\Pages;

use App\Filament\Resources\QuestionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;

class EditQuestion extends EditRecord
{
    protected static string $resource = QuestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $imageUrl = $data['image_url'] ?? null;
        if (is_string($imageUrl) && trim($imageUrl) !== '') {
            $path = $imageUrl;

            // Support old absolute URLs: https://domain/storage/question-images/file.jpg
            if (Str::startsWith($path, ['http://', 'https://'])) {
                $parsedPath = (string) parse_url($path, PHP_URL_PATH);
                if ($parsedPath !== '') {
                    $path = ltrim($parsedPath, '/');
                }
            }

            // FileUpload (disk: public) expects a path relative to storage/app/public
            if (Str::startsWith($path, '/storage/')) {
                $path = ltrim($path, '/');
            }
            if (Str::startsWith($path, 'storage/')) {
                $path = Str::after($path, 'storage/');
            }

            $data['image_upload'] = $path;
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (!empty($data['image_upload'])) {
            // Store a relative path so URLs remain valid across environments.
            $data['image_url'] = 'storage/' . ltrim($data['image_upload'], '/');
        }
        unset($data['image_upload']);
        return $data;
    }
}
