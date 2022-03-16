<?php


namespace As247\WpEloquent\Database;

use DateTime;
use Exception;
use Generator;
use JsonSerializable;
use Serializable;
use wpdb;

class WpConnection extends MySqlConnection
{
    /**
     * @var wpdb
     */
    public wpdb $db;

    /**
     * The database connection configuration options.
     *
     * @var array
     */
    protected array $config = [];


    /**
     * Initializes the Database class
     *
     * @return bool|WpConnection
     */
    public static function instance() : bool|WpConnection|static
    {
        static $instance = false;
        global $wpdb;

        if( ! $instance ) {
            $dbName = defined( 'DB_NAME' )
                ? DB_NAME
                : $wpdb->dbname ?? '';

            $instance = new self( $wpdb, $dbName, '' );
        }

        return $instance;
    }


    /**
     * @param Wpdb   $wpdb
     * @param string $database
     * @param string $tablePrefix
     * @param array  $config
     */
    public function __construct( wpdb $wpdb, string $database = '', string $tablePrefix = '', array $config = [] )
    {
        $this->db = $wpdb;

        $pdo = new WpPdo( $this );

        parent::__construct( $pdo, $database, $tablePrefix, $config );
    }


    /**
     * Get wpdb object
     *
     * @return wpdb
     */
    public function getWpdb() : wpdb
    {
        return $this->db;
    }


    /**
     * Run a select statement against the database.
     *
     * @param string $query
     * @param array  $bindings
     * @param bool   $useReadPdo
     *
     * @return array
     * @throws QueryException
     *
     */
    public function select( string $query, array $bindings = [], $useReadPdo = true ) : array
    {
        return $this->run( $query, $bindings, function ( $query, $bindings ) use ( $useReadPdo ) {
            if( $this->pretending() ) {
                return [];
            }
            $query = $this->bindParams( $query, $bindings );
            $error = $this->db->suppress_errors();
            $result = $this->db->get_results( $query );
            $this->db->suppress_errors( $error );
            if( $this->db->last_error ) {
                throw new QueryException( $query, $bindings, new Exception( $this->db->last_error ) );
            }

            return $result;
        } );
    }


    /**
     * Run a select statement against the database and returns a generator.
     *
     * @param string $query
     * @param array  $bindings
     * @param bool   $useReadPdo
     *
     * @return Generator
     */
    public function cursor( string $query, array $bindings = [], $useReadPdo = true ) : Generator
    {
        $result = $this->select( $query, $bindings, $useReadPdo );
        foreach( $result as $row ) {
            yield $row;
        }
    }


    /**
     * Execute an SQL statement and return the boolean result.
     *
     * @param string $query
     * @param array  $bindings
     *
     * @return bool
     */
    public function statement( string $query, array $bindings = [] ) : bool
    {
        $new_query = $this->bindParams( $query, $bindings );

        return $this->unprepared( $new_query );
    }


    /**
     * Run an SQL statement and get the number of rows affected.
     *
     * @param string $query
     * @param array  $bindings
     *
     * @return int
     */
    public function affectingStatement( string $query, array $bindings = [] ) : int
    {
        $new_query = $this->bindParams( $query, $bindings );

        return $this->runRawQuery( $new_query );
    }


    /**
     * Run a raw, unprepared query against the PDO connection.
     *
     * @param string $query
     *
     * @return bool
     */
    public function unprepared( string $query ) : bool
    {
        return (bool) $this->runRawQuery( $query );
    }


    /**
     * Run raw sql query
     *
     * @param $query
     *
     * @return int
     */
    protected function runRawQuery( string $query ) : int
    {
        return $this->run( $query, [], function ( $query ) {
            if( $this->pretending() ) {
                return 1;
            }
            $error = $this->db->suppress_errors();
            $result = $this->db->query( $query );
            $this->db->suppress_errors( $error );
            if( $this->db->last_error ) {
                throw new QueryException( $query, [], new Exception( $this->db->last_error ) );
            }

            return (int) $result;
        } );
    }


    /**
     * A hacky way to emulate bind parameters into SQL query
     *
     * @param $query
     * @param $bindings
     *
     * @return string|array
     * @throws Exception
     */
    private function bindParams( $query, $bindings ) : string|array
    {
        $query = str_replace( '"', '`', $query );
        $bindings = $this->prepareBindings( $bindings );
        if( ! $bindings ) {
            return $query;
        }

        $bindings = array_map( static function ( $replace ) {
            if( is_string( $replace ) ) {
                $replace = "'" . esc_sql( $replace ) . "'";
            } elseif( $replace === null ) {
                $replace = "null";
            }

            return $replace;
        }, $bindings );
        $query = str_replace( [ '%', '?' ], [ '%%', '%s' ], $query );
        $query = vsprintf( $query, $bindings );

        return $query;
    }


    /**
     * Prepare the query bindings for execution.
     *
     * @param array $bindings
     *
     * @return array
     * @throws Exception
     */
    public function prepareBindings( array $bindings ) : array
    {
        $grammar = $this->getQueryGrammar();

        foreach( $bindings as $key => $value ) {
            // Micro-optimization: check for scalar values before instances
            if( is_bool( $value ) ) {
                $bindings[ $key ] = (int) $value;
            } elseif( is_scalar( $value ) || is_null( $value ) ) {//null is not scalar but expected to keep
                continue;
            } elseif( $value instanceof DateTime ) {
                // We need to transform all instances of the DateTime class into an actual
                // date string. Each query grammar maintains its own date string format
                // so we'll just ask the grammar for the format to get from the date.
                $bindings[ $key ] = $value->format( $grammar->getDateFormat() );
            } elseif( is_object( $value ) ) {
                if( $value instanceof Serializable ) {
                    $bindings[ $key ] = $value->serialize();
                } elseif( $value instanceof JsonSerializable ) {
                    $bindings[ $key ] = json_encode( $value->jsonSerialize() );
                } else {
                    $bindings[ $key ] = (string) $value;
                }
            } else {
                $bindings[ $key ] = (string) $value;
            }
        }

        return $bindings;
    }
}
