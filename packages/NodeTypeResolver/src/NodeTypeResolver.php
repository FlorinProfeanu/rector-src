<?php declare(strict_types=1);

namespace Rector\NodeTypeResolver;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Scope;
use PHPStan\TrinaryLogic;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\ThisType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use Rector\Node\Attribute;
use Rector\NodeTypeResolver\Contract\PerNodeTypeResolver\PerNodeTypeResolverInterface;
use Rector\NodeTypeResolver\Reflection\ClassReflectionTypesResolver;

final class NodeTypeResolver
{
    /**
     * @var PerNodeTypeResolverInterface[]
     */
    private $perNodeTypeResolvers = [];

    /**
     * @var ClassReflectionTypesResolver
     */
    private $classReflectionTypesResolver;

    public function __construct(ClassReflectionTypesResolver $classReflectionTypesResolver)
    {
        $this->classReflectionTypesResolver = $classReflectionTypesResolver;
    }

    public function addPerNodeTypeResolver(PerNodeTypeResolverInterface $perNodeTypeResolver): void
    {
        foreach ($perNodeTypeResolver->getNodeClasses() as $nodeClass) {
            $this->perNodeTypeResolvers[$nodeClass] = $perNodeTypeResolver;
        }
    }

    /**
     * @return string[]
     */
    public function resolve(Node $node): array
    {
        /** @var Scope $nodeScope */
        $nodeScope = $node->getAttribute(Attribute::SCOPE);

        if ($nodeScope === null) {
            return [];
        }

        if ($node instanceof Variable) {
            return $this->resolveVariableNode($node, $nodeScope);
        }

        if ($node instanceof Expr) {
            return $this->resolveExprNode($node);
        }

        $nodeClass = get_class($node);
        if (isset($this->perNodeTypeResolvers[$nodeClass])) {
            return $this->perNodeTypeResolvers[$nodeClass]->resolve($node);
        }

        return [];
    }

    /**
     * @return string[]
     */
    private function resolveExprNode(Expr $exprNode): array
    {
        /** @var Scope $nodeScope */
        $nodeScope = $exprNode->getAttribute(Attribute::SCOPE);

        $type = $nodeScope->getType($exprNode);

        return $this->resolveObjectTypesToStrings($type);
    }

    /**
     * @return string[]
     */
    private function resolveObjectTypesToStrings(Type $type): array
    {
        $types = [];

        if ($type instanceof ObjectType) {
            $types[] = $type->getClassName();
        }

        if ($type instanceof UnionType) {
            foreach ($type->getTypes() as $type) {
                // @todo recursion
                if ($type instanceof ObjectType) {
                    $types[] = $type->getClassName();
                }
            }
        }

        if ($type instanceof IntersectionType) {
            foreach ($type->getTypes() as $type) {
                // @todo recursion
                if ($type instanceof ObjectType) {
                    $types[] = $type->getClassName();
                }
            }
        }

        return $types;
    }

    /**
     * @return string[]
     */
    private function resolveVariableNode(Variable $variableNode, Scope $nodeScope): array
    {
        $variableName = (string) $variableNode->name;

        if ($nodeScope->hasVariableType($variableName) === TrinaryLogic::createYes()) {
            $type = $nodeScope->getVariableType($variableName);

            // this
            if ($type instanceof ThisType) {
                return $this->classReflectionTypesResolver->resolve($nodeScope->getClassReflection());
            }

            return $this->resolveObjectTypesToStrings($type);
        }

        return [];
    }
}
