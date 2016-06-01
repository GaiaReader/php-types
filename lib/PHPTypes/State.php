<?php

/*
 * This file is part of PHP-Types, a type reconstruction lib for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPTypes;

use PHPCfg\Block;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PHPCfg\Traverser;
use PHPCfg\Visitor;
use SplObjectStorage;

class State {
    /** @var InternalArgInfo  */
    public $internalTypeInfo;
    /** @var TypeResolver  */
    public $resolver;
    
    /** @var Block[] */
    public $scripts = [];
    
    /** @var Op\Stmt\Class_[][] */
    public $classMap = [];

    /** @var SplObjectStorage */
    public $variables;

    /** @var Op\Terminal\Const_[] */
    public $constants;    
    /** @var Op\Stmt\Trait_[] */
    public $traits;
    /** @var Op\Stmt\Class_[] */
    public $classes;
    /** @var Op\Stmt\Interface_[] */
    public $interfaces;
    /** @var Op\Stmt\ClassMethod[] */
    public $methods;
    /** @var Op\Stmt\ClassMethod[][] */
    public $methodLookup;
    /** @var Op\Stmt\Function_[] */
    public $functions;
    /** @var Op\Stmt\Function_[][] */
    public $functionLookup;

    public $classResolves = [];

    public $classResolvedBy = [];

    /** @var Op\Expr\FuncCall[] */
    public $funcCalls = [];
    /** @var Op\Expr\NsFuncCall[] */
    public $nsFuncCalls = [];
    /** @var Op\Expr\MethodCall[] */
    public $methodCalls = [];
    /** @var Op\Expr\StaticCall[] */
    public $staticCalls = [];
    /** @var Op\Expr\New_[] */
    public $newCalls = [];

    /**
     * State constructor.
     * @param Script[] $scripts
     */
    public function __construct(array $scripts) {
        $this->scripts = $scripts;
        $this->resolver = new TypeResolver($this);
        $this->internalTypeInfo = new InternalArgInfo;
        $this->load();
    }


    private function load() {
        $traverser = new Traverser;
        $declarations = new Visitor\DeclarationFinder;
        $calls = new Visitor\CallFinder;
        $variables = new Visitor\VariableFinder;
        $traverser->addVisitor($declarations);
        $traverser->addVisitor($calls);
        $traverser->addVisitor($variables);

        foreach ($this->scripts as $script) {
            $traverser->traverse($script);
        }

        $this->variables = $variables->getVariables();
        $this->constants = $declarations->getConstants();
        $this->traits = $declarations->getTraits();
        $this->classes = $declarations->getClasses();
        $this->interfaces = $declarations->getInterfaces();
        $this->methods = $declarations->getMethods();
        $this->functions = $declarations->getFunctions();
        $this->functionLookup = $this->buildFunctionLookup($declarations->getFunctions());
        $this->funcCalls = $calls->getFuncCalls();
        $this->nsFuncCalls = $calls->getNsFuncCalls();
        $this->methodCalls = $calls->getMethodCalls();
        $this->staticCalls = $calls->getStaticCalls();
        $this->newCalls = $calls->getNewCalls();
        $this->computeTypeMatrix();
    }

    /**
     * @param Op\Stmt\Function_[] $functions
     * @return Op\Stmt\Function_[][]
     */
    private function buildFunctionLookup(array $functions) {
        $lookup = [];
        foreach ($functions as $function) {
            $name = strtolower($function->func->name);
            if (!isset($lookup[$name])) {
                $lookup[$name] = [];
            }
            $lookup[$name][] = $function;
        }
        return $lookup;
    }

    private function computeTypeMatrix() {
        // TODO: This is dirty, and needs cleaning
        // A extends B
        $map = []; // a => [a, b], b => [b]
        $interfaceMap = [];
        $classMap = [];
        $toProcess = [];
        /** @var Op\Stmt\Interface_ $interface */
        foreach ($this->interfaces as $interface) {
            $name = strtolower($interface->name->value);
            $map[$name] = [$name => $interface];
            $interfaceMap[$name] = [];
            if ($interface->extends) {
                foreach ($interface->extends as $extends) {
                    assert($extends instanceof Operand\Literal);
                    $sub = strtolower($extends->value);
                    $interfaceMap[$name][] = $sub;
                    $map[$sub][$name] = $interface;
                }
            }
        }
        /** @var Op\Stmt\Class_ $class */
        foreach ($this->classes as $class) {
            $name = strtolower($class->name->value);
            $map[$name] = [$name => $class];
            $classMap[$name] = [$name];
            foreach ($class->implements as $interface) {
                assert($interface instanceof Operand\Literal);
                $iname = strtolower($interface->value);
                $classMap[$name][] = $iname;
                $map[$iname][$name] = $class;
                if (isset($interfaceMap[$iname])) {
                    foreach ($interfaceMap[$iname] as $sub) {
                        $classMap[$name][] = $sub;
                        $map[$sub][$name] = $class;
                    }
                }
            }
            if ($class->extends) {
                assert($interface instanceof Operand\Literal);
                $toProcess[] = [$name, strtolower($class->extends->value), $class];
            }
        }
        foreach ($toProcess as $ext) {
            $name = $ext[0];
            $extends = $ext[1];
            $class = $ext[2];
            if (isset($classMap[$extends])) {
                foreach ($classMap[$extends] as $mapped) {
                    $map[$mapped][$name] = $class;
                }
            } else {
                echo "Could not find parent $extends\n";
            }
        }
        $this->classResolves = $map;
        $this->classResolvedBy = [];
        foreach ($map as $child => $parent) {
            foreach ($parent as $name => $_) {
                if (!isset($this->classResolvedBy[$name])) {
                    $this->classResolvedBy[$name] = [];
                }
                //allows iterating and looking udm_cat_path(agent, category)
                $this->classResolvedBy[$name][$child] = $child;
            }
        }
    }
}