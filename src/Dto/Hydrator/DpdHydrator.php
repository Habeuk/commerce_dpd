<?php

// modules/commerce_dpd/src/Hydrator/DpdHydrator.php
namespace Drupal\commerce_dpd\Dto\Hydrator;

use Drupal\commerce_dpd\Dto\Attribute\DpdField;
use Drupal\commerce_dpd\DpdHelper\BaseData;

class DpdHydrator {
  /**
   *
   * @var array
   */
  private array $reflectionCache = [];
  
  public function hydrate(string $className, array|object $data): object {
    $arrayData = BaseData::normalizeStdClassToArray($data);
    $reflection = $this->getReflection($className);
    $instance = $reflection->newInstanceWithoutConstructor();
    foreach ($reflection->getProperties() as $property) {
      $this->setProperty($property, $instance, $arrayData);
    }
    return $instance;
  }
  
  private function getReflection(string $className): \ReflectionClass {
    return $this->reflectionCache[$className] ??= new \ReflectionClass($className);
  }
  
  private function setProperty(\ReflectionProperty $property, object $instance, array $data): void {
    $attributes = $property->getAttributes(DpdField::class);
    if (empty($attributes)) {
      return;
    }
    $attribute = $attributes[0]->newInstance();
    
    $value = $this->getValue($data, $attribute, $property->getName());
    if (!$property->isPublic()) {
      $property->setAccessible(true);
    }
    $property->setValue($instance, $value);
  }
  
  private function getValue(array $data, DpdField $attribute, string $propertyName) {
    $key = $attribute->source ?: $propertyName;
    $value = $this->getByPath($data, $key);
    if ($value === null && $attribute->required) {
      throw new \RuntimeException(sprintf('Champ DPD requis manquant : "%s" (propriété: %s)', $key, $propertyName));
    }
    if ($attribute->factory && $value !== null) {
      return $this->callFactory($attribute->factory, $value);
    }
    if ($attribute->type && $value !== null) {
      return $this->cast($value, $attribute->type);
    }
    return $value;
  }
  
  /**
   * Récupère une valeur par chemin (optimisé).
   */
  private function getByPath(array $data, string $path) {
    if (strpos($path, '.') === false) {
      return $data[$path] ?? null;
    }
    $parts = explode('.', $path);
    $current = $data;
    foreach ($parts as $part) {
      if (!isset($current[$part])) {
        return null;
      }
      $current = $current[$part];
    }
    return $current;
  }
  
  private function callFactory(string $factory, $data) {
    [
      $className,
      $methodName
    ] = explode('::', $factory, 2);
    if (!class_exists($className) || !method_exists($className, $methodName)) {
      throw new \RuntimeException("Factory invalide : {$factory}");
    }
    return $className::$methodName($data);
  }
  
  private function cast($value, string $type) {
    if ($value === null) {
      return null;
    }
    return match ($type) {
        'int' => (int) $value,
        'float' => (float) $value,
        'string' => (string) $value,
        'bool' => (bool) $value,
        'array' => (array) $value,
        default => $value
    };
  }
}