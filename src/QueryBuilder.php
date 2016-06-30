<?php
/**
 * Created by PhpStorm.
 * User: max
 * Date: 20/06/16
 * Time: 10:17
 */

namespace Mindy\QueryBuilder;

use Exception;
use Mindy\QueryBuilder\Interfaces\ILookupBuilder;
use Mindy\QueryBuilder\Q\Q;
use Mindy\QueryBuilder\Q\QAnd;

class QueryBuilder
{
    const TYPE_SELECT = 'SELECT';
    const TYPE_INSERT = 'INSERT';
    const TYPE_UPDATE = 'UPDATE';
    const TYPE_DELETE = 'DELETE';
    const TYPE_DROP_TABLE = 'DROP_TABLE';
    const TYPE_RAW = 'RAW';
    const TYPE_CREATE_TABLE = 'CREATE_TABLE';
    const TYPE_ALTER_COLUMN = 'ALTER_COLUMN';

    protected $update = [];
    protected $insert = [];
    protected $type = null;
    protected $alias = '';
    protected $select = ['*'];
    protected $from = '';
    protected $raw = '';
    protected $limit = '';
    protected $offset = '';
    protected $order = [];
    protected $group = [];
    protected $alterColumn = [];
    /**
     * @var array
     */
    protected $createTable = [];
    /**
     * @var string
     */
    protected $dropTable;
    /**
     * @var array|Q
     */
    protected $where;
    /**
     * @var array|Q
     */
    protected $exclude;
    protected $join = [];
    protected $tablePrefix = '';
    /**
     * @var BaseAdapter
     */
    protected $adapter;
    /**
     * @var ILookupBuilder
     */
    protected $lookupBuilder;

    protected $schema;

    /**
     * QueryBuilder constructor.
     * @param BaseAdapter $adapter
     */
    public function __construct(BaseAdapter $adapter, ILookupBuilder $lookupBuilder, $schema = null)
    {
        $this->adapter = $adapter;
        $this->schema = $schema;

        $lookupBuilder->setQueryBuilder($this);
        $this->lookupBuilder = $lookupBuilder;
    }

    /**
     * @return $this
     */
    public function setTypeSelect()
    {
        $this->type = self::TYPE_SELECT;
        return $this;
    }

    /**
     * @return $this
     */
    public function setTypeUpdate()
    {
        $this->type = self::TYPE_UPDATE;
        return $this;
    }

    /**
     * @return $this
     */
    public function setTypeDelete()
    {
        $this->type = self::TYPE_DELETE;
        return $this;
    }

    /**
     * @return $this
     */
    public function setTypeInsert()
    {
        $this->type = self::TYPE_INSERT;
        return $this;
    }

    /**
     * @return $this
     */
    public function setTypeRaw()
    {
        $this->type = self::TYPE_RAW;
        return $this;
    }

    /**
     * @param $type
     * @return $this
     */
    public function setType($type)
    {
        $this->type = $type;
        return $this;
    }

    /**
     * If type is null return TYPE_SELECT
     * @return string
     */
    public function getType()
    {
        return empty($this->type) ? self::TYPE_SELECT : $this->type;
    }

    /**
     * @param $select array|string columns
     * @return $this
     */
    public function setSelect($select)
    {
        $this->select = $select;
        return $this;
    }

    /**
     * @param $tableName string
     * @return $this
     */
    public function setFrom($tableName)
    {
        $this->from = $tableName;
        return $this;
    }

    /**
     * @param array $where lookups
     * @return $this
     */
    public function setWhere($where)
    {
        if (($where instanceof Q) == false) {
            $where = new QAnd($where);
        }
        $this->where = $where;
        return $this;
    }

    /**
     * @param $where
     * @return $this
     */
    public function addWhere($where)
    {
        if (empty($this->where)) {
            $this->setWhere($where);
        } else {
            if (($where instanceof Q) == false) {
                $where = new QAnd($where);
            }
            $this->where->addWhere($where);
        }
        return $this;
    }

    /**
     * @param array $exclude lookups
     * @return $this
     */
    public function setExclude($exclude)
    {
        if (($exclude instanceof Q) == false) {
            $exclude = new QAnd($exclude);
        }
        $this->exclude = $exclude;
        return $this;
    }

    /**
     * @param $exclude
     * @return $this
     */
    public function addExclude($exclude)
    {
        if (empty($this->exclude)) {
            $this->setExclude($exclude);
        } else {
            if (($exclude instanceof Q) == false) {
                $exclude = new QAnd($exclude);
            }
            $this->exclude->addWhere($exclude);
        }
        return $this;
    }

    /**
     * @param $alias string join alias
     * @return bool
     */
    public function hasJoin($alias)
    {
        return array_key_exists($alias, $this->join);
    }

    public function setLimit($limit)
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * @param $offset
     * @return $this
     */
    public function setOffset($offset)
    {
        $this->offset = $offset;
        return $this;
    }

    protected function generateLimitOffsetSQL()
    {
        $sql = $this->getAdapter()->generateLimitOffsetSQL($this->limit, $this->offset);
        if (!empty($sql)) {
            return ' ' . $sql;
        }

        return '';
    }

    /**
     * Generate SELECT SQL
     * @return string
     */
    protected function generateSelectSQL()
    {
        $rawSelect = (array)$this->select;

        $adapter = $this->getAdapter();
        $alias = $this->getAlias();
        $select = [];
        foreach ($rawSelect as $column => $subQuery) {
            if (is_numeric($column)) {
                $column = $subQuery;
                $subQuery = '';
            }

            if (empty($subQuery) === false && strpos($subQuery, 'SELECT') !== false) {
                $value = '(' . $subQuery . ') AS ' . $adapter->quoteColumn(empty($alias) ? $column : $alias . '.' . $column);
            } else if (empty($subQuery) === false) {
                $value = $adapter->quoteColumn(empty($alias) ? $column : $alias . '.' . $column) . ' AS ' . $subQuery;
            } else if (empty($subQuery) && strpos($column, '.') !== false) {
                $newSelect = [];
                foreach (explode(',', $column) as $item) {
                    /*
                    if (preg_match('/^(.*?)(?i:\s+as\s+|\s+)([\w\-_\.]+)$/', $item, $matches)) {
                        list(, $rawColumn, $rawAlias) = $matches;
                    }
                    */
                    if (strpos($item, 'AS') !== false) {
                        list($rawColumn, $rawAlias) = explode('AS', $item);
                    } else {
                        $rawColumn = $item;
                        $rawAlias = '';
                    }

                    $newSelect[] = empty($rawAlias) ? $adapter->quoteColumn(trim($rawColumn)) : $adapter->quoteColumn(trim($rawColumn)) . ' AS ' . $adapter->quoteColumn(trim($rawAlias));
                }
                $value = implode(',', $newSelect);
            } else if (empty($subQuery) === false) {
                $value = $adapter->quoteColumn(empty($alias) ? $column : $alias . '.' . $column) . ' AS ' . $subQuery;
            } else {
                $value = $adapter->quoteColumn(empty($alias) ? $column : $alias . '.' . $column);
            }
            $select[] = $value;
        }
        return 'SELECT ' . implode(',', $select);
    }

    /**
     * Genertate FROM SQL
     * @return string
     */
    protected function generateFromSQL()
    {
        $alias = $this->getAlias();
        $adapter = $this->getAdapter();
        if (strpos($this->from, 'SELECT') !== false) {
            return ' FROM (' . $this->from . ')' . (empty($this->alias) ? '' : ' AS ' . $adapter->quoteTableName($alias));
        } else {
            $tableName = $adapter->getRawTableName($this->tablePrefix, $this->from);
            return ' FROM ' . $adapter->quoteTableName($tableName) . (empty($this->alias) ? '' : ' AS ' . $adapter->quoteTableName($alias));
        }
    }

    protected function getLookupBuilder()
    {
        return $this->lookupBuilder;
    }

    /**
     * @return BaseAdapter
     */
    public function getAdapter()
    {
        return $this->adapter;
    }

    /**
     * Generate WHERE SQL
     * @return string
     * @throws Exception
     */
    protected function generateWhereSQL()
    {
        if (empty($this->where) && empty($this->exclude)) {
            return '';
        }

        $adapter = $this->getAdapter();
        $lookupBuilder = $this->getLookupBuilder();
        if (empty($this->where)) {
            $whereSql = '';
        } else {
            $where = $this->where;
            $where->setLookupBuilder($lookupBuilder);
            $where->setAdapter($adapter);
            $whereSql = $where->toSQL();
        }

        if (empty($this->exclude)) {
            $excludeSql = '';
        } else {
            $exclude = $this->exclude;
            $exclude->setLookupBuilder($lookupBuilder);
            $exclude->setAdapter($adapter);
            $excludeSql = $exclude->toSQL();
        }

        if (empty($whereSql) && empty($excludeSql)) {
            return '';
        } else {
            $sql = ' WHERE ';
            if (!empty($whereSql)) {
                $sql .= $whereSql;
            }

            if (!empty($excludeSql)) {
                $sql .= ' AND NOT (' . $excludeSql . ')';
            }
            return $sql;
        }
    }

    /**
     * @param $joinType string LEFT JOIN, RIGHT JOIN, etc...
     * @param $tableName string
     * @param array $on link columns
     * @param string $alias string
     * @return $this
     * @throws Exception
     */
    public function setJoin($joinType, $tableName, array $on, $alias = '')
    {
        if (empty($alias)) {
            $this->join[] = [$joinType, $tableName, $on, $alias];
        } else {
            if (array_key_exists($alias, $this->join)) {
                throw new Exception('Alias already defined in $join');
            }
            $this->join[$alias] = [$joinType, $tableName, $on, $alias];
        }
        return $this;
    }

    /**
     * @param $alias string table alias
     * @return $this
     */
    public function setAlias($alias)
    {
        $this->alias = $alias;
        return $this;
    }

    /**
     * @return string table alias
     */
    public function getAlias()
    {
        return $this->alias;
    }

    /**
     * @return string join sql
     * @throws Exception
     */
    protected function generateJoinSQL()
    {
        if (empty($this->join)) {
            return '';
        }

        $join = [];
        foreach ($this->join as $alias => $joinParams) {
            list($joinType, $tableName, $on, $alias) = $joinParams;

            $onSQL = [];
            $adapter = $this->getAdapter();
            foreach ($on as $leftColumn => $rightColumn) {
                $onSQL[] = $adapter->quoteColumn($leftColumn) . '=' . $adapter->quoteColumn($rightColumn);
            }

            if (strpos($tableName, 'SELECT') !== false) {
                $join[] = $joinType . ' (' . $adapter->quoteSql($this->tablePrefix, $tableName) . ')' . (empty($alias) ? '' : ' AS ' . $adapter->quoteColumn($alias)) . ' ON ' . implode(',', $onSQL);
            } else {
                $join[] = $joinType . ' ' . $adapter->quoteTableName($tableName) . (empty($alias) ? '' : ' AS ' . $adapter->quoteColumn($alias)) . ' ON ' . implode(',', $onSQL);
            }
        }

        return ' ' . implode(' ', $join);
    }

    /**
     * @param array $columns columns
     * @return $this
     */
    public function setGroup(array $columns)
    {
        $this->group = $columns;
        return $this;
    }

    /**
     * Generate GROUP SQL
     * @return string
     */
    protected function generateGroupSQL()
    {
        if (empty($this->group)) {
            return '';
        }

        $alias = $this->getAlias();
        $adapter = $this->getAdapter();
        $group = [];
        foreach ($this->group as $column) {
            $group[] = $adapter->quoteColumn(empty($alias) ? $column : $alias . '.' . $column);
        }

        return ' GROUP BY ' . implode(' ', $group);
    }

    /**
     * @param array|string $columns columns
     * @return $this
     */
    public function setOrder($columns)
    {
        $this->order = (array)$columns;
        return $this;
    }

    /**
     * Generate ORDER SQL
     * @return string
     */
    protected function generateOrderSQL()
    {
        if (empty($this->order)) {
            return '';
        }

        $order = [];
        $adapter = $this->getAdapter();
        $alias = $this->getAlias();
        foreach ($this->order as $column) {
            if (strpos($column, '-', 0) === 0) {
                $column = substr($column, 0, 1);
                $direction = 'DESC';
            } else {
                $direction = 'ASC';
            }

            $order[] = $adapter->quoteColumn(empty($alias) ? $column : $alias . '.' . $column) . ' ' . $direction;
        }

        return ' ORDER BY ' . implode(' ', $order);
    }

    /**
     * Clear properties
     * @return $this
     */
    public function clear()
    {
        $this->where = [];
        $this->join = [];
        $this->insert = [];
        $this->update = [];
        $this->group = [];
        $this->order = [];
        $this->select = [];
        $this->raw = '';
        $this->from = '';
        return $this;
    }

    /**
     * @param array $values rows with columns [name => value...]
     * @return $this
     */
    public function setInsert($tableName, array $columns, array $rows)
    {
        $this->insert = [$tableName, $columns, $rows];
        return $this;
    }

    /**
     * Generate DELETE SQL
     * @return string
     */
    protected function generateDeleteSQL()
    {
        return 'DELETE';
    }

    /**
     * @param array $values columns [name => value...]
     * @return $this
     */
    public function setUpdate(array $values)
    {
        $this->update = $values;
        return $this;
    }

    /**
     * Generate UPDATE SQL
     * @return string
     */
    protected function generateUpdateSQL()
    {
        $updateSQL = [];
        $adapter = $this->getAdapter();
        foreach ($this->update as $column => $value) {
            $updateSQL[] = $adapter->quoteColumn($column) . '=' . $adapter->quoteValue($value);
        }

        $alias = $this->getAlias();
        $tableName = empty($alias) ? $this->from : $alias . '.' . $this->from;
        return 'UPDATE ' . $adapter->quoteTableName($tableName) . ' SET ' . implode(' ', $updateSQL);
    }

    public function setRaw($sql)
    {
        $this->setTypeRaw();
        $this->sql = $sql;
        return $this;
    }

    protected function generateRawSql()
    {
        return $this->getAdapter()->quoteSql($this->tablePrefix, $this->raw);
    }

    /**
     * @return string
     * @throws Exception
     */
    public function toSQL()
    {
        switch ($this->getType()) {
            case self::TYPE_RAW:
                return $this->generateRawSql();
            case self::TYPE_ALTER_COLUMN:
                return strtr('{sql}', [
                    '{sql}' => $this->generateAlterColumnSQL()
                ]);
            case self::TYPE_DROP_TABLE:
                return strtr('{sql}', [
                    '{sql}' => $this->generateDropTableSql()
                ]);
            case self::TYPE_CREATE_TABLE:
                return strtr('{sql}', [
                    '{sql}' => $this->generateCreateTableSql()
                ]);
            case self::TYPE_INSERT:
                return strtr('{sql}', [
                    '{sql}' => $this->generateInsertSQL(),
                ]);
            case self::TYPE_UPDATE:
                return strtr('{update}{where}{join}{order}{group}', [
                    '{update}' => $this->generateUpdateSQL(),
                    '{where}' => $this->generateWhereSQL(),
                    '{group}' => $this->generateGroupSQL(),
                    '{order}' => $this->generateOrderSQL(),
                    '{join}' => $this->generateJoinSQL()
                ]);
            case self::TYPE_DELETE:
                return strtr('{delete}{from}{where}{join}{order}{group}', [
                    '{delete}' => $this->generateDeleteSQL(),
                    '{from}' => $this->generateFromSQL(),
                    '{where}' => $this->generateWhereSQL(),
                    '{group}' => $this->generateGroupSQL(),
                    '{order}' => $this->generateOrderSQL(),
                    '{join}' => $this->generateJoinSQL()
                ]);
            case self::TYPE_SELECT:
            default:
                return strtr('{select}{from}{where}{join}{group}{order}{limit_offset}', [
                    '{select}' => $this->generateSelectSQL(),
                    '{from}' => $this->generateFromSQL(),
                    '{where}' => $this->generateWhereSQL(),
                    '{group}' => $this->generateGroupSQL(),
                    '{order}' => $this->generateOrderSQL(),
                    '{join}' => $this->generateJoinSQL(),
                    '{limit_offset}' => $this->generateLimitOffsetSQL(),
                ]);
        }
    }

    /**
     * @return \Mindy\Query\Schema\Schema|\Mindy\Query\Mysql\Schema|\Mindy\Query\Sqlite\Schema|\Mindy\Query\Pgsql\Schema
     */
    public function getSchema()
    {
        return $this->schema;
    }

    public function setTypeDropTable()
    {
        $this->type = self::TYPE_DROP_TABLE;
        return $this;
    }

    public function dropTable($tableName)
    {
        $this->setTypeDropTable();
        $this->dropTable = $tableName;
        return $this;
    }

    protected function generateDropTableSql()
    {
        return "DROP TABLE " . $this->getAdapter()->quoteTableName($this->dropTable);
    }


    public function setTypeCreateTable()
    {
        $this->type = self::TYPE_CREATE_TABLE;
        return $this;
    }

    public function createTable($tableName, array $columns = [], $options = null)
    {
        $this->setTypeCreateTable();
        $this->createTable = [$tableName, $columns, $options];
        return $this;
    }

    /**
     * Builds a SQL statement for creating a new DB table.
     *
     * The columns in the new  table should be specified as name-definition pairs (e.g. 'name' => 'string'),
     * where name stands for a column name which will be properly quoted by the method, and definition
     * stands for the column type which can contain an abstract DB type.
     * The [[getColumnType()]] method will be invoked to convert any abstract type into a physical one.
     *
     * If a column is specified with definition only (e.g. 'PRIMARY KEY (name, type)'), it will be directly
     * inserted into the generated SQL.
     *
     * For example,
     *
     * ~~~
     * $sql = $queryBuilder->createTable('user', [
     *  'id' => 'pk',
     *  'name' => 'string',
     *  'age' => 'integer',
     * ]);
     * ~~~
     *
     * @param string $table the name of the table to be created. The name will be properly quoted by the method.
     * @param array $columns the columns (name => definition) in the new table.
     * @param string $options additional SQL fragment that will be appended to the generated SQL.
     * @return string the SQL statement for creating a new DB table.
     */
    protected function generateCreateTableSql()
    {
        $adapter = $this->getAdapter();
        list($tableName, $columns, $options) = $this->createTable;

        $cols = [];
        foreach ($columns as $name => $type) {
            if (is_string($name)) {
                $cols[] = "\t" . $adapter->quoteColumn($name) . ' ' . $this->getSchema()->getColumnType($type);
            } else {
                $cols[] = "\t" . $type;
            }
        }
        $sql = "CREATE TABLE " . $adapter->quoteTableName($tableName) . " (\n" . implode(",\n", $cols) . "\n)";
        return empty($options) ? $sql : $sql . ' ' . $options;
    }

    public function alterColumn($table, $column, $type)
    {
        $this->setTypeAlterColumn();
        $this->alterColumn = [$table, $column, $type];
        return $this;
    }

    public function setTypeAlterColumn()
    {
        $this->type = self::TYPE_ALTER_COLUMN;
        return $this;
    }

    protected function generateAlterColumnSQL()
    {
        list($table, $column, $type) = $this->alterColumn;
        return $this->getAdapter()->generateAlterColumnSQL($table, $column, $type, $this->getSchema()->getColumnType($type));
    }

    /**
     * @return string
     */
    protected function generateInsertSQL()
    {
        list($tableName, $columns, $rows) = $this->insert;
        return $this->getAdapter()->generateInsertSQL($tableName, $columns, $rows);
    }
}
