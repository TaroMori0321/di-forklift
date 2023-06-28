<?php

declare(strict_types=1);

namespace Haikara\DiForklift;

use Haikara\DiForklift\Attributes\Inject;
use Haikara\DiForklift\Exceptions\ContainerException;
use Haikara\DiForklift\Exceptions\NotFoundException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

class Container implements ContainerInterface
{
    /**
     * 生成処理を格納する
     * @var Definitions
     */
    protected Definitions $definitions;

    /**
     * 生成された依存性を格納する
     * @var Dependencies
     */
    protected Dependencies $dependencies;

    public function __construct() {
        $this->definitions = new Definitions;
        $this->dependencies = new Dependencies;
    }

    /**
     * @param string $id
     * @return mixed
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function get(string $id): mixed
    {
        // 未登録のIDなら自動解決
        if (!$this->has($id)) {
            $this->dependencies->add($id, $this->resolve($id));
        }

        // 生成済みではないが定義済みの場合、生成処理を実行
        if (!$this->dependencies->has($id) && $this->definitions->has($id)) {
            $this->dependencies->add($id, $this->definitions->get($id));
        }

        // 生成済みならそれを返す
        if ($this->dependencies->has($id)) {
            return $this->dependencies->get($id);
        }

        throw new NotFoundException;
    }

    /**
     * IDが登録済みかどうか
     *
     * @param string $id
     * @return bool
     */
    public function has(string $id): bool
    {
        // 生成済み、もしくは生成処理を定義済みならtrue
        return $this->dependencies->has($id) || $this->definitions->has($id);
    }

    /**
     * 生成処理の登録。
     * $definitionがnullなら$idに指定された値の生成処理を自動で登録する
     *
     * @param string $id
     * @param mixed $definition
     * @return void
     */
    public function add(string $id, mixed $definition = null): void {
        $definition ??= fn () => $this->get($id);
        $this->definitions->add($id, $definition);
    }

    /**
     * ReflectionClassを分析し、クラスのインスタンス化に必要な依存性を取り揃える
     *
     * @param string $id
     * @return object
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected function resolve(string $id): object
    {
        // IDがクラス文字列でなければ依存解決エラー
        if (!class_exists($id)) {
            throw new ContainerException;
        }

        $ref_class = new ReflectionClass($id);

        // クラスがインスタンス化不可なら依存解決エラー
        if (!$ref_class->isInstantiable()) {
            throw new ContainerException;
        }

        $ref_constructor = $ref_class->getConstructor();

        $params = [];

        // コンストラクタの引数から依存性を判断
        if ($ref_constructor instanceof ReflectionMethod) {
            foreach ($ref_constructor->getParameters() as $ref_param) {
                $param_name = $ref_param->getName();
                $params[$param_name] = $this->getDependency($ref_param);
            }
        }

        return new $id(...$params);
    }

    /**
     * ReflectionParameterを分析し、必要な依存性を取得する
     *
     * @param ReflectionParameter $ref_param
     * @return mixed
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected function getDependency(ReflectionParameter $ref_param): mixed
    {
        // Inject属性があれば参照
        // Inject属性がなければ型宣言から判別
        // 型が指定されていなければnull
        $id = $this->hasInjectAttribute($ref_param)
            ? $this->getInjectAttribute($ref_param)->getId()
            : $ref_param->getType()?->getName();

        // 型が指定されていなければ依存解決エラー
        if ($id === null) {
            throw new ContainerException;
        }

        return $this->get($id);
    }

    /**
     * 引数がInject属性を持っているかどうか
     *
     * @param ReflectionParameter $ref_param
     * @return boolean
     */
    protected function hasInjectAttribute(ReflectionParameter $ref_param): bool
    {
        return isset($ref_param->getAttributes(Inject::class)[0]);
    }

    /**
     * 引数が持っているInject属性のインスタンスを返す
     *
     * @param ReflectionParameter $ref_param
     * @return Inject
     */
    protected function getInjectAttribute(ReflectionParameter $ref_param): Inject
    {
        $ref_attrs = $ref_param->getAttributes(Inject::class);

        if ($ref_attrs === []) {
            throw new ContainerException;
        }

        return $ref_attrs[0]->newInstance();
    }
}