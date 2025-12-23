<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuestionResource\Pages;
use App\Models\Question;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class QuestionResource extends Resource
{
    protected static ?string $model = Question::class;

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return 'heroicon-o-question-mark-circle';
    }

    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Question Bank';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Question Details')
                    ->description('Enter the clinical scenario and question')
                    ->schema([
                        TextInput::make('question_number')
                            ->label('Question Number')
                            ->numeric()
                            ->required()
                            ->helperText('Order within the condition (e.g., 1, 2, 3)')
                            ->minValue(1),
                        Textarea::make('stem')
                            ->label('Question Stem / Clinical Vignette')
                            ->required()
                            ->rows(5)
                            ->columnSpanFull()
                            ->helperText('Enter the clinical scenario and question text'),
                    ])
                    ->columns(2),

                Section::make('Answer Options')
                    ->description('Enter all 5 answer options')
                    ->schema([
                        Textarea::make('option_a')
                            ->label('Option A')
                            ->required()
                            ->rows(2),
                        Textarea::make('option_b')
                            ->label('Option B')
                            ->required()
                            ->rows(2),
                        Textarea::make('option_c')
                            ->label('Option C')
                            ->required()
                            ->rows(2),
                        Textarea::make('option_d')
                            ->label('Option D')
                            ->required()
                            ->rows(2),
                        Textarea::make('option_e')
                            ->label('Option E')
                            ->required()
                            ->rows(2),
                        Select::make('correct_option')
                            ->label('Correct Answer')
                            ->options([
                                'A' => 'Option A',
                                'B' => 'Option B',
                                'C' => 'Option C',
                                'D' => 'Option D',
                                'E' => 'Option E',
                            ])
                            ->required()
                            ->native(false),
                    ])
                    ->columns(2),

                Section::make('Explanation & Guidelines')
                    ->description('Provide detailed explanation and guideline references')
                    ->schema([
                        Textarea::make('explanation')
                            ->label('Answer Explanation')
                            ->required()
                            ->rows(6)
                            ->columnSpanFull()
                            ->helperText('Explain why the correct answer is right and why others are wrong. Include rationale for correct answer and other options.'),
                        Textarea::make('guideline_reference')
                            ->label('Guideline Reference')
                            ->rows(2)
                            ->columnSpanFull()
                            ->helperText('Full guideline reference text (e.g., "NICE MI (STEMI) QS68: Offer immediate fibrinolysis if PPCI delay >120 min")'),
                        Textarea::make('guideline_excerpt')
                            ->label('Guideline Excerpt')
                            ->rows(3)
                            ->columnSpanFull()
                            ->helperText('Relevant text excerpt from official medical guidelines'),
                        TextInput::make('guideline_source')
                            ->label('Guideline Source Name')
                            ->placeholder('e.g., NICE CG95, RCEM Guidelines')
                            ->maxLength(255),
                        TextInput::make('guideline_url')
                            ->label('Guideline URL')
                            ->url()
                            ->placeholder('https://www.nice.org.uk/guidance/...')
                            ->maxLength(500)
                            ->columnSpanFull()
                            ->helperText('Full URL to the guideline document'),
                    ])
                    ->columns(2),

                Section::make('Classification')
                    ->description('Categorize the question')
                    ->schema([
                        TextInput::make('clinical_presentation')
                            ->label('Clinical Presentation')
                            ->placeholder('e.g., CP1. Chest pain, CP2. Breathlessness')
                            ->maxLength(50)
                            ->helperText('Clinical presentation code (e.g., CP1, CP2)'),
                        TextInput::make('condition_code')
                            ->label('Condition Code')
                            ->placeholder('e.g., CC1. Acute Coronary Syndromes')
                            ->maxLength(50)
                            ->helperText('Condition/issue code (e.g., CC1, CC2)'),
                        TextInput::make('topic')
                            ->label('Main Topic')
                            ->required()
                            ->placeholder('e.g., Cardiology, Respiratory, Trauma')
                            ->maxLength(255)
                            ->datalist([
                                'Cardiology',
                                'Respiratory',
                                'Neurology',
                                'Trauma',
                                'Paediatrics',
                                'Toxicology',
                                'Infectious Disease',
                                'Gastroenterology',
                                'Renal',
                                'Endocrine',
                                'Haematology',
                                'Dermatology',
                                'Musculoskeletal',
                                'Psychiatry',
                                'Obstetrics & Gynaecology',
                                'ENT',
                                'Ophthalmology',
                                'Environmental',
                                'Resuscitation',
                            ]),
                        TextInput::make('subtopic')
                            ->label('Sub-topic')
                            ->placeholder('e.g., ACS, STEMI, Arrhythmias')
                            ->maxLength(255),
                        Select::make('difficulty')
                            ->label('Difficulty Level')
                            ->options([
                                'Easy' => 'Easy',
                                'Medium' => 'Medium',
                                'Hard' => 'Hard',
                            ])
                            ->default('Medium')
                            ->required()
                            ->native(false),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->helperText('Inactive questions will not appear in exams')
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make('References')
                    ->description('Add multiple reference sources')
                    ->schema([
                        Repeater::make('references')
                            ->label('References')
                            ->schema([
                                TextInput::make('title')
                                    ->label('Title')
                                    ->required()
                                    ->placeholder('e.g., NICE MI (STEMI) QS68')
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                                TextInput::make('url')
                                    ->label('URL')
                                    ->url()
                                    ->placeholder('https://www.nice.org.uk/guidance/...')
                                    ->maxLength(500)
                                    ->columnSpanFull(),
                            ])
                            ->itemLabel(function (array $state): ?string {
                                // Show the title if available, otherwise just "Reference"
                                return !empty($state['title']) ? $state['title'] : 'Reference';
                            })
                            ->addActionLabel('Add Reference')
                            ->defaultItems(0)
                            ->collapsible()
                            ->reorderable()
                            ->collapsed(false),
                    ]),

                Section::make('Media')
                    ->description('Add images or ECG references if applicable')
                    ->schema([
                        Toggle::make('has_image')
                            ->label('Has Image')
                            ->helperText('Check if this question includes an image or ECG')
                            ->default(false)
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set) {
                                if (!$state) {
                                    $set('image_url', null);
                                }
                            }),
                        TextInput::make('image_url')
                            ->label('Image/ECG URL')
                            ->url()
                            ->placeholder('https://example.com/image.jpg')
                            ->maxLength(500)
                            ->helperText('URL to question image, ECG, or diagram')
                            ->visible(fn($get) => $get('has_image'))
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->compact(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('stem')
                    ->label('Question')
                    ->limit(60)
                    ->searchable()
                    ->tooltip(fn(Question $record): string => $record->stem),
                TextColumn::make('topic')
                    ->label('Topic')
                    ->sortable()
                    ->searchable()
                    ->badge()
                    ->color('primary'),
                TextColumn::make('subtopic')
                    ->label('Subtopic')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('clinical_presentation')
                    ->label('Clinical Presentation')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('condition_code')
                    ->label('Condition Code')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('has_image')
                    ->label('Has Image')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('correct_option')
                    ->label('Answer')
                    ->badge()
                    ->color('success'),
                TextColumn::make('difficulty')
                    ->label('Difficulty')
                    ->sortable()
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'Easy' => 'success',
                        'Medium' => 'warning',
                        'Hard' => 'danger',
                        default => 'gray',
                    }),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('guideline_source')
                    ->label('Guideline')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('topic')
                    ->options(fn() => Question::distinct()->pluck('topic', 'topic')->toArray()),
                SelectFilter::make('clinical_presentation')
                    ->label('Clinical Presentation')
                    ->options(fn() => Question::distinct()->whereNotNull('clinical_presentation')->pluck('clinical_presentation', 'clinical_presentation')->toArray()),
                SelectFilter::make('condition_code')
                    ->label('Condition Code')
                    ->options(fn() => Question::distinct()->whereNotNull('condition_code')->pluck('condition_code', 'condition_code')->toArray()),
                SelectFilter::make('difficulty')
                    ->options([
                        'Easy' => 'Easy',
                        'Medium' => 'Medium',
                        'Hard' => 'Hard',
                    ]),
                TernaryFilter::make('is_active')
                    ->label('Active Status')
                    ->placeholder('All Questions')
                    ->trueLabel('Active Only')
                    ->falseLabel('Inactive Only'),
                TernaryFilter::make('has_image')
                    ->label('Has Image')
                    ->placeholder('All Questions')
                    ->trueLabel('With Images')
                    ->falseLabel('Without Images'),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    BulkAction::make('activate')
                        ->label('Activate Selected')
                        ->icon('heroicon-o-check-circle')
                        ->action(fn($records) => $records->each->update(['is_active' => true]))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deactivate')
                        ->label('Deactivate Selected')
                        ->icon('heroicon-o-x-circle')
                        ->action(fn($records) => $records->each->update(['is_active' => false]))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('id', 'asc')
            ->paginated([10, 25, 50, 100, 200, 500, -1])
            ->defaultPaginationPageOption(50);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuestions::route('/'),
            'create' => Pages\CreateQuestion::route('/create'),
            'view' => Pages\ViewQuestion::route('/{record}'),
            'edit' => Pages\EditQuestion::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $count = static::getModel()::count();
        return $count < 250 ? 'warning' : 'success';
    }
}
