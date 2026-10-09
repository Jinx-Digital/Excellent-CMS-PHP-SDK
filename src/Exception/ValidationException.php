<?php

declare(strict_types=1);

namespace ExcellentCms\Sdk\Exception;

/**
 * 422 – a field, filter or sort does not fit the schema. `errors()` lists the messages per field.
 */
final class ValidationException extends ApiException
{
    /**
     * Messages per field, e.g. ["title" => ["Bitte ausfüllen."], "filter.country.name" => [...]].
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        $errors = [];
        foreach ($this->errorData() ?? [] as $field => $messages) {
            $errors[(string)$field] = array_values(array_map(strval(...), (array)$messages));
        }
        return $errors;
    }
}
