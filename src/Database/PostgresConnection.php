<?php

namespace As247\WpEloquent\Database;

use As247\WpEloquent\Database\Query\Processors\Processor;
use As247\WpEloquent\Database\Schema\Builder;
use Doctrine\DBAL\Driver\PDOPgSql\Driver as DoctrineDriver;
use As247\WpEloquent\Database\Query\Grammars\PostgresGrammar as QueryGrammar;
use As247\WpEloquent\Database\Query\Processors\PostgresProcessor;
use As247\WpEloquent\Database\Schema\Grammars\PostgresGrammar as SchemaGrammar;
use As247\WpEloquent\Database\Schema\PostgresBuilder;
use As247\WpEloquent\Database\Schema\PostgresSchemaState;
use PDO;
use PDOStatement;

class PostgresConnection extends Connection
{
    /**
     * Bind values to their parameters in the given statement.
     *
     * @param PDOStatement $statement
     * @param array        $bindings
     *
     * @return void
     */
    public function bindValues( PDOStatement $statement, array $bindings )
    {
        foreach( $bindings as $key => $value ) {
            if( is_int( $value ) ) {
                $pdoParam = PDO::PARAM_INT;
            } elseif( is_resource( $value ) ) {
                $pdoParam = PDO::PARAM_LOB;
            } else {
                $pdoParam = PDO::PARAM_STR;
            }

            $statement->bindValue(
                is_string( $key ) ? $key : $key + 1,
                $value,
                $pdoParam
            );
        }
    }


    /**
     * Get the default query grammar instance.
     */
    protected function getDefaultQueryGrammar()
    {
        return $this->withTablePrefix( new QueryGrammar );
    }


    /**
     * Get a schema builder instance for the connection.
     *
     * @return Builder
     */
    public function getSchemaBuilder() : Builder
    {
        if( is_null( $this->schemaGrammar ) ) {
            $this->useDefaultSchemaGrammar();
        }

        return new PostgresBuilder( $this );
    }


    /**
     * Get the default schema grammar instance.
     */
    protected function getDefaultSchemaGrammar()
    {
        return $this->withTablePrefix( new SchemaGrammar );
    }


    /**
     * Get the schema state for the connection.
     *
     * @param \As247\WpEloquent\Filesystem\Filesystem|null $files
     * @param callable|null                                $processFactory
     *
     * @return PostgresSchemaState
     */
    public function getSchemaState( Filesystem $files = null, callable $processFactory = null ) : PostgresSchemaState
    {
        return new PostgresSchemaState( $this, $files, $processFactory );
    }


    /**
     * Get the default post processor instance.
     *
     * @return Processor
     */
    protected function getDefaultPostProcessor() : Processor
    {
        return new PostgresProcessor;
    }


    /**
     * Get the Doctrine DBAL driver.
     *
     * @return DoctrineDriver
     */
    protected function getDoctrineDriver() : DoctrineDriver
    {
        return new DoctrineDriver;
    }
}
