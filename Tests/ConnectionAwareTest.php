<?php

declare(strict_types=1);

/*
 * Studio 107 (c) 2018 Maxim Falaleev
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mindy\QueryBuilder\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Mindy\QueryBuilder\AdapterInterface;
use Mindy\QueryBuilder\QueryBuilder;
use Mindy\QueryBuilder\QueryBuilderFactory;
use PHPUnit\Framework\TestCase;

abstract class ConnectionAwareTest extends TestCase
{
    /**
     * @var string
     */
    protected $driver = 'sqlite';

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function setUp()
    {
        parent::setUp();

        $config = require __DIR__.'/config/'.(@getenv('TRAVIS') ? 'config_travis.php' : 'config.php');
        if (empty($this->driver)) {
            throw new \RuntimeException(sprintf(
                'Missing driver property in %s',
                get_class($this)
            ));
        }

        if (false === isset($config[$this->driver])) {
            $this->markTestSkipped('Missing config for '.$this->driver.' driver');
        }

        $driverConfig = [];
        if (extension_loaded('pdo_'.$this->driver)) {
            $driverConfig = $config[$this->driver];
        } else {
            $this->markTestSkipped('Missing pdo extension for '.$this->driver.' driver');
        }

        $fixtures = $driverConfig['fixture'];
        unset($driverConfig['fixture']);

        $this->connection = DriverManager::getConnection($driverConfig);

        $this->loadFixtures($this->connection, $fixtures);
    }

    protected function loadFixtures(Connection $connection, $fixtures)
    {
        $sql = file_get_contents($fixtures);
        if (empty($sql)) {
            return;
        }

        /* @var \PDOStatement $stmt */
        if ($connection instanceof \Doctrine\DBAL\Driver\PDOConnection) {
            // PDO Drivers
            try {
                $lines = 0;
                $stmt = $connection->prepare($sql);
                $stmt->execute();
                do {
                    // Required due to "MySQL has gone away!" issue
                    $stmt->fetch();
                    $stmt->closeCursor();
                    ++$lines;
                } while ($stmt->nextRowset());
            } catch (\PDOException $e) {
                throw new \RuntimeException($e->getMessage(), $e->getCode(), $e);
            }
        } else {
            // Non-PDO Drivers (ie. OCI8 driver)
            $stmt = $connection->prepare($sql);
            $rs = $stmt->execute();
            if (!$rs) {
                $error = $stmt->errorInfo();
                throw new \RuntimeException($error[2], $error[0]);
            }
            $stmt->closeCursor();
        }
    }

    /**
     * @throws \Exception
     *
     * @return AdapterInterface
     */
    protected function getAdapter(): AdapterInterface
    {
        return $this->getQueryBuilder()->getAdapter();
    }

    /**
     * @throws \Exception
     *
     * @return QueryBuilder
     */
    protected function getQueryBuilder()
    {
        return QueryBuilderFactory::getQueryBuilder($this->connection);
    }
}
