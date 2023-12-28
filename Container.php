<?php

declare(strict_types=1);

namespace Inspira\Container;

use Closure;
use Inspira\Container\Exceptions\NonInstantiableBindingException;
use Inspira\Container\Exceptions\NotFoundException;
use Inspira\Container\Exceptions\UnresolvableBindingException;
use Inspira\Container\Exceptions\UnresolvableBuiltInTypeException;
use Inspira\Container\Exceptions\UnresolvableMissingTypeException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionType;
use Throwable;

/**
 * @author Ronald Lambino
 */
class Container implements ContainerInterface
{
	/**
	 * An array of abstract:concrete bindings
	 *
	 * @var array{concrete: string, singleton: bool} $bindings
	 */
	private array $bindings = [];

	/**
	 * An array of resolved instances
	 *
	 * @var array<string, mixed> $resolved
	 */
	private array $resolved = [];

	/**
	 * Binds an abstract class|interface to a concrete class
	 *
	 * @param string $abstract
	 * @param string|Closure|null $concrete
	 * @param false|bool $singleton
	 * @return static
	 */
	public function bind(string $abstract, string|Closure $concrete = null, bool $singleton = false): static
	{
		$concrete = $concrete ?? $abstract;

		$this->setBinding($abstract, $concrete, $singleton);

		return $this;
	}

	/**
	 * Binds an abstract into concrete and ensure only a single instance is created
	 *
	 * @param string $abstract
	 * @param string|Closure|null $concrete
	 * @return static
	 */
	public function singleton(string $abstract, string|Closure $concrete = null): static
	{
		$this->bind($abstract, $concrete, true);

		return $this;
	}

	/**
	 * Make a new instance of the given class
	 * If binding was found with a singleton type, returns the same instance
	 *
	 * @param string $abstract
	 * @param string|null $concrete
	 * @return mixed|object|null
	 * @throws NonInstantiableBindingException
	 * @throws UnresolvableBindingException
	 * @throws UnresolvableBuiltInTypeException
	 * @throws UnresolvableMissingTypeException
	 */
	public function make(string $abstract, string $concrete = null): mixed
	{
		$concrete = $concrete ?? $abstract;

		$bindings = $this->getBindings($abstract) ?? [];
		if ($this->has($abstract) && !empty($bindings)) {
			$class = $bindings['concrete'];
			$singleton = $bindings['singleton'];
		} else {
			$this->bind($abstract, $concrete);
			$class = $concrete;
		}

		return $this->resolve($class, null, $singleton ?? false);
	}

	/**
	 * Get all or specific binding
	 *
	 * @param string|null $binding
	 * @return array|null
	 */
	public function getBindings(string $binding = null): array|null
	{
		if ($binding && !$this->has($binding)) {
			return null;
		}

		return $this->bindings[$binding] ?? $this->bindings;
	}

	public function getConcreteBinding(string $key)
	{
		if (!$this->has($key)) {
			return null;
		}

		return $this->getBindings($key)['concrete'];
	}

	/**
	 * Get all or specific resolved
	 *
	 * @param string|null $resolved
	 * @return mixed
	 */
	public function getResolved(string $resolved = null): mixed
	{
		if ($resolved && !$this->hasResolved($resolved)) {
			return null;
		}

		return $this->resolved[$resolved] ?? $this->resolved;
	}

	/**
	 * Resolve the given service|closure and all of its dependencies
	 *
	 * @param string|Closure $closure
	 * @param string|null $method
	 * @param bool $singleton
	 * @return mixed|object|null
	 * @throws NonInstantiableBindingException
	 * @throws UnresolvableBindingException
	 * @throws UnresolvableBuiltInTypeException
	 * @throws UnresolvableMissingTypeException
	 */
	public function resolve(string|Closure $closure, string $method = null, bool $singleton = false): mixed
	{
		if ($closure instanceof Closure) {
			return $this->resolveFunction($closure);
		}

		if ($singleton) {
			return $this->resolveSingleton($closure, $method);
		}

		return $this->resolveRegular($closure, $method);
	}

	/**
	 * Get the resolved instance of the given id
	 * This is to comply with the Psr\Container\ContainerInterface
	 *
	 * @param string $id
	 * @return mixed
	 * @throws NonInstantiableBindingException
	 * @throws UnresolvableBindingException
	 * @throws UnresolvableBuiltInTypeException
	 * @throws UnresolvableMissingTypeException
	 * @throws NotFoundException
	 */
	public function get(string $id): mixed
	{
		if (!$this->has($id)) {
			throw new NotFoundException("`$id` does not exists.");
		}

		return $this->resolve($id);
	}

	/**
	 * Checks if a given id was bound
	 *
	 * @param string $id
	 * @return bool
	 */
	public function has(string $id): bool
	{
		return isset($this->bindings[$id]);
	}

	/**
	 * Check if a given identifier was resolved
	 *
	 * @param string $resolved
	 * @return bool
	 */
	public function hasResolved(string $resolved): bool
	{
		return isset($this->resolved[$resolved]);
	}

	/**
	 * Add resolved instance into resolved array
	 *
	 * @param string $service
	 * @param object $instance
	 * @return void
	 */
	public function setResolved(string $service, object $instance): void
	{
		$this->resolved[$service] = $instance;
	}

	/**
	 * Add binding into bindings array
	 *
	 * @param string $abstract
	 * @param string|Closure $concrete
	 * @param bool $singleton
	 * @return void
	 */
	private function setBinding(string $abstract, string|Closure $concrete, bool $singleton = false): void
	{
		$this->bindings[$abstract] = compact('concrete', 'singleton');
	}

	/**
	 * Resolves a regular binding and give a new instance everytime
	 *
	 * @param string $service
	 * @param string|null $method
	 * @return mixed
	 * @throws NonInstantiableBindingException
	 * @throws UnresolvableBindingException
	 * @throws UnresolvableBuiltInTypeException
	 * @throws UnresolvableMissingTypeException
	 */
	private function resolveRegular(string $service, string $method = null): mixed
	{
		$resolved = $this->resolveClass($service);
		if (is_object($resolved)) {
			$this->setResolved($service, $resolved);

			if ($method) {
				return $this->resolveMethod($resolved, $method);
			}
		}

		return $resolved;
	}

	/**
	 * Resolves a singleton binding and give only a single instance
	 *
	 * @param string $service
	 * @param string|null $method
	 * @return mixed
	 * @throws NonInstantiableBindingException
	 * @throws UnresolvableBindingException
	 * @throws UnresolvableBuiltInTypeException
	 * @throws UnresolvableMissingTypeException
	 */
	private function resolveSingleton(string $service, string $method = null): mixed
	{
		$resolved = $this->getResolved($service) ?? $this->resolveClass($service);
		if (is_object($resolved)) {
			$this->setResolved($service, $resolved);

			if ($method) {
				return $this->resolveMethod($resolved, $method);
			}
		}

		return $resolved;
	}

	/**
	 * Resolves the given class and it's dependencies
	 *
	 * @param string $service
	 * @return object|null
	 * @throws NonInstantiableBindingException
	 * @throws UnresolvableBindingException
	 * @throws UnresolvableBuiltInTypeException
	 * @throws UnresolvableMissingTypeException
	 */
	private function resolveClass(string $service): ?object
	{
		$concrete = $this->getBindings($service)['concrete'] ?? $service;

		if ($concrete instanceof Closure && is_object($instance = $this->resolveFunction($concrete))) {
			$this->setResolved($service, $instance);
			return $this->getResolved($service);
		}

		if ($this->hasResolved($concrete)) {
			try {
				return new ($this->getResolved($concrete));
			} catch (Throwable) { }
		}

		try {
			$reflection = new ReflectionClass($concrete);

			$this->validateInstantiable($reflection);
		} catch (ReflectionException) {
			throw new UnresolvableBindingException(sprintf('Unable to resolve binding `%s`', $concrete));
		}

		$constructor = $reflection->getConstructor();
		$dependencies = $constructor ? $this->resolveDependencies($constructor) : [];

		try {
			$instance = $reflection->newInstanceArgs($dependencies);
			$this->setResolved($concrete, $instance);

			return $instance;
		} catch (ReflectionException) {
			throw new UnresolvableBindingException(sprintf('Unable to resolve binding `%s`', implode(', ', $dependencies)));
		}
	}

	/**
	 * Resolves a method and it's dependencies
	 *
	 * @param object $service
	 * @param string $method
	 * @return mixed
	 * @throws NonInstantiableBindingException
	 * @throws UnresolvableBindingException
	 * @throws UnresolvableBuiltInTypeException
	 * @throws UnresolvableMissingTypeException
	 */
	private function resolveMethod(object $service, string $method): mixed
	{
		try {
			$class = get_class($service);
			$reflection = new ReflectionClass($class);
		} catch (ReflectionException) {
			throw new UnresolvableBindingException();
		}

		try {
			$reflectionMethod = $reflection->getMethod($method);
			return $service->$method(...$this->resolveDependencies($reflectionMethod));
		} catch (ReflectionException) {
			throw new UnresolvableBindingException();
		}
	}

	/**
	 * Resolves a function and it's dependencies
	 *
	 * @param Closure $function
	 * @return mixed
	 * @throws NonInstantiableBindingException
	 * @throws UnresolvableBindingException
	 * @throws UnresolvableBuiltInTypeException
	 * @throws UnresolvableMissingTypeException
	 */
	private function resolveFunction(Closure $function): mixed
	{
		try {
			$reflection = new ReflectionFunction($function);
			return $reflection->invoke(...$this->resolveDependencies($reflection));
		} catch (ReflectionException) {
			throw new UnresolvableBindingException();
		}
	}

	/**
	 * Resolves all the parameters of a given reflection
	 *
	 * @param ReflectionClass|ReflectionMethod|ReflectionFunction $service
	 * @return array<int, mixed>
	 * @throws NonInstantiableBindingException
	 * @throws UnresolvableBindingException
	 * @throws UnresolvableBuiltInTypeException
	 * @throws UnresolvableMissingTypeException
	 */
	private function resolveDependencies(ReflectionClass|ReflectionMethod|ReflectionFunction $service): array
	{
		$dependencies = [];

		if (!method_exists($service, 'getParameters')) {
			return $dependencies;
		}

		foreach ($service->getParameters() as $param) {
			$type = $param->getType();
			$parameter = $param->getName();

			$this->validateType($type, $parameter, $service->getName(), $param);

			$name = $type->getName();
			$binding = $this->getBindings($name);

			if (!$this->has($name) && $param->isDefaultValueAvailable()) {
				$dependencies[] = $param->getDefaultValue();
				continue;
			}

			// Resolve closure binding
			if (is_array($binding) && $binding['concrete'] instanceof Closure) {
				if ($binding['singleton'] && $this->hasResolved($name)) {
					$dependencies[] = $this->getResolved($name);
				} else {
					$resolved = $this->resolveFunction($binding['concrete']);
					$dependencies[] = $resolved;
					$this->setResolved($name, $resolved);
				}

				continue;
			}

			if (is_array($binding) && $binding['singleton'] && $this->hasResolved($name)) {
				$dependencies[] = $this->getResolved($name);
				continue;
			}

			if (is_array($binding) && !$binding['singleton'] && $this->hasResolved($name)) {
				// Silently catch any service that can't be instantiated
				// When it throws an exception, it will resolve the service again
				// This is to handle services that aren't singleton and has dependencies
				try {
					$dependencies[] = new ($this->getResolved($name));
					continue;
				} catch (Throwable) { }
			}

			$resolved = $this->resolve($name);
			$dependencies[] = $resolved;
			$this->setResolved($name, $resolved);
		}

		return $dependencies;
	}

	/**
	 * Validates the type of parameter passed on the service
	 *
	 * @param ReflectionType|null $type
	 * @param string $parameter
	 * @param string $serviceName
	 * @param ReflectionParameter $param
	 * @return void
	 * @throws UnresolvableBuiltInTypeException
	 * @throws UnresolvableMissingTypeException
	 */
	private function validateType(null|ReflectionType $type, string $parameter, string $serviceName, ReflectionParameter $param): void
	{
		if ($type instanceof ReflectionType && method_exists($type, 'isBuiltin') && $type->isBuiltin()) {
			throw new UnresolvableBuiltInTypeException(
				sprintf('Unable to resolve built in type `%s` of `%s` in `%s`', $type, $parameter, $serviceName)
			);
		}

		if ($type === null && !$param->hasType()) {
			throw new UnresolvableMissingTypeException(
				sprintf('Unable to resolve missing type of `%s` in `%s`', $parameter, $serviceName)
			);
		}
	}

	/**
	 * Check if a reflection is instantiable
	 *
	 * @param ReflectionClass $reflection
	 * @return void
	 * @throws NonInstantiableBindingException
	 */
	private function validateInstantiable(ReflectionClass $reflection): void
	{
		if (!$reflection->isInstantiable()) {
			throw new NonInstantiableBindingException(sprintf('Unable to create an instance of non-instantiable `%s`', $reflection->getName()));
		}
	}
}
