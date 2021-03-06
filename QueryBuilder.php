<?php

declare(strict_types=1);

/*
 * Studio 107 (c) 2018 Maxim Falaleev
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mindy\QueryBuilder;

use Doctrine\DBAL\Connection;
use Exception;
use Mindy\QueryBuilder\Aggregation\Aggregation;
use Mindy\QueryBuilder\Q\Q;
use Mindy\QueryBuilder\Q\QAnd;
use Mindy\QueryBuilder\Utils\TableNameResolver;

class QueryBuilder implements QueryBuilderInterface
{
    const SELECT = 'SELECT';
    const INSERT = 'INSERT';
    const UPDATE = 'UPDATE';
    const DELETE = 'DELETE';

    /**
     * @var null|string sql query type SELECT|UPDATE|DELETE
     */
    protected $type = self::SELECT;

    protected $tablePrefix = '';
    /**
     * @var BaseAdapter
     */
    protected $adapter;
    /**
     * @var LookupBuilderInterface
     */
    protected $lookupBuilder;
    /**
     * Counter of joined tables aliases.
     *
     * @var int
     */
    private $_aliasesCount = 0;
    /**
     * @var array
     */
    private $_joinAlias = [];
    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var array the array of SQL parts collected
     */
    private $sqlParts = [
        'select' => [],
        'from' => [
            'table' => null,
            'alias' => null,
        ],
        'distinct' => [],
        'join' => [],
        'set' => [],
        'where' => [
            'and' => [],
            'or' => [],
        ],
        'groupBy' => [],
        'having' => null,
        'limit' => null,
        'offset' => null,
        'orderBy' => [
            'columns' => [],
            'options' => null,
        ],
        'values' => [],
        'union' => [],
    ];

    /**
     * @return \Doctrine\DBAL\Platforms\AbstractPlatform
     */
    public function getDatabasePlatform()
    {
        return $this->connection->getDatabasePlatform();
    }

    /**
     * QueryBuilder constructor.
     *
     * @param Connection             $connection
     * @param BaseAdapter            $adapter
     * @param LookupBuilderInterface $lookupBuilder
     */
    public function __construct(Connection $connection, BaseAdapter $adapter, LookupBuilderInterface $lookupBuilder)
    {
        $this->connection = $connection;
        $this->adapter = $adapter;
        $this->lookupBuilder = $lookupBuilder;
    }

    /**
     * @param LookupCollectionInterface $lookupCollection
     *
     * @return $this
     */
    public function addLookupCollection(LookupCollectionInterface $lookupCollection)
    {
        $this->lookupBuilder->addLookupCollection($lookupCollection);

        return $this;
    }

    public function distinct($distinct)
    {
        $this->sqlParts['distinct'] = $distinct;

        return $this;
    }

    /**
     * @param Aggregation $aggregation
     * @param string      $columnAlias
     *
     * @return string
     */
    protected function buildSelectFromAggregation(Aggregation $aggregation)
    {
        $tableAlias = $this->getAlias();
        $rawColumns = $aggregation->getFields();
        $newSelect = $this->getLookupBuilder()->buildJoin($this, $rawColumns);
        if (false === $newSelect) {
            if (empty($tableAlias) || '*' === $rawColumns) {
                $columns = $rawColumns;
            } else {
                $columns = $tableAlias.'.'.$rawColumns;
            }
        } else {
            list($alias, $joinColumn) = $newSelect;
            $columns = $alias.'.'.$joinColumn;
        }
        $fieldsSql = $this->buildColumns($columns);
        $aggregation->setFieldsSql($fieldsSql);

        return $this->getAdapter()->quoteSql($aggregation->toSQL());
    }

    /**
     * @param $columns
     *
     * @throws \Doctrine\DBAL\DBALException
     *
     * @return array|string
     */
    protected function buildColumns($columns)
    {
        if (!is_array($columns)) {
            if ($columns instanceof Aggregation) {
                $columns->setFieldsSql($this->buildColumns($columns->getFields()));

                return $this->getAdapter()->quoteSql($columns->toSQL());
            } elseif (false !== strpos($columns, '(')) {
                return $this->getAdapter()->quoteSql($columns);
            }
            $columns = preg_split('/\s*,\s*/', $columns, -1, PREG_SPLIT_NO_EMPTY);
        }
        foreach ($columns as $i => $column) {
            if ($column instanceof Expression) {
                $columns[$i] = $this->getAdapter()->quoteSql($column->toSQL());
            } elseif (false !== strpos($column, 'AS')) {
                if (preg_match('/^(.*?)(?i:\s+as\s+|\s+)([\w\-_\.]+)$/', $column, $matches)) {
                    list(, $rawColumn, $rawAlias) = $matches;
                    $columns[$i] = $this->getQuotedName($rawColumn).' AS '.$this->getQuotedName($rawAlias);
                }
            } elseif (false === strpos($column, '(')) {
                $columns[$i] = $this->getQuotedName($column);
            }
        }

        return is_array($columns) ? implode(', ', $columns) : $columns;
    }

    /**
     * @throws \Doctrine\DBAL\DBALException
     *
     * @return string
     */
    protected function buildSelect()
    {
        if (empty($this->sqlParts['select'])) {
            $this->sqlParts['select'] = ['*'];
        }

        $builder = $this->getLookupBuilder();
        $columns = [];
        foreach ($this->sqlParts['select'] as $alias => $column) {
            if ($column instanceof Aggregation) {
                $columns[$alias] = $this->buildSelectFromAggregation($column);
            } elseif (is_string($column)) {
                if (false !== strpos($column, 'SELECT')) {
                    $columns[$alias] = $column;
                } else {
                    $columns[$alias] = $this->addColumnAlias($builder->fetchColumnName($column));
                }
            } else {
                $columns[$alias] = $column;
            }
        }

        $selectSql = $this->sqlParts['distinct'] ? 'SELECT DISTINCT ' : 'SELECT ';
        if (empty($columns)) {
            return $selectSql.'*';
        }

        if (false === is_array($columns)) {
            $columns = [$columns];
        }

        $select = [];
        foreach ($columns as $column => $subQuery) {
            if ($subQuery instanceof ToSqlInterface) {
                $subQuery = $subQuery->toSQL();
            } else {
                $subQuery = $this->getAdapter()->quoteSql($subQuery);
            }

            if (is_numeric($column)) {
                $column = $subQuery;
                $subQuery = '';
            }

            if (!empty($subQuery)) {
                if (false !== strpos($subQuery, 'SELECT')) {
                    $value = $this->columnAs(
                        '('.$subQuery.')',
                        $this->getQuotedName($column)
                    );
                } else {
                    $value = $this->columnAs(
                        $this->getQuotedName($subQuery),
                        $this->getQuotedName($column)
                    );
                }
            } else {
                $value = $this->normalizeColumns($column);
            }
            $select[] = $value;
        }

        return $selectSql.implode(', ', $select);
    }

    /**
     * @param string $str
     *
     * @throws \Doctrine\DBAL\DBALException
     *
     * @return string
     */
    public function getQuotedName($str): string
    {
        $platform = $this->connection->getDatabasePlatform();
        $keywords = $platform->getReservedKeywordsList();
        $parts = explode('.', (string) $str);
        foreach ($parts as $k => $v) {
            $parts[$k] = ($keywords->isKeyword($v)) ? $platform->quoteIdentifier($v) : $v;
        }

        return implode('.', $parts);
    }

    public function columnAs($x, $y): string
    {
        return $x.' AS '.$y;
    }

    /**
     * @param string $input
     *
     * @return bool
     */
    protected function columnHasAlias(string $input): bool
    {
        return false !== strpos($input, 'AS');
    }

    /**
     * @param string $input
     *
     * @throws \Doctrine\DBAL\DBALException
     *
     * @return string
     */
    protected function normalizeColumn(string $input): string
    {
        if ($this->columnHasAlias($input)) {
            list($rawColumn, $rawAlias) = explode('AS', $input);
        } else {
            $rawColumn = $input;
            $rawAlias = '';
        }

        $column = $this->getQuotedName(trim($rawColumn));

        return empty($rawAlias) ?
            $column :
            $this->columnAs($column, $this->getQuotedName(trim($rawAlias)));
    }

    /**
     * @param string $input
     *
     * @throws \Doctrine\DBAL\DBALException
     *
     * @return string
     */
    protected function normalizeColumns(string $input): string
    {
        $result = [];
        foreach (explode(',', $input) as $column) {
            $result[] = $this->normalizeColumn($column);
        }

        return implode(', ', $result);
    }

    /**
     * @param $select
     * @param null $distinct
     *
     * @return $this
     */
    public function select($select, $distinct = null)
    {
        if (null !== $distinct) {
            $this->distinct($distinct);
        }

        if (empty($select)) {
            return $this;
        }

        $builder = $this->getLookupBuilder();
        $parts = [];
        if (is_array($select)) {
            foreach ($select as $key => $part) {
                if (is_string($part)) {
                    $newSelect = $builder->buildJoin($this, $part);
                    if ($newSelect) {
                        list($alias, $column) = $newSelect;
                        $parts[$key] = $alias.'.'.$column;
                    } else {
                        $parts[$key] = $part;
                    }
                } else {
                    $parts[$key] = $part;
                }
            }
        } elseif (is_string($select)) {
            $newSelect = $builder->buildJoin($this, $select);
            if ($newSelect) {
                list($alias, $column) = $newSelect;
                $parts[$alias] = $column;
            } else {
                $parts[] = $select;
            }
        } else {
            $parts[] = $select;
        }

        $this->sqlParts['select'] = $parts;

        return $this;
    }

    /**
     * @param $table string
     * @param null $alias
     *
     * @return $this
     */
    public function table($table, $alias = null)
    {
        $this->sqlParts['from']['table'] = $table;

        if ($alias) {
            $this->sqlParts['from']['alias'] = $alias;
        }

        return $this;
    }

    /**
     * @param $alias string join alias
     *
     * @return bool
     */
    public function hasJoin($alias)
    {
        return array_key_exists($alias, $this->sqlParts['join']);
    }

    /**
     * @param int $page
     * @param int $pageSize
     *
     * @return $this
     */
    public function paginate($page = 1, $pageSize = 10)
    {
        return $this
            ->limit($pageSize)
            ->offset($page > 1 ? $pageSize * ($page - 1) : 0);
    }

    public function limit($limit)
    {
        $this->sqlParts['limit'] = $limit;

        return $this;
    }

    /**
     * @param $offset
     *
     * @return $this
     */
    public function offset($offset)
    {
        $this->sqlParts['offset'] = $offset;

        return $this;
    }

    /**
     * @return LookupBuilderInterface
     */
    public function getLookupBuilder(): LookupBuilderInterface
    {
        return $this->lookupBuilder;
    }

    /**
     * @return AdapterInterface
     */
    public function getAdapter(): AdapterInterface
    {
        return $this->adapter;
    }

    /**
     * @param $joinType string LEFT JOIN, RIGHT JOIN, etc...
     * @param $tableName string
     * @param array  $on    link columns
     * @param string $alias string
     *
     * @throws Exception
     *
     * @return $this
     */
    public function join($joinType, $tableName = '', $on = [], $alias = '')
    {
        if (is_string($joinType) && empty($tableName)) {
            $this->sqlParts['join'][] = $this->getAdapter()->quoteSql($joinType);
        } elseif ($tableName instanceof self) {
            $this->sqlParts['join'][] = $this->sqlJoin($joinType, $tableName, $on, $alias);
        } else {
            $this->sqlParts['join'][$tableName] = $this->sqlJoin($joinType, $tableName, $on, $alias);

            $this->_joinAlias[$tableName] = $alias;
        }

        return $this;
    }

    /**
     * @param string $sql
     *
     * @return $this
     */
    public function joinRaw(string $sql)
    {
        $this->sqlParts['join'][] = $this->getAdapter()->quoteSql($sql);

        return $this;
    }

    /**
     * @param string|array $columns columns
     *
     * @return $this
     */
    public function group($columns)
    {
        if (false === is_array($columns)) {
            $columns = explode(',', $columns);
        }

        $this->sqlParts['groupBy'] = array_merge(
            $this->sqlParts['groupBy'],
            $columns
        );

        return $this;
    }

    /**
     * @param array|string $columns columns
     * @param null         $options
     *
     * @return $this
     */
    public function order($columns, $options = null)
    {
        $this->sqlParts['orderBy'] = [
            'columns' => $columns,
            'options' => $options,
        ];

        return $this;
    }

    /**
     * Clear properties.
     *
     * @return $this
     */
    public function clear()
    {
        $this->type = self::SELECT;

        $this->resetQueryParts();

        return $this;
    }

    /**
     * @return array
     */
    protected function defaultQueryParts(): array
    {
        return [
            'select' => [],
            'from' => [
                'table' => null,
                'alias' => null,
            ],
            'distinct' => [],
            'join' => [],
            'set' => [],
            'where' => [
                'and' => [],
                'or' => [],
            ],
            'groupBy' => [],
            'having' => null,
            'limit' => null,
            'offset' => null,
            'orderBy' => [
                'columns' => [],
                'options' => null,
            ],
            'values' => [],
            'union' => [],
        ];
    }

    /**
     * Resets SQL parts.
     *
     * @return $this this QueryBuilder instance
     */
    public function resetQueryParts()
    {
        foreach (array_keys($this->sqlParts) as $key) {
            $this->resetQueryPart($key);
        }

        return $this;
    }

    /**
     * Resets a single SQL part.
     *
     * @param string $queryPartName
     *
     * @return $this this QueryBuilder instance
     */
    public function resetQueryPart($queryPartName)
    {
        $defaults = $this->defaultQueryParts();
        $this->sqlParts[$queryPartName] = $defaults[$queryPartName];

        return $this;
    }

    /**
     * @return $this
     */
    public function insert()
    {
        $this->type = self::INSERT;

        return $this;
    }

    /**
     * @return $this
     */
    public function update()
    {
        $this->type = self::UPDATE;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getAlias()
    {
        return $this->sqlParts['from']['alias'];
    }

    /**
     * @param string $alias
     *
     * @return $this
     */
    public function setAlias($alias)
    {
        $this->sqlParts['from']['alias'] = $alias;

        return $this;
    }

    public function buildCondition($condition, &$params = [])
    {
        if (!is_array($condition)) {
            return (string) $condition;
        } elseif (empty($condition)) {
            return '';
        }

        if (isset($condition[0]) && is_string($condition[0])) {
            $operatorRaw = array_shift($condition);
            $operator = strtoupper($operatorRaw);

            return $this->buildAndCondition($operator, $condition, $params);
        }

        return $this->parseCondition($condition);
    }

    public function getJoinAlias($tableName)
    {
        return $this->_joinAlias[$tableName];
    }

    /**
     * @param $condition
     *
     * @return string
     */
    protected function parseCondition($condition)
    {
        $tableAlias = $this->getAlias();
        $parts = [];

        if ($condition instanceof QueryBuilderAwareInterface) {
            $condition->setQueryBuilder($this);
        }

        if ($condition instanceof Expression) {
            $parts[] = $this->getAdapter()->quoteSql($condition->toSQL());
        } elseif ($condition instanceof Q) {
            $condition->setLookupBuilder($this->getLookupBuilder());
            $condition->setAdapter($this->getAdapter());
            $condition->setTableAlias($tableAlias);
            $parts[] = $condition->toSQL();
        } elseif ($condition instanceof ToSqlInterface) {
            $parts[] = $condition->toSQL();
        } elseif (is_array($condition)) {
            foreach ($condition as $key => $value) {
                if ($value instanceof Q) {
                    $parts[] = $this->parseCondition($value);
                } else {
                    list($lookup, $column, $lookupValue) = $this->lookupBuilder->parseLookup($this, $key, $value);
                    $column = $this->getLookupBuilder()->fetchColumnName($column);
                    if (false === empty($tableAlias) && false === strpos($column, '.')) {
                        $column = $tableAlias.'.'.$column;
                    }
                    $parts[] = $this->lookupBuilder->runLookup($this->getAdapter(), $lookup, $column, $lookupValue);
                }
            }
        } elseif (is_string($condition)) {
            $parts[] = $condition;
        } elseif ($condition instanceof Expression) {
            $parts[] = $condition->toSQL();
        }

        if (1 === count($parts)) {
            return $parts[0];
        }

        return '('.implode(') AND (', $parts).')';
    }

    public function buildAndCondition($operator, $operands, &$params)
    {
        $parts = [];
        foreach ($operands as $operand) {
            if (is_array($operand)) {
                $operand = $this->buildCondition($operand, $params);
            } else {
                $operand = $this->parseCondition($operand);
            }
            if ('' !== $operand) {
                $parts[] = $this->getAdapter()->quoteSql($operand);
            }
        }
        if (!empty($parts)) {
            return '('.implode(') '.$operator.' (', $parts).')';
        }

        return '';
    }

    /**
     * @param $condition
     *
     * @return $this
     */
    public function where($condition)
    {
        $this->sqlParts['where']['and'][] = $condition;

        return $this;
    }

    /**
     * @param $condition
     *
     * @return $this
     */
    public function orWhere($condition)
    {
        $this->sqlParts['where']['or'][] = $condition;

        return $this;
    }

    /**
     * @return array
     */
    public function buildWhereTree()
    {
        $where = [];
        foreach ($this->sqlParts['where']['and'] as $condition) {
            if (empty($where)) {
                $where = ['and', $condition];
            } else {
                $where = ['and', $where, ['and', $condition]];
            }
        }

        foreach ($this->sqlParts['where']['or'] as $condition) {
            if (empty($where)) {
                $where = ['or', $condition];
            } else {
                $where = ['or', $where, ['and', $condition]];
            }
        }

        return $where;
    }

    public function buildWhere()
    {
        $params = [];
        $sql = $this->buildCondition($this->buildWhereTree(), $params);

        return empty($sql) ? '' : ' WHERE '.$sql;
    }

    protected function getSQLForSelect(): string
    {
        $where = $this->buildWhere();
        $order = $this->buildOrder();
        $union = $this->buildUnion();

        $select = $this->buildSelect();
        $from = $this->buildFrom();
        $join = $this->buildJoin();
        $group = $this->buildGroup();
        $having = $this->buildHaving();
        $limitOffset = $this->buildLimitOffset();

        return strtr('{select}{from}{join}{where}{group}{having}{order}{limit_offset}{union}', [
            '{select}' => $select,
            '{from}' => $from,
            '{where}' => $where,
            '{group}' => $group,
            '{order}' => empty($union) ? $order : '',
            '{having}' => $having,
            '{join}' => $join,
            '{limit_offset}' => $limitOffset ? ' '.$limitOffset : '',
            '{union}' => empty($union) ? '' : $union.$order,
        ]);
    }

    public function getSQLForDelete(): string
    {
        return sprintf(
            'DELETE%s%s',
            $this->buildFrom(),
            $this->buildWhere()
        );
    }

    public function getSQLForUpdate(): string
    {
        $this->setAlias(null);

        $table = TableNameResolver::getTableName(
            $this->sqlParts['from']['table'],
            $this->tablePrefix
        );

        $parts = [];
        $rows = $this->sqlParts['values'];
        foreach (array_shift($rows) as $column => $value) {
            if ($value instanceof ToSqlInterface) {
                $val = $this->getAdapter()->quoteSql($value->toSQL());
            } else {
                $val = $this->getAdapter()->getSqlType($value);
            }
            $parts[] = $this->getQuotedName($column).' = '.$val;
        }

        return sprintf(
            'UPDATE %s SET %s%s',
            $this->getQuotedName($table),
            implode(', ', $parts),
            $this->buildWhere()
        );
    }

    /**
     * @throws \Doctrine\DBAL\DBALException
     *
     * @return string
     */
    public function toSQL(): string
    {
        switch ($this->type) {
            case self::INSERT:
                $sql = $this->getSQLForInsert();
                break;

            case self::DELETE:
                $sql = $this->getSQLForDelete();
                break;

            case self::UPDATE:
                $sql = $this->getSQLForUpdate();
                break;

            case self::SELECT:
            default:
                $sql = $this->getSQLForSelect();
                break;
        }

        return $sql;
    }

    public function buildHaving()
    {
        if (empty($this->_having)) {
            return '';
        }

        if ($this->sqlParts['having'] instanceof Q) {
            $sql = $this->sqlParts['having']->toSQL();
        } else {
            $sql = $this->quoteSql($this->sqlParts['having']);
        }

        return empty($sql) ? '' : ' HAVING '.$sql;
    }

    /**
     * @return string
     */
    public function buildLimitOffset(): string
    {
        $sql = $this
            ->connection
            ->createQueryBuilder()
            ->setMaxResults($this->sqlParts['limit'])
            ->setFirstResult($this->sqlParts['offset']);

        return trim(str_replace('SELECT', '', $sql));
    }

    public function buildUnion()
    {
        $sql = '';
        foreach ($this->sqlParts['union'] as $part) {
            list($union, $all) = $part;

            if (empty($union)) {
                continue;
            }

            if ($union instanceof self) {
                $unionSQL = $union->order(null)->toSQL();
            } else {
                $unionSQL = $this->getAdapter()->quoteSql($union);
            }

            $sql .= ($all ? ' UNION ALL' : ' UNION').' ('.$unionSQL.')';
        }

        return empty($sql) ? '' : $sql;
    }

    /**
     * @param $joinType string
     * @param $tableName string
     * @param $on string|array
     * @param $alias string
     *
     * @throws \Doctrine\DBAL\DBALException
     *
     * @return string
     */
    public function sqlJoin($joinType, $tableName, $on, $alias)
    {
        if (is_string($tableName)) {
            $tableName = TableNameResolver::getTableName($tableName, $this->tablePrefix);
        } elseif ($tableName instanceof self) {
            $tableName = $tableName->toSQL();
        }

        $onSQL = [];
        if (is_string($on)) {
            $onSQL[] = $this->getAdapter()->quoteSql($on);
        } else {
            foreach ($on as $leftColumn => $rightColumn) {
                if ($rightColumn instanceof Expression) {
                    $onSQL[] = $this->getQuotedName($leftColumn).'='.$this->getAdapter()->quoteSql($rightColumn->toSQL());
                } else {
                    $onSQL[] = $this->getQuotedName($leftColumn).'='.$this->getQuotedName($rightColumn);
                }
            }
        }

        if (false !== strpos($tableName, 'SELECT')) {
            return $joinType.' ('.$this->getAdapter()->quoteSql($tableName).')'.(empty($alias) ? '' : ' AS '.$this->getQuotedName($alias)).' ON '.implode(',', $onSQL);
        }

        return $joinType.' '.$this->getQuotedName($tableName).(empty($alias) ? '' : ' AS '.$this->getQuotedName($alias)).' ON '.implode(',', $onSQL);
    }

    public function getSchema()
    {
        return $this->schema;
    }

    /**
     * @param string|ToSqlInterface $having
     *
     * @return $this
     */
    public function having($having)
    {
        if (false == ($having instanceof Q)) {
            $having = new QAnd($having);
        }
        $having->setLookupBuilder($this->getLookupBuilder());
        $having->setAdapter($this->getAdapter());

        $this->sqlParts['having'] = $having;

        return $this;
    }

    public function union($union, $all = false)
    {
        $this->sqlParts['union'][] = [$union, $all];

        return $this;
    }

    /**
     * Makes alias for joined table.
     *
     * @param $table
     * @param bool $increment
     *
     * @return string
     */
    public function makeAliasKey($table, $increment = true)
    {
        //        if ($increment) {
//            $this->_aliasesCount += 1;
//        }
        return strtr('{table}_{count}', [
            '{table}' => TableNameResolver::getTableName($table),
            '{count}' => $this->_aliasesCount + 1,
        ]);
    }

    public function getJoin($tableName)
    {
        return $this->sqlParts['join'][$tableName];
    }

    /**
     * @param $column
     *
     * @return string
     */
    protected function addColumnAlias($column)
    {
        $tableAlias = $this->getAlias();
        if (empty($tableAlias)) {
            return $column;
        }

        if (false === strpos($column, '.') &&
            false === strpos($column, '(') &&
            false === strpos($column, 'SELECT')
        ) {
            return $tableAlias.'.'.$column;
        }

        return $column;
    }

    protected function applyTableAlias($column)
    {
        // If column already has alias - skip
        if (false === strpos($column, '.')) {
            $tableAlias = $this->getAlias();

            return empty($tableAlias) ? $column : $tableAlias.'.'.$column;
        }

        return $column;
    }

    protected function buildJoin()
    {
        if (empty($this->sqlParts['join'])) {
            return '';
        }
        $join = [];
        foreach ($this->sqlParts['join'] as $part) {
            $join[] = $part;
        }

        return ' '.implode(' ', $join);
    }

    /**
     * @param string $order
     *
     * @return array
     */
    protected function buildOrderJoin(string $order): array
    {
        list($column, $direction) = $this->formatOrderDirection($order);

        $lookupBuilder = $this->getLookupBuilder();

        $column = $lookupBuilder->fetchColumnName($column);
        $newOrder = $lookupBuilder->buildJoin($this, $column);
        if (false === $newOrder) {
            return [$column, $direction];
        }

        list($alias, $column) = $newOrder;

        return [
            $alias.'.'.$column,
            $direction,
        ];
    }

    /**
     * @param string $input
     *
     * @return array
     */
    protected function formatOrderDirection(string $input): array
    {
        if (0 === strpos($input, '-', 0)) {
            return [substr($input, 1), 'DESC'];
        }

        return [$input, 'ASC'];
    }

    protected function prepareOrderFromString(string $input): array
    {
        $columns = preg_split('/\s*,\s*/', $input, -1, PREG_SPLIT_NO_EMPTY);

        return array_map(function ($raw) {
            if (false === strpos($raw, ' ')) {
                return $this->getQuotedName($raw);
            }

            list($column, $direction) = explode(' ', $raw);

            return $this->getQuotedName($column).' '.$direction;
        }, $columns);
    }

    /**
     * @return string
     */
    public function buildOrder()
    {
        $orderColumns = $this->sqlParts['orderBy']['columns'];
        $options = $this->sqlParts['orderBy']['options'];

        if (empty($orderColumns)) {
            return '';
        }

        $order = [];
        if (is_array($orderColumns)) {
            foreach ($orderColumns as $column) {
                if ('?' === $column) {
                    $order[] = $this->getAdapter()->getRandomOrder();
                } else {
                    list($newColumn, $direction) = $this->buildOrderJoin($column);
                    $order[] = $this->applyTableAlias($newColumn).' '.$direction;
                }
            }
        } elseif (is_string($orderColumns)) {
            $order = $this->prepareOrderFromString($orderColumns);
        } else {
            $order[] = implode(', ', $this->buildOrderJoin($this->_order));
        }

        $sql = implode(', ', $order);

        if (false === empty($options)) {
            $sql .= $options;
        }

        return empty($sql) ? '' : ' ORDER BY '.$sql;
    }

    /**
     * @throws \Doctrine\DBAL\DBALException
     *
     * @return string
     */
    public function buildGroup(): string
    {
        if (empty($this->sqlParts['groupBy'])) {
            return '';
        }

        $group = [];
        foreach ($this->sqlParts['groupBy'] as $column) {
            $group[] = $this->getQuotedName(trim($column));
        }

        return ' GROUP BY '.implode(', ', $group);
    }

    public function buildFrom()
    {
        if (empty($this->sqlParts['from']['table'])) {
            return '';
        }

        if (!empty($this->sqlParts['from']['alias']) && !is_array($this->sqlParts['from']['table'])) {
            $tables = [
                $this->sqlParts['from']['alias'] => $this->sqlParts['from']['table'],
            ];
        } else {
            $tables = (array) $this->sqlParts['from']['table'];
        }

        $quotedTableNames = [];
        foreach ($tables as $tableAlias => $table) {
            if ($table instanceof self) {
                $tableRaw = $table->toSQL();
            } else {
                $tableRaw = TableNameResolver::getTableName($table);
            }
            if (false !== strpos($tableRaw, 'SELECT')) {
                $quotedTableNames[] = '('.$tableRaw.')'.(is_numeric($tableAlias) ? '' : ' AS '.$this->getQuotedName($tableAlias));
            } else {
                $quotedTableNames[] = $this->getQuotedName($tableRaw).(is_numeric($tableAlias) ? '' : ' AS '.$this->getQuotedName($tableAlias));
            }
        }

        $sql = implode(', ', $quotedTableNames);

        return empty($sql) ? '' : ' FROM '.$sql;
    }

    /**
     * @return $this
     */
    public function delete()
    {
        $this->type = self::DELETE;

        return $this;
    }

    /**
     * @param array $values
     *
     * @return $this
     */
    public function values(array $values)
    {
        $this->sqlParts['values'] = is_array(current($values)) ? $values : [$values];

        return $this;
    }

    /**
     * @throws \Doctrine\DBAL\DBALException
     *
     * @return string
     */
    public function getSQLForInsert()
    {
        $columns = array_map(function ($column) {
            return $this->getQuotedName($column);
        }, array_keys(current($this->sqlParts['values'])));

        $values = [];
        foreach ($this->sqlParts['values'] as $part) {
            $record = array_map(function ($value) {
                if ($value instanceof ToSqlInterface) {
                    return $value->toSQL();
                }

                return $this->getAdapter()->getSqlType($value);
            }, $part);

            $values[] = '('.implode(', ', $record).')';
        }

        $table = $this->getQuotedName($this->sqlParts['from']['table']);

        return sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $table,
            implode(', ', $columns),
            implode(', ', $values)
        );
    }
}
