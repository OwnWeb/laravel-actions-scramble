<?php

namespace Tommica\LaravelActionsScramble;

use Dedoc\Scramble\Infer;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\OperationExtensions\ParameterExtractor\ParameterExtractor;
use Dedoc\Scramble\Support\OperationExtensions\RequestBodyExtension;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\GeneratesParametersFromRules;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\ParametersExtractionResult;
use Dedoc\Scramble\Support\RouteInfo;
use Dedoc\Scramble\Support\SchemaClassDocReflector;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Reference\MethodCallReferenceType;
use PhpParser\PrettyPrinter;

/**
 * Parameter extractor for Laravel Actions that have a rules() method on the action class itself.
 * 
 * This handles actions like:
 * 
 * class MyAction {
 *     use AsAction;
 *     
 *     public function rules(): array {
 *         return ['field' => 'required'];
 *     }
 *     
 *     public function asController(Request $request): JsonResponse {
 *         // ...
 *     }
 * }
 */
class LaravelActionsParameterExtractor implements ParameterExtractor
{
    use GeneratesParametersFromRules;

    public function __construct(
        private PrettyPrinter $printer,
        private TypeTransformer $openApiTransformer,
    ) {}

    public function handle(RouteInfo $routeInfo, array $parameterExtractionResults): array
    {
        // Only handle Laravel Actions (routes using asController)
        if ($routeInfo->methodName() !== 'asController') {
            return $parameterExtractionResults;
        }

        $actionClassName = $routeInfo->className();
        
        if (!$actionClassName || !method_exists($actionClassName, 'rules')) {
            return $parameterExtractionResults;
        }

        // Check if this action has a Data parameter - if so, skip (handled by DataRequestExtension)
        if ($this->hasDataParameter($routeInfo)) {
            return $parameterExtractionResults;
        }

        $parameterExtractionResults[] = $this->extractActionParameters($actionClassName, $routeInfo);

        return $parameterExtractionResults;
    }

    private function hasDataParameter(RouteInfo $routeInfo): bool
    {
        if (!$reflectionAction = $routeInfo->reflectionAction()) {
            return false;
        }

        foreach ($reflectionAction->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $className = $type->getName();
                if (class_exists($className) && is_subclass_of($className, \Spatie\LaravelData\Data::class)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function extractActionParameters(string $actionClassName, RouteInfo $routeInfo): ParametersExtractionResult
    {
        $classReflector = Infer\Reflector\ClassReflector::make($actionClassName);

        $phpDocReflector = SchemaClassDocReflector::createFromDocString(
            $classReflector->getReflection()->getDocComment() ?: ''
        );

        $schemaName = ($phpDocReflector->getTagValue('@ignoreSchema')->value ?? null) !== null
            ? null
            : null; // Don't create a named schema for action rules

        // Get rules from the action class
        try {
            $action = app($actionClassName);
            $rules = $action->rules();
        } catch (\Throwable $e) {
            return new ParametersExtractionResult([]);
        }

        if (empty($rules)) {
            return new ParametersExtractionResult([]);
        }

        return new ParametersExtractionResult(
            parameters: $this->makeParameters(
                rules: $rules,
                typeTransformer: $this->openApiTransformer,
                rulesDocsRetriever: new LaravelActionsRulesDocumentationRetriever(
                    $routeInfo->getScope(),
                    new MethodCallReferenceType(new ObjectType($actionClassName), 'rules', []),
                ),
                in: in_array(mb_strtolower($routeInfo->method), RequestBodyExtension::HTTP_METHODS_WITHOUT_REQUEST_BODY)
                    ? 'query'
                    : 'body',
            ),
            schemaName: $schemaName,
        );
    }
}
