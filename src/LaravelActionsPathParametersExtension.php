<?php

namespace Tommica\LaravelActionsScramble;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * This extension fixes path parameter names for Laravel Actions.
 * 
 * Laravel Actions use __invoke(...$arguments) as the method signature,
 * which causes Scramble to use "arguments" as the path parameter name.
 * 
 * This extension looks at the asController method to get the correct
 * parameter names and types.
 */
class LaravelActionsPathParametersExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        // Only process Laravel Actions (asController method with __invoke)
        if ($routeInfo->methodName() !== 'asController') {
            return;
        }

        $className = $routeInfo->className();
        if (!$className || !method_exists($className, 'asController')) {
            return;
        }

        // Get the route parameter names from the URI
        $routeParamNames = $routeInfo->route->parameterNames();
        if (empty($routeParamNames)) {
            return;
        }

        // Get the asController method reflection
        $reflection = new ReflectionMethod($className, 'asController');
        $methodParams = $reflection->getParameters();

        // Filter out injected dependencies (keep only scalar types that match route params)
        $pathParams = [];
        foreach ($methodParams as $param) {
            $type = $param->getType();
            
            // Skip parameters that are class types (injected dependencies)
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                continue;
            }

            $pathParams[] = $param;
        }

        // Map route parameter names to method parameter info
        $parameterMap = [];
        foreach ($routeParamNames as $index => $routeParamName) {
            if (isset($pathParams[$index])) {
                $param = $pathParams[$index];
                $parameterMap[$routeParamName] = [
                    'name' => $param->getName(),
                    'type' => $param->getType(),
                    'allowsNull' => $param->allowsNull(),
                    'hasDefault' => $param->isDefaultValueAvailable(),
                ];
            }
        }

        // Fix the operation path - replace {arguments} with correct parameter names
        $path = $operation->path;
        if (Str::contains($path, '{arguments}')) {
            // Replace {arguments} with the first route parameter name
            $firstParamName = $routeParamNames[0] ?? 'id';
            $path = Str::replace('{arguments}', '{'.$firstParamName.'}', $path);
            $operation->setPath($path);
        }

        // Update or add path parameters with correct names and types
        $existingParams = collect($operation->parameters)->keyBy('name');
        
        foreach ($parameterMap as $routeParamName => $paramInfo) {
            // Remove incorrectly named parameter (e.g., "arguments")
            $operation->parameters = array_filter(
                $operation->parameters,
                fn ($p) => $p->name !== 'arguments'
            );

            // Check if parameter already exists with correct name
            if ($existingParams->has($routeParamName)) {
                continue;
            }

            // Create the parameter with correct type
            $type = $paramInfo['type'];
            $schemaType = new StringType();
            
            if ($type instanceof ReflectionNamedType) {
                $typeName = $type->getName();
                if ($typeName === 'int') {
                    $schemaType = new IntegerType();
                }
            }

            $isRequired = !$paramInfo['allowsNull'] && !$paramInfo['hasDefault'];

            $parameter = Parameter::make($routeParamName, 'path')
                ->setSchema(Schema::fromType($schemaType))
                ->required($isRequired);

            $operation->addParameters([$parameter]);
        }
    }
}
