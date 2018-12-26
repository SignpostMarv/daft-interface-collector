<?php

declare(strict_types=1);
/**
* @author SignpostMarv
*/

namespace SignpostMarv\DaftInterfaceCollector;

use Closure;
use Generator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use Traversable;

class StaticMethodCollector
{
    /**
    * @var array<string, array<string, string[]>>
    */
    private $staticMethods = [];

    /**
    * @var string[]
    */
    private $interfaces = [];

    /**
    * @var array<int, string>
    */
    private $processedSources = [];

    /**
    * @var string[]
    */
    private $alreadyYielded = [];

    /**
    * @var bool
    */
    private $autoReset;

    public function __construct(array $staticMethods, array $interfaces, bool $autoReset = true)
    {
        $filteredMethods = [];

        foreach ($this->FilterArrayOfInterfaceOffsets($staticMethods) as $interface => $methods) {
            $filteredMethods[$interface] = $this->FilterMethods($interface, $methods);
        }

        /**
        * @var array<string, array<string, string[]>> $filteredMethods
        */
        $filteredMethods = $this->FilterNonZeroArray($filteredMethods);

        $this->staticMethods = $filteredMethods;

        /**
        * @var string[] $filteredInterfaces
        */
        $filteredInterfaces = $this->FilterArrayOfInterfacesOrClasses($interfaces);

        $this->interfaces = $filteredInterfaces;

        $this->autoReset = $autoReset;
    }

    public function Collect(string ...$implementations) : Generator
    {
        if ($this->autoReset) {
            $this->processedSources = [];
            $this->alreadyYielded = [];
        }

        yield from $this->CollectInterfaces(...$implementations);
    }

    protected function CollectInterfaces(string ...$implementations) : Generator
    {
        foreach (
            array_filter(
                $implementations,
                function (string $implementation) : bool {
                    return
                        class_exists($implementation) &&
                        ! in_array($implementation, $this->processedSources, true);
                }
            ) as $implementation
        ) {
            $this->processedSources[] = $implementation;
            yield from $this->CollectInterfacesFromImplementationCheckInterfaces($implementation);
            yield from $this->CollectInterfacesFromImplementation($implementation);
        }
    }

    final protected function CollectInterfacesFromImplementationCheckInterfaces(
        string $implementation
    ) : Generator {
        foreach ($this->interfaces as $interface) {
            if (
                ! in_array($implementation, $this->alreadyYielded, true) &&
                is_a($implementation, $interface, true)
            ) {
                yield $implementation;
                $this->alreadyYielded[] = $implementation;
                break;
            }
        }
    }

    final protected function CollectInterfacesFromImplementation(string $implementation) : Generator
    {
        $interfaces = array_keys($this->staticMethods);
        /**
        * @var string $interface
        */
        foreach ($this->FilterIsA($implementation, $interfaces) as $interface) {
            foreach ($this->staticMethods[$interface] as $method => $types) {
                yield from $this->CollectInterfacesFromImplementationTypes(
                    $implementation,
                    $method,
                    $types
                );
            }
        }
    }

    /**
    * @psalm-suppress InvalidStringClass
    */
    final protected function CollectInterfacesFromImplementationTypes(
        string $implementation,
        string $method,
        array $types
    ) : Generator {
        /**
        * @var iterable<string> $methodResult
        */
        $methodResult = $implementation::$method();

        foreach ($methodResult as $result) {
            if (in_array($result, $this->alreadyYielded, true)) {
                continue;
            }
            /**
            * @var string $type
            */
            foreach ($this->FilterIsA($result, $types) as $type) {
                yield $result;
                $this->alreadyYielded[] = $result;
            }

            yield from $this->CollectInterfaces($result);
        }
    }

    final protected function FilterIsA(string $implementation, array $interfaces) : array
    {
        return array_filter($interfaces, function (string $interface) use ($implementation) : bool {
            return is_a($implementation, $interface, true);
        });
    }

    /**
    * @return string[]|array<string, mixed>
    */
    final protected function FilterArrayOfInterfaces(array $interfaces, int $flag = 0) : array
    {
        $strings = array_filter($interfaces, 'is_string', $flag);

        return array_filter($strings, 'interface_exists', $flag);
    }

    /**
    * @return string[]
    */
    final protected function FilterArrayOfInterfacesOrClasses(array $interfaces) : array
    {
        /**
        * @var string[] $strings
        */
        $strings = array_filter($interfaces, 'is_string');

        return array_filter($strings, function (string $maybe) : bool {
            return interface_exists($maybe) || class_exists($maybe);
        });
    }

    /**
    * @return array<string, array>
    */
    final protected function FilterArrayOfInterfaceOffsets(array $interfaces) : array
    {
        /**
        * @var array<string, array> $strings
        */
        $strings = $this->FilterArrayOfInterfaces($interfaces, ARRAY_FILTER_USE_KEY);

        return array_filter($strings, 'is_array');
    }

    final protected function MakeMethodFilter(string $interface) : Closure
    {
        return function (string $maybe) use ($interface) : bool {
            $ref = new ReflectionClass($interface);

            return
                $ref->hasMethod($maybe) &&
                $this->FilterReflectionMethod($ref->getMethod($maybe));
        };
    }

    final protected function FilterReflectionMethod(ReflectionMethod $refMethod) : bool
    {
        return
            $refMethod->isStatic() &&
            $refMethod->isPublic() &&
            0 === $refMethod->getNumberOfRequiredParameters() &&
            $this->FilterReflectionReturnType($refMethod->getReturnType());
    }

    final protected function FilterReflectionReturnType(? ReflectionType $refReturn) : bool
    {
        $refReturnName = ($refReturn instanceof ReflectionNamedType) ? $refReturn->getName() : '';

        return 'array' === $refReturnName || is_a($refReturnName, Traversable::class, true);
    }

    /**
    * @return array<string, string[]>
    */
    final protected function FilterMethods(string $interface, array $methods) : array
    {
        /**
        * @var array<string, string[]>
        */
        $filteredMethods = $this->FilterNonZeroArray(array_map(
            [$this, 'FilterArrayOfInterfacesOrClasses'],
            array_filter(
                array_filter($methods, 'is_string', ARRAY_FILTER_USE_KEY),
                $this->MakeMethodFilter($interface),
                ARRAY_FILTER_USE_KEY
            )
        ));

        return $filteredMethods;
    }

    /**
    * @var array[]
    */
    final protected function FilterNonZeroArray(array $in) : array
    {
        return array_filter(
            $in,
            function (array $val) : bool {
                return count($val) > 0;
            }
        );
    }
}
