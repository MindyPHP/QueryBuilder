<?php

declare(strict_types=1);

/*
 * Studio 107 (c) 2018 Maxim Falaleev
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mindy\QueryBuilder\LookupBuilder;

use Exception;
use Mindy\QueryBuilder\AdapterInterface;
use Mindy\QueryBuilder\LookupBuilderInterface;
use Mindy\QueryBuilder\LookupCollectionInterface;
use Mindy\QueryBuilder\QueryBuilder;

abstract class Base implements LookupBuilderInterface
{
    /**
     * @var string
     */
    protected $default = 'exact';
    /**
     * @var string
     */
    protected $separator = '__';
    /**
     * @var callable|null
     */
    protected $callback = null;
    /**
     * @var callable|null
     */
    protected $joinCallback = null;
    /**
     * @var null|\Closure
     */
    protected $fetchColumnCallback = null;
    /**
     * @var LookupCollectionInterface[]
     */
    private $_lookupCollections = [];

    public function __clone()
    {
        foreach ($this as $key => $val) {
            if (is_object($val) || is_array($val)) {
                $this->{$key} = unserialize(serialize($val));
            }
        }
    }

    /**
     * @param LookupCollectionInterface $lookupCollection
     *
     * @return $this
     */
    public function addLookupCollection(LookupCollectionInterface $lookupCollection)
    {
        $this->_lookupCollections[] = $lookupCollection;

        return $this;
    }

    /**
     * @param mixed $callback
     *
     * @return $this
     */
    public function setCallback($callback)
    {
        $this->callback = $callback;

        return $this;
    }

    /**
     * @param $callback
     *
     * @return $this
     */
    public function setJoinCallback($callback)
    {
        $this->joinCallback = $callback;

        return $this;
    }

    public function setFetchColumnCallback($callback)
    {
        $this->fetchColumnCallback = $callback;

        return $this;
    }

    public function getCallback()
    {
        return $this->callback;
    }

    public function getJoinCallback()
    {
        return $this->joinCallback;
    }

    public function fetchColumnName($column)
    {
        if (null === $this->fetchColumnCallback) {
            return $column;
        }

        return $this->fetchColumnCallback->run($column);
    }

    public function runCallback(QueryBuilder $queryBuilder, $lookupNodes, $value)
    {
        if (null === $this->callback) {
            return;
        }

        return $this->callback->run($queryBuilder, $this, $lookupNodes, $value);
    }

    public function runJoinCallback(QueryBuilder $queryBuilder, $lookupNodes)
    {
        if (null === $this->joinCallback) {
            return;
        }

        return $this->joinCallback->run($queryBuilder, $this, $lookupNodes);
    }

    public function getSeparator()
    {
        return $this->separator;
    }

    public function getDefault()
    {
        return $this->default;
    }

    /**
     * @param $lookup
     *
     * @return bool
     */
    public function hasLookup($lookup)
    {
        foreach ($this->_lookupCollections as $collection) {
            if ($collection->has($lookup)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param AdapterInterface $adapter
     * @param $lookup
     * @param $column
     * @param $value
     *
     * @throws Exception
     *
     * @return string
     *
     * @exception \Exception
     */
    public function runLookup(AdapterInterface $adapter, $lookup, $column, $value)
    {
        foreach ($this->_lookupCollections as $collection) {
            if ($collection->has($lookup)) {
                return $collection->process($adapter, $lookup, $column, $value);
            }
        }
        throw new Exception('Unknown lookup: '.$lookup.', column: '.$column.', value: '.(is_array($value) ? print_r($value, true) : $value));
    }

    /**
     * @param QueryBuilder $queryBuilder
     * @param array        $where
     *
     * @return mixed
     */
    abstract public function parse(QueryBuilder $queryBuilder, array $where);
}
