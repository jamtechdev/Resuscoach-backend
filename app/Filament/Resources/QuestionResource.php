<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuestionResource\Pages;
use App\Models\Question;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
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
                        Textarea::make('stem')
                            ->label('Question Stem / Clinical Vignette')
                            ->required()
                            ->rows(5)
                            ->columnSpanFull()
                            ->helperText('Enter the clinical scenario and question text'),
                    ]),

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
                            ->rows(4)
                            ->columnSpanFull()
                            ->helperText('Explain why the correct answer is right and why others are wrong'),
                        Textarea::make('guideline_excerpt')
                            ->label('Guideline Excerpt')
                            ->rows(3)
                            ->columnSpanFull()
                            ->helperText('Relevant text from official medical guidelines'),
                        TextInput::make('guideline_source')
                            ->label('Guideline Source')
                            ->placeholder('e.g., NICE CG95, RCEM Guidelines')
                            ->maxLength(255),
                    ]),

                Section::make('Classification')
                    ->description('Categorize the question')
                    ->schema([
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
                    ->tooltip(fn (Question $record): string => $record->stem),
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
                TextColumn::make('correct_option')
                    ->label('Answer')
                    ->badge()
                    ->color('success'),
                TextColumn::make('difficulty')
                    ->label('Difficulty')
                    ->sortable()
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
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
                    ->options(fn () => Question::distinct()->pluck('topic', 'topic')->toArray()),
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
                        ->action(fn ($records) => $records->each->update(['is_active' => true]))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deactivate')
                        ->label('Deactivate Selected')
                        ->icon('heroicon-o-x-circle')
                        ->action(fn ($records) => $records->each->update(['is_active' => false]))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('id', 'desc');
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
