<?php

namespace Tommica\LaravelActionsScramble;

use Dedoc\Scramble\Infer\Reflector\ClassReflector;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\OperationExtensions\RequestBodyExtension;
use Dedoc\Scramble\Support\OperationExtensions\RulesExtractor\ValidationNodesResult;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Arr;
use PhpParser\Node;
use PhpParser\NodeFinder;

class LaravelActionsExtension extends RequestBodyExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        // Only process Laravel Actions (routes with asController method)
        if ($routeInfo->methodName() !== 'asController') {
            return;
        }

        $className = $routeInfo->className();
        if (!$className || !method_exists($className, 'rules')) {
            return;
        }

        parent::handle($operation, $routeInfo);
    }

    protected function extractRouteRequestValidationRules(RouteInfo $route, $methodNode)
    {
        $rules = [];
        $nodesResults = [];

        $className = $route->className();
        
        if (!$className || !method_exists($className, 'rules')) {
            return [$rules, $nodesResults];
        }

        try {
            $rules = $this->extractRulesFromAction($className);
            
            if (!empty($rules)) {
                $nodesResults[] = $this->actionNode($className);
            }
        } catch (\Throwable $e) {
            // If we can't extract rules, just return empty
        }

        return [$rules, array_filter($nodesResults)];
    }

    private function extractRulesFromAction(string $className): array
    {
        if (!method_exists($className, 'rules')) {
            return [];
        }

        try {
            $action = app($className);
            return $action->rules();
        } catch (\Throwable $e) {
            // If we can't instantiate the action, try calling rules statically
            return [];
        }
    }

    private function actionNode(string $className)
    {
        try {
            $method = ClassReflector::make($className)->getMethod('rules');
            $rulesMethodNode = $method->getAstNode();

            return new ValidationNodesResult(
                (new NodeFinder())->find(
                    Arr::wrap($rulesMethodNode->stmts),
                    fn(Node $node) => $node instanceof Node\Expr\ArrayItem
                        && $node->key instanceof Node\Scalar\String_
                        && $node->getAttribute('parsedPhpDoc')
                )
            );
        } catch (\Throwable $e) {
            return new ValidationNodesResult([]);
        }
    }
}
