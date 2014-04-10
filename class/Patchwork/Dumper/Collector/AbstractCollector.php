<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Patchwork\Dumper\Collector;

use Patchwork\Dumper\Exception\ThrowingCasterException;

/**
 * @author Nicolas Grekas <p@tchwork.com>
 */
abstract class AbstractCollector implements CollectorInterface
{
    public static $defaultCasters = array(
        'o:Closure'        => 'Patchwork\Dumper\Caster\ReflectionCaster::castClosure',
        'o:Reflector'      => 'Patchwork\Dumper\Caster\ReflectionCaster::castReflector',

        'o:Doctrine\Common\Proxy\Proxy' => 'Patchwork\Dumper\Caster\DoctrineCaster::castCommonProxy',
        'o:Doctrine\ORM\Proxy\Proxy'    => 'Patchwork\Dumper\Caster\DoctrineCaster::castOrmProxy',

        'o:ErrorException' => 'Patchwork\Dumper\Caster\ExceptionCaster::castErrorException',
        'o:Exception'      => 'Patchwork\Dumper\Caster\ExceptionCaster::castException',
        'o:Patchwork\Dumper\Exception\ThrowingCasterException'
                           => 'Patchwork\Dumper\Caster\ExceptionCaster::castThrowingCasterException',

        'o:PDO'            => 'Patchwork\Dumper\Caster\PdoCaster::castPdo',
        'o:PDOStatement'   => 'Patchwork\Dumper\Caster\PdoCaster::castPdoStatement',

        'o:SplDoublyLinkedList' => 'Patchwork\Dumper\Caster\SplCaster::castSplDoublyLinkedList',
        'o:SplFixedArray'       => 'Patchwork\Dumper\Caster\SplCaster::castSplFixedArray',
        'o:SplHeap'             => 'Patchwork\Dumper\Caster\SplCaster::castSplIterator',
        'o:SplObjectStorage'    => 'Patchwork\Dumper\Caster\SplCaster::castSplObjectStorage',
        'o:SplPriorityQueue'    => 'Patchwork\Dumper\Caster\SplCaster::castSplIterator',

        'r:dba'            => 'Patchwork\Dumper\Caster\ResourceCaster::castDba',
        'r:dba persistent' => 'Patchwork\Dumper\Caster\ResourceCaster::castDba',
        'r:gd'             => 'Patchwork\Dumper\Caster\ResourceCaster::castGd',
        'r:mysql link'     => 'Patchwork\Dumper\Caster\ResourceCaster::castMysqlLink',
        'r:process'        => 'Patchwork\Dumper\Caster\ResourceCaster::castProcess',
        'r:stream'         => 'Patchwork\Dumper\Caster\ResourceCaster::castStream',
    );

    protected $maxItems = 1000;
    protected $maxString = 10000;

    private $casters = array();
    private $data = array(array(null));
    private $prevErrorHandler = null;

    public function __construct(array $defaultCasters = null)
    {
        if (!isset($defaultCasters)) {
            $defaultCasters = static::$defaultCasters;
        }
        $this->addCasters($defaultCasters);
    }

    public function addCasters(array $casters)
    {
        foreach ($casters as $type => $callback) {
            $this->casters[strtolower($type)][] = $callback;
        }
    }

    public function setMaxItems($maxItems)
    {
        $this->maxItems = (int) $maxItems;
    }

    public function setMaxString($maxString)
    {
        $this->maxString = (int) $maxString;
    }

    public function collect($var)
    {
        $this->prevErrorHandler = set_error_handler(array($this, 'handleError'));
        try {
            $data = $this->doCollect($var);
        } catch (\Exception $e) {
        }
        restore_error_handler();
        $this->prevErrorHandler = null;

        if (isset($e)) {
            throw $e;
        }

        return new Data($data);
    }

    abstract protected function doCollect($var);

    protected function castObject($class, $obj)
    {
        if (method_exists($obj, '__debugInfo')) {
            if (!$a = $this->callCaster(array($this, '__debugInfo'), $obj, array())) {
                $a = (array) $obj;
            }
        } else {
            $a = (array) $obj;
        }

        $p = array($class => $class)
            + class_parents($obj)
            + class_implements($obj)
            + array('*' => '*');

        foreach (array_reverse($p) as $p) {
            if (!empty($this->casters[$p = 'o:'.strtolower($p)])) {
                foreach ($this->casters[$p] as $p) {
                    $a = $this->callCaster($p, $obj, $a);
                }
            }
        }

        return $a;
    }

    protected function castResource($type, $res)
    {
        $a = array();

        if (!empty($this->casters['r:'.$type])) {
            foreach ($this->casters['r:'.$type] as $c) {
                $a = $this->callCaster($c, $res, $a);
            }
        }

        return $a;
    }

    private function callCaster($callback, $obj, $a)
    {
        try {
            // Ignore invalid $callback
            $cast = @call_user_func($callback, $obj, $a);

            if (is_array($cast)) {
                $a = $cast;
            }
        } catch (\Exception $e) {
            $a["\0~\0⚠"] = new ThrowingCasterException($callback, $e);
        }

        return $a;
    }

    /**
     * @internal
     */
    public function handleError($type, $msg, $file, $line, $context)
    {
        if (E_RECOVERABLE_ERROR === $type || E_USER_ERROR === $type) {
            // Collector never dies
            throw new \ErrorException($msg, 0, $type, $file, $line);
        }

        if ($this->prevErrorHandler) {
            return call_user_func_array($this->prevErrorHandler, array($type, $msg, $file, $line, $context));
        }

        return false;
    }
}
