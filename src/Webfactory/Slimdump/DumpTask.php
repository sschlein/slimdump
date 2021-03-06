<?php

namespace Webfactory\Slimdump;

use Doctrine\DBAL\DBALException;
use Symfony\Component\Console\Output\OutputInterface;
use Webfactory\Slimdump\Config\Config;
use Webfactory\Slimdump\Config\ConfigBuilder;
use Webfactory\Slimdump\Database\Dumper;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Driver\PDOMySql\Driver as PDOMySqlDriver;

final class DumpTask
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var Connection
     */
    private $db;

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var bool
     */
    private $noProgress;

    /**
     * @param string          $dsn
     * @param string[]        $configFiles
     * @param bool            $noProgress
     * @param OutputInterface $output
     *
     * @throws DBALException
     */
    public function __construct($dsn, array $configFiles, bool $noProgress, OutputInterface $output)
    {
        $mysqliIndependentDsn = preg_replace('_^mysqli:_', 'mysql:', $dsn);

        $this->noProgress = $noProgress;
        $this->output = $output;
        $this->config = ConfigBuilder::createConfigurationFromConsecutiveFiles($configFiles);
        $this->db = DriverManager::getConnection(
            ['url' => $mysqliIndependentDsn, 'charset' => 'utf8', 'driverClass' => PDOMySqlDriver::class]
        );
    }

    public function dump()
    {
        $dumper = new Dumper($this->output);
        $dumper->exportAsUTF8();
        $dumper->disableForeignKeys();

        $db = $this->db;

        $platform = $db->getDatabasePlatform();

        $fetchTablesResult = $db->query($platform->getListTablesSQL());

        while ($tableName = $fetchTablesResult->fetchColumn(0)) {
            $tableConfig = $this->config->findTable($tableName);

            if (null === $tableConfig || !$tableConfig->isSchemaDumpRequired()) {
                continue;
            }

            $dumper->dumpSchema($tableName, $db, $tableConfig->keepAutoIncrement(), $this->noProgress);

            if ($tableConfig->isDataDumpRequired()) {
                $dumper->dumpData($tableName, $tableConfig, $db, $this->noProgress);
            }

            if ($tableConfig->isTriggerDumpRequired()) {
                $dumper->dumpTriggers($db, $tableName, $tableConfig->getDumpTriggersLevel());
            }
        }

        $fetchViewsResult = $db->query($platform->getListViewsSQL($db->getDatabase()));

        while ($viewName = $fetchViewsResult->fetchColumn(2)) {
            $tableConfig = $this->config->findTable($viewName);

            if (null === $tableConfig || !$tableConfig->isSchemaDumpRequired()) {
                continue;
            }

            $dumper->dumpView($db, $viewName, $tableConfig->getViewDefinerLevel());
        }

        $dumper->enableForeignKeys();
    }
}
