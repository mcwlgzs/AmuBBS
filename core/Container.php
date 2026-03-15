<?php
/**
 * 依赖注入容器
 */

namespace Core;

class Container
{
    private array $bindings = [];
    private array $instances = [];

    /**
     * 绑定服务
     */
    public function bind(string $abstract, callable $concrete): void
    {
        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'shared' => false,
        ];
    }

    /**
     * 绑定单例服务
     */
    public function singleton(string $abstract, callable $concrete = null): void
    {
        $this->bindings[$abstract] = [
            'concrete' => $concrete ?? function($container) use ($abstract) {
                return new $abstract();
            },
            'shared' => true,
        ];
    }

    /**
     * 解析服务
     */
    public function make(string $abstract): mixed
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (!isset($this->bindings[$abstract])) {
            return $this->resolve($abstract);
        }

        $binding = $this->bindings[$abstract];
        $instance = $binding['concrete']($this);

        if ($binding['shared']) {
            $this->instances[$abstract] = $instance;
        }

        return $instance;
    }

    /**
     * 别名：get
     */
    public function get(string $abstract): mixed
    {
        return $this->make($abstract);
    }

    /**
     * 自动解析类（支持构造函数依赖注入）
     */
    private function resolve(string $class): object
    {
        $reflector = new \ReflectionClass($class);

        if (!$reflector->isInstantiable()) {
            throw new \Exception("类 {$class} 不可实例化");
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $parameters = $constructor->getParameters();
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            if ($type === null || !($type instanceof \ReflectionNamedType) || $type->isBuiltin()) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                    continue;
                }
                throw new \Exception("无法解析参数 {$parameter->getName()}");
            }

            $dependencies[] = $this->make($type->getName());
        }

        return $reflector->newInstanceArgs($dependencies);
    }

    /**
     * 检查服务是否已绑定
     */
    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }
}
