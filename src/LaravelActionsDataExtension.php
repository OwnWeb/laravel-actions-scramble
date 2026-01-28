<?php

namespace Tommica\LaravelActionsScramble;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\OperationExtensions\RequestBodyExtension;
use Dedoc\Scramble\Support\RouteInfo;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\ObjectType;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use ReflectionNamedType;
use Spatie\LaravelData\Data;

/**
 * This extension handles Laravel Actions with Spatie Data objects as request bodies.
 * 
 * It uses reflection to find Data object parameters in the asController method
 * and properly documents them in the OpenAPI spec.
 */
class LaravelActionsDataExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        // Only process Laravel Actions with asController method
        if ($routeInfo->methodName() !== 'asController') {
            return;
        }

        $className = $routeInfo->className();
        if (!$className || !method_exists($className, 'asController')) {
            return;
        }

        // Check if Scramble Pro's DataRequestExtension already handled this
        // by checking if there's already a request body with a Data schema
        if ($operation->requestBodyObject && $this->hasDataSchema($operation)) {
            return;
        }

        // Use reflection to find Data parameters in asController
        $reflection = new ReflectionMethod($className, 'asController');
        $dataParameter = $this->findDataParameter($reflection);

        if (!$dataParameter) {
            return;
        }

        $dataClassName = $dataParameter['type'];

        // Make sure the class is analyzed
        $this->infer->analyzeClass($dataClassName);

        // Check if Scramble Pro is available and use its DataTransformConfig
        if (class_exists(\Dedoc\ScramblePro\Extensions\LaravelData\DataTransformConfig::class)) {
            $this->handleWithScramblePro($operation, $routeInfo, $dataClassName);
        } else {
            $this->handleWithoutScramblePro($operation, $routeInfo, $dataClassName);
        }

        // Attach validation error response
        $this->attachValidationErrorToResponses($operation);
    }

    protected function findDataParameter(ReflectionMethod $reflection): ?array
    {
        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                if (class_exists($typeName) && is_subclass_of($typeName, Data::class)) {
                    return [
                        'name' => $param->getName(),
                        'type' => $typeName,
                        'allowsNull' => $type->allowsNull(),
                    ];
                }
            }
        }
        return null;
    }

    protected function hasDataSchema(Operation $operation): bool
    {
        if (!$operation->requestBodyObject || empty($operation->requestBodyObject->content)) {
            return false;
        }

        foreach ($operation->requestBodyObject->content as $content) {
            $schema = $content->schema ?? null;
            if ($schema instanceof Reference) {
                $schemaName = $schema->getUniqueName();
                // Check if the schema name ends with "Data" which indicates a Spatie Data class
                if (Str::endsWith($schemaName, 'Data')) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function handleWithScramblePro(Operation $operation, RouteInfo $routeInfo, string $dataClassName): void
    {
        $config = new \Dedoc\ScramblePro\Extensions\LaravelData\DataTransformConfig(
            \Dedoc\ScramblePro\Extensions\LaravelData\DataTransformConfig::INPUT
        );

        /** @var Reference $schemaReference */
        $schemaReference = $this->openApiTransformer->transform($config->wrapToInputType(new Generic($dataClassName)));
        $schema = $schemaReference->resolve()->type;

        if (in_array($operation->method, RequestBodyExtension::HTTP_METHODS_WITHOUT_REQUEST_BODY)) {
            $laravelDataParameters = collect($schema->properties)
                ->map(fn ($type, $name) => Parameter::make($name, 'query')
                    ->setSchema(Schema::fromType($type))
                    ->required(in_array($name, $schema->required)))
                ->values()
                ->toArray();

            $operation->addParameters($laravelDataParameters);
            return;
        }

        if (!$operation->requestBodyObject) {
            $operation->requestBodyObject = RequestBodyObject::make()->setContent(
                $this->getMediaType($operation, $routeInfo),
                Schema::fromType(new \Dedoc\Scramble\Support\Generator\Types\ObjectType),
            );
        }

        $operation->requestBodyObject
            ->description('`' . $schemaReference->getUniqueName() . '`');

        $operation->requestBodyObject->setContent(
            array_keys($operation->requestBodyObject->content)[0] ?? 'application/json',
            $schemaReference,
        )->required($this->isSchemaRequired($schemaReference));
    }

    protected function handleWithoutScramblePro(Operation $operation, RouteInfo $routeInfo, string $dataClassName): void
    {
        // Basic handling without Scramble Pro - just create a reference to the Data class
        $schemaReference = $this->openApiTransformer->transform(new ObjectType($dataClassName));

        if (in_array($operation->method, RequestBodyExtension::HTTP_METHODS_WITHOUT_REQUEST_BODY)) {
            return;
        }

        if (!$operation->requestBodyObject) {
            $operation->requestBodyObject = RequestBodyObject::make()->setContent(
                'application/json',
                $schemaReference instanceof Reference ? $schemaReference : Schema::fromType($schemaReference),
            );
        }
    }

    protected function getMediaType(Operation $operation, RouteInfo $routeInfo): string
    {
        if (
            ($mediaTags = $routeInfo->phpDoc()->getTagsByName('@requestMediaType'))
            && ($mediaType = trim(Arr::first($mediaTags)?->value?->value))
        ) {
            return $mediaType;
        }

        return 'application/json';
    }

    protected function isSchemaRequired(Reference|Schema $schema): bool
    {
        $schema = $schema instanceof Reference ? $schema->resolve() : $schema;
        $type = $schema instanceof Schema ? $schema->type : $schema;

        if ($type instanceof \Dedoc\Scramble\Support\Generator\Types\ObjectType) {
            return count($type->required) > 0;
        }

        return false;
    }

    protected function attachValidationErrorToResponses(Operation $operation): void
    {
        if (collect($operation->responses)->where('code', 422)->count()) {
            return;
        }

        if (!$response = $this->openApiTransformer->toResponse(new ObjectType(ValidationException::class))) {
            return;
        }

        $operation->responses[] = $response;
    }
}
