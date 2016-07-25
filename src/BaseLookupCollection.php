<?php
/**
 * Created by PhpStorm.
 * User: max
 * Date: 20/06/16
 * Time: 15:38
 */

namespace Mindy\QueryBuilder;

use Exception;
use Mindy\QueryBuilder\Interfaces\IAdapter;
use Mindy\QueryBuilder\Interfaces\ILookupCollection;

class BaseLookupCollection implements ILookupCollection
{
    /**
     * @var array
     */
    protected $lookups = [];

    public function __construct(array $lookups = [])
    {
        $this->lookups = $lookups;
    }

    /**
     * @param array $collection
     * @return $this
     */
    public function addCollection(array $collection)
    {
        $this->lookups = array_merge($this->lookups, $collection);
        return $this;
    }

    /**
     * @return array
     */
    public function getLookups()
    {
        return [
            'exact' => function (IAdapter $adapter, $column, $value) {
                /** @var $adapter \Mindy\QueryBuilder\BaseAdapter */
                if ($value instanceof QueryBuilder) {
                    $sqlValue = '(' . $value->toSQL() . ')';
                } else if (strpos($value, 'SELECT') !== false) {
                    $sqlValue = '(' . $value . ')';
                } else {
                    $sqlValue = $adapter->quoteValue($value);
                }

                if (in_array($adapter->getSqlType($value), ['TRUE', 'FALSE', 'NULL'])) {
                    return $adapter->quoteColumn($column) . ' IS ' . $adapter->getSqlType($value);
                } else {
                    return $adapter->quoteColumn($column) . '=' . $sqlValue;
                }
            },
            'gte' => function (IAdapter $adapter, $column, $value) {
                return $adapter->quoteColumn($column) . '>=' . $adapter->quoteValue($value);
            },
            'gt' => function (IAdapter $adapter, $column, $value) {
                return $adapter->quoteColumn($column) . '>' . $adapter->quoteValue($value);
            },
            'lte' => function (IAdapter $adapter, $column, $value) {
                return $adapter->quoteColumn($column) . '<=' . $adapter->quoteValue($value);
            },
            'lt' => function (IAdapter $adapter, $column, $value) {
                return $adapter->quoteColumn($column) . '<' . $adapter->quoteValue($value);
            },
            'range' => function (IAdapter $adapter, $column, $value) {
                list($min, $max) = $value;
                return $adapter->quoteColumn($column) . ' BETWEEN ' . $adapter->quoteValue($min) . ' AND ' . $adapter->quoteValue($max);
            },
            'isnt' => function (IAdapter $adapter, $column, $value) {
                /** @var $adapter \Mindy\QueryBuilder\BaseAdapter */
                if (in_array($adapter->getSqlType($value), ['TRUE', 'FALSE', 'NULL'])) {
                    return $adapter->quoteColumn($column) . ' IS NOT ' . $adapter->getSqlType($value);
                } else {
                    return $adapter->quoteColumn($column) . '!=' . $adapter->quoteValue($value);
                }
            },
            'isnull' => function (IAdapter $adapter, $column, $value) {
                return $adapter->quoteColumn($column) . ' ' . ((bool)$value ? 'IS NULL' : 'IS NOT NULL');
            },
            'contains' => function (IAdapter $adapter, $column, $value) {
                return $adapter->quoteColumn($column) . ' LIKE ' . $adapter->quoteValue('%' . $value . '%');
            },
            'icontains' => function (IAdapter $adapter, $column, $value) {
                return 'LOWER(' . $adapter->quoteColumn($column) . ') LIKE ' . $adapter->quoteValue('%' . mb_strtolower($value, 'UTF-8') . '%');
            },
            'startswith' => function (IAdapter $adapter, $column, $value) {
                return $adapter->quoteColumn($column) . ' LIKE ' . $adapter->quoteValue($value . '%');
            },
            'istartswith' => function (IAdapter $adapter, $column, $value) {
                return 'LOWER(' . $adapter->quoteColumn($column) . ') LIKE ' . $adapter->quoteValue(mb_strtolower($value, 'UTF-8') . '%');
            },
            'endswith' => function (IAdapter $adapter, $column, $value) {
                return $adapter->quoteColumn($column) . ' LIKE ' . $adapter->quoteValue('%' . $value);
            },
            'iendswith' => function (IAdapter $adapter, $column, $value) {
                return 'LOWER(' . $adapter->quoteColumn($column) . ') LIKE ' . $adapter->quoteValue('%' . mb_strtolower($value, 'UTF-8'));
            },
            'in' => function (IAdapter $adapter, $column, $value) {
                if (is_array($value)) {
                    $quotedValues = array_map(function ($item) use ($adapter) {
                        return $adapter->quoteValue($item);
                    }, $value);
                    $sqlValue = implode(', ', $quotedValues);
                } else if ($value instanceof QueryBuilder) {
                    $sqlValue = $value->toSQL();
                } else {
                    $sqlValue = $adapter->quoteSql($value);
                }
                return $adapter->quoteColumn($column) . ' IN (' . $sqlValue . ')';
            },
            'raw' => function (IAdapter $adapter, $column, $value) {
                return $adapter->quoteColumn($column) . ' ' . $adapter->quoteSql($value);
            },
            'regex' => function (IAdapter $adapter, $column, $value) {
                return 'BINARY ' . $adapter->quoteColumn($column) . ' REGEXP ' . $value;
            },
            'iregex' => function (IAdapter $adapter, $column, $value) {
                return $adapter->quoteColumn($column) . ' REGEXP ' . $value;
            },
            'second' => function (IAdapter $adapter, $column, $value) {
                return 'EXTRACT(SECOND FROM ' . $adapter->quoteColumn($column) . ')=' . $value;
            },
            'year' => function (IAdapter $adapter, $column, $value) {
                return 'EXTRACT(YEAR FROM ' . $adapter->quoteColumn($column) . ')=' . $value;
            },
            'minute' => function (IAdapter $adapter, $column, $value) {
                return 'EXTRACT(MINUTE FROM ' . $adapter->quoteColumn($column) . ')=' . $value;
            },
            'hour' => function (IAdapter $adapter, $column, $value) {
                return 'EXTRACT(HOUR FROM ' . $adapter->quoteColumn($column) . ')=' . $value;
            },
            'day' => function (IAdapter $adapter, $column, $value) {
                return 'EXTRACT(DAY FROM ' . $adapter->quoteColumn($column) . ')=' . $value;
            },
            'month' => function (IAdapter $adapter, $column, $value) {
                return 'EXTRACT(MONTH FROM ' . $adapter->quoteColumn($column) . ')=' . $value;
            },
            'week_day' => function (IAdapter $adapter, $column, $value) {
                return 'EXTRACT(DAYOFWEEK FROM ' . $adapter->quoteColumn($column) . ')=' . $value;
            },
        ];
    }

    /**
     * @param $lookup
     * @return bool
     */
    public function has($lookup)
    {
        return array_key_exists($lookup, array_merge($this->getLookups(), $this->lookups));
    }

    /**
     * @param $adapter
     * @param $lookup
     * @param $column
     * @param $value
     * @return mixed
     * @throws Exception
     */
    public function run($adapter, $lookup, $column, $value)
    {
        $lookups = array_merge($this->getLookups(), $this->lookups);
        /** @var \Closure $closure */
        if ($this->has($lookup)) {
            $closure = $lookups[$lookup];
            return $closure->__invoke($adapter, $column, $value);
        } else {
            throw new Exception("Unknown lookup: " . $lookup);
        }
    }
}