<?php

namespace App\Core\View;

final class ApiViewMessages
{
    public const SUCCESS = 'SUCCESS';
    public const ENTITY_NOT_FOUND = 'Entity is not found';
    public const INVALID_JSON = 'Invalid JSON';
    public const INVALID_CONTENT_FIELD = 'Invalid content field';
    public const CREATE_FAILED = 'Create failed';
    public const BATCH_UPDATE_ERROR = 'Batch update error';
    public const CONTENT_TYPE_ERROR = 'Content type error.';
    public const TRANSITION_CANNOT_APPLY = 'Current transition cannot be applied.';

    public static function propertyRequired(string $property): string
    {
        return ucfirst($property) . ' is required';
    }

    public static function propertyCannotBeEmpty(string $property): string
    {
        return ucfirst($property) . ' cannot be empty.';
    }
}
