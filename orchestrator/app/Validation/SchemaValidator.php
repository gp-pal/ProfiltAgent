<?php

namespace App\Validation;

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;

class SchemaValidator
{
    private array $schema;
    private Validator $validator;

    public function __construct()
    {
        // Try enhanced schema first, fallback to original
        $enhancedSchemaPath = base_path('resources/dsl/schema.json');
        $originalSchemaPath = base_path('resources/dsl/schema.json');

        if (file_exists($enhancedSchemaPath)) {
            $this->schema = json_decode(file_get_contents($enhancedSchemaPath), true);
        } else {
            $this->schema = json_decode(file_get_contents($originalSchemaPath), true);
        }

        $this->validator = new Validator();
    }

    public function validate(array $plan): bool
    {
        $planObject = json_decode(json_encode($plan));
        $schemaObject = json_decode(json_encode($this->schema));

        $this->validator->validate($planObject, $schemaObject, Constraint::CHECK_MODE_COERCE_TYPES);

        if (!$this->validator->isValid()) {
            $errors = [];
            foreach ($this->validator->getErrors() as $error) {
                $errors[] = sprintf("[%s] %s", $error['property'], $error['message']);
            }
            throw new \InvalidArgumentException('Schema validation failed: ' . implode(', ', $errors));
        }

        return true;
    }

    public function getErrors(): array
    {
        return $this->validator->getErrors();
    }
}