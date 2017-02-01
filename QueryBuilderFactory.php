<?php

/*
 * (c) Studio107 <mail@studio107.ru> http://studio107.ru
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * Author: Maxim Falaleev <max@studio107.ru>
 */

namespace Mindy\QueryBuilder;

use Doctrine\DBAL\Connection;
use Mindy\QueryBuilder\Interfaces\ICallback;
use Mindy\QueryBuilder\Interfaces\ILookupBuilder;

class QueryBuilderFactory
{
    /**
     * @var BaseAdapter
     */
    protected $adapter;
    /**
     * @var ILookupBuilder
     */
    protected $lookupBuilder;
    /**
     * @var Connection
     */
    protected $connection;

    /**
     * QueryBuilder constructor.
     *
     * @param Connection     $connection
     * @param BaseAdapter    $adapter
     * @param ILookupBuilder $lookupBuilder
     *
     * @internal param ICallback $callback
     */
    public function __construct(Connection $connection, BaseAdapter $adapter, ILookupBuilder $lookupBuilder)
    {
        $this->connection = $connection;
        $this->adapter = $adapter;
        $this->lookupBuilder = $lookupBuilder;
    }

    public function getQueryBuilder()
    {
        return new QueryBuilder($this->connection, $this->adapter, $this->lookupBuilder);
    }
}
