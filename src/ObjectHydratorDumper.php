<?php

declare(strict_types=1);

namespace EventSauce\ObjectHydrator;

use function array_key_exists;
use function array_keys;
use function array_pop;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function str_replace;
use function var_export;

class ObjectHydratorDumper
{
    private DefinitionProvider $definitionProvider;

    public function __construct(DefinitionProvider $definitionProvider = null)
    {
        $this->definitionProvider = $definitionProvider ?: new ReflectionDefinitionProvider();
    }

    public function dump(array $classes, string $dumpedClassName): string
    {
        $parts = explode('\\', $dumpedClassName);
        $shortName = array_pop($parts);
        $namespace = implode('\\', $parts);
        $classes = $this->expandClasses($classes);
        $hydrators = [];
        $hydratorMap = [];

        foreach ($classes as $className) {
            $classDefinition = $this->definitionProvider->provideDefinition($className);
            $methodName = 'hydrate' . str_replace('\\', '', $className);
            $hydratorMap[] = "'$className' => \$this->$methodName(\$payload),";
            $hydrators[] = $this->dumpClassHydrator($className, $classDefinition);
        }

        $hydratorMapCode = implode("\n                ", $hydratorMap);
        $hydratorCode = implode("\n\n", $hydrators);

        return <<<CODE
<?php

declare(strict_types=1);

namespace $namespace;

use EventSauce\ObjectHydrator\ObjectHydrator;
use EventSauce\ObjectHydrator\UnableToHydrateObject;

/**
 * @template T
 */
class $shortName extends ObjectHydrator
{
    /**
     * @param class-string<T> \$className
     * @return T
     */
    public function hydrateObject(string \$className, array \$payload): object
    {
        try {
            return match(\$className) {
                $hydratorMapCode
                default => throw new \\LogicException("No hydration defined for \$className"),
            };
        } catch (\\Throwable \$exception) {
            throw UnableToHydrateObject::dueToError(\$className, \$exception);
        }
    }
    
    $hydratorCode
}
CODE;
    }

    private function expandClasses(array $classes): array
    {
        $classes = array_values($classes);

        for ($i = 0; array_key_exists($i, $classes); $i++) {
            $class = $classes[$i];
            $classDefinition = $this->definitionProvider->provideDefinition($class);

            foreach ($classDefinition->propertyDefinitions as $propertyDefinition) {
                if ($propertyDefinition->canBeHydrated === false) {
                    continue;
                }

                $className = (string) $propertyDefinition->concreteTypeName;

                if ( ! in_array($className, $classes)) {
                    $classes[] = $className;
                }
            }
        }

        return $classes;
    }

    private function dumpClassHydrator(string $className, ClassDefinition $classDefinition)
    {
        $body = '';
        foreach ($classDefinition->propertyDefinitions as $definition) {
            $keys = $definition->keys;
            $property = $definition->property;

            if (count($keys) === 1) {
                $from = array_keys($keys)[0];
                $body .= <<<CODE

            \$value = \$payload['$from'] ?? null;

            if (\$value === null) {
                goto after_$property;
            }

CODE;
            } else {
                $collectKeys = '';

                foreach ($keys as $from => $to) {
                    $collectKeys .= <<<CODE

            if (array_key_exists('$from', \$payload)) {
                \$value['$to'] = \$payload['$from'];
            }
CODE;

                }

                $body .= <<<CODE

            \$value = [];

            $collectKeys

            if (\$value === []) {
                goto after_$property;
            }

CODE;
            }

            foreach ($definition->propertyCasters as $index => [$caster, $options]) {
                $casterOptions = var_export($options, true);
                $casterName = $property . 'Caster' . $index;

                if ($caster) {
                    $body .= <<<CODE
        global \$$casterName;

        if (\$$casterName === null) {
            \$$casterName = new \\$caster(...$casterOptions);
        }

        \$value = \${$casterName}->cast(\$value, \$this);
CODE;
                }
            }

            if ($definition->isEnum) {
                $body .= <<<CODE
            \$value = \\{$definition->concreteTypeName}::from(\$value);
CODE;
            } elseif ($definition->canBeHydrated) {
                $body .= <<<CODE
            if (is_array(\$value)) {
                \$value = \$this->hydrateObject('{$definition->concreteTypeName}', \$value);
            }
CODE;
            }

            $body .= <<<CODE

            \$properties['$property'] = \$value;

            after_$property:

CODE;
        }

        $methodName = 'hydrate' . str_replace('\\', '', $className);
        $constructionCode = $classDefinition->constructionStyle === 'new' ? "new \\$className(...\$properties)" : "$classDefinition->constructor(...\$properties)";

        return <<<CODE
        
        private function $methodName(array \$payload): \\$className
        {
            \$properties = []; 
            $body
            
            return $constructionCode;
        }
CODE;
    }
}
