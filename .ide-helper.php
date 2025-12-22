<?php

/**
 * IDE Helper file for Filament 4 classes
 * This file helps IDEs understand Filament 4 classes and suppresses false positives
 */

namespace Filament\Schemas;

/**
 * @mixin \Illuminate\Support\Collection
 */
class Schema
{
    /**
     * @param array $components
     * @return static
     */
    public function components(array $components): static
    {
        return $this;
    }

    /**
     * @param array $schema
     * @return static
     */
    public function schema(array $schema): static
    {
        return $this;
    }
}

namespace Filament\Forms\Components;

class Section
{
    /**
     * @param string $label
     * @return static
     */
    public static function make(string $label): static
    {
        return new static();
    }

    /**
     * @param array $components
     * @return static
     */
    public function schema(array $components): static
    {
        return $this;
    }

    /**
     * @param int $columns
     * @return static
     */
    public function columns(int $columns): static
    {
        return $this;
    }

    /**
     * @param string $description
     * @return static
     */
    public function description(string $description): static
    {
        return $this;
    }
}

class Select
{
    public function __construct() {}

    /**
     * @param string $name
     * @return static
     */
    public static function make(string $name): static
    {
        return new static();
    }

    /**
     * @param string $name
     * @param string $titleAttribute
     * @return static
     */
    public function relationship(string $name, string $titleAttribute): static
    {
        return $this;
    }

    /**
     * @param array $options
     * @return static
     */
    public function options(array $options): static
    {
        return $this;
    }

    /**
     * @return static
     */
    public function searchable(): static
    {
        return $this;
    }

    /**
     * @return static
     */
    public function preload(): static
    {
        return $this;
    }

    /**
     * @return static
     */
    public function required(): static
    {
        return $this;
    }

    /**
     * @param bool $native
     * @return static
     */
    public function native(bool $native = true): static
    {
        return $this;
    }
}

class TextInput
{
    public function __construct() {}

    /**
     * @param string $name
     * @return static
     */
    public static function make(string $name): static
    {
        return new static();
    }

    /**
     * @return static
     */
    public function numeric(): static
    {
        return $this;
    }

    /**
     * @param mixed $default
     * @return static
     */
    public function default($default): static
    {
        return $this;
    }

    /**
     * @param string $suffix
     * @return static
     */
    public function suffix(string $suffix): static
    {
        return $this;
    }

    /**
     * @param int $maxLength
     * @return static
     */
    public function maxLength(int $maxLength): static
    {
        return $this;
    }

    /**
     * @param string $placeholder
     * @return static
     */
    public function placeholder(string $placeholder): static
    {
        return $this;
    }

    /**
     * @param bool|callable $unique
     * @return static
     */
    public function unique($unique = true): static
    {
        return $this;
    }

    /**
     * @param string $helperText
     * @return static
     */
    public function helperText(string $helperText): static
    {
        return $this;
    }

    /**
     * @param array $datalist
     * @return static
     */
    public function datalist(array $datalist): static
    {
        return $this;
    }
}

class Textarea
{
    public function __construct() {}

    /**
     * @param string $name
     * @return static
     */
    public static function make(string $name): static
    {
        return new static();
    }

    /**
     * @param string $label
     * @return static
     */
    public function label(string $label): static
    {
        return $this;
    }

    /**
     * @return static
     */
    public function required(): static
    {
        return $this;
    }

    /**
     * @param int $rows
     * @return static
     */
    public function rows(int $rows): static
    {
        return $this;
    }

    /**
     * @return static
     */
    public function columnSpanFull(): static
    {
        return $this;
    }

    /**
     * @param string $helperText
     * @return static
     */
    public function helperText(string $helperText): static
    {
        return $this;
    }
}

class Toggle
{
    public function __construct() {}

    /**
     * @param string $name
     * @return static
     */
    public static function make(string $name): static
    {
        return new static();
    }

    /**
     * @param string $label
     * @return static
     */
    public function label(string $label): static
    {
        return $this;
    }

    /**
     * @param string $helperText
     * @return static
     */
    public function helperText(string $helperText): static
    {
        return $this;
    }

    /**
     * @param bool $default
     * @return static
     */
    public function default(bool $default): static
    {
        return $this;
    }
}

class DateTimePicker
{
    public function __construct() {}

    /**
     * @param string $name
     * @return static
     */
    public static function make(string $name): static
    {
        return new static();
    }

    /**
     * @return static
     */
    public function required(): static
    {
        return $this;
    }
}

namespace Filament\Tables\Actions;

class ViewAction
{
    /**
     * @return static
     */
    public static function make(): static
    {
        return new static();
    }
}

class EditAction
{
    /**
     * @return static
     */
    public static function make(): static
    {
        return new static();
    }
}

class DeleteAction
{
    /**
     * @return static
     */
    public static function make(): static
    {
        return new static();
    }
}

class BulkActionGroup
{
    /**
     * @param array $actions
     * @return static
     */
    public static function make(array $actions): static
    {
        return new static();
    }
}

class DeleteBulkAction
{
    /**
     * @return static
     */
    public static function make(): static
    {
        return new static();
    }
}

class BulkAction
{
    /**
     * @param string $name
     * @return static
     */
    public static function make(string $name): static
    {
        return new static();
    }

    /**
     * @param string $label
     * @return static
     */
    public function label(string $label): static
    {
        return $this;
    }

    /**
     * @param string $icon
     * @return static
     */
    public function icon(string $icon): static
    {
        return $this;
    }

    /**
     * @param callable $action
     * @return static
     */
    public function action(callable $action): static
    {
        return $this;
    }

    /**
     * @return static
     */
    public function deselectRecordsAfterCompletion(): static
    {
        return $this;
    }
}
