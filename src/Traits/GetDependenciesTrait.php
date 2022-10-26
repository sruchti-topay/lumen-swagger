<?php

namespace RonasIT\Support\AutoDoc\Traits;

use Illuminate\Container\Container;
use Illuminate\Support\Arr;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;

trait GetDependenciesTrait
{
    protected function resolveClassMethodDependencies(array $parameters, $instance, $method)
    {
        if (!method_exists($instance, $method)) {
            return $parameters;
        }

        return $this->getDependencies(
            new ReflectionMethod($instance, $method)
        );
    }

    public function getDependencies(ReflectionFunctionAbstract $reflector)
    {
        return array_map(function ($parameter) {
            return $this->transformDependency($parameter);
        }, $reflector->getParameters());
    }

    protected function transformDependency(ReflectionParameter $parameter)
    {
        $class = ($parameter->getType() && !$parameter->getType()->isBuiltin())
                    ? new ReflectionClass($parameter->getType()->getName())
                    : null;

        if (empty($class)) {
            return null;
        }

        return interface_exists($class->name) ? $this->getClassByInterface($class->name) : $class->name;
    }

    protected function getClassByInterface($interfaceName)
    {
        $bindings = Container::getInstance()->getBindings();

        $implementation = Arr::get($bindings, "{$interfaceName}.concrete");

        $classFields = (new ReflectionFunction($implementation))->getStaticVariables();

        return $classFields['concrete'];
    }
}
