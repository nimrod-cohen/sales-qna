<?php

namespace providers;

use mysqli;
use Exception;
use interfaces\VectorProviderInterface;
use MHz\MysqlVector\VectorTable;

class VectorTableProvider implements VectorProviderInterface {
  private $table_name;
  private $dimension;
  private $engine;
  private $mysqli;
  private $vector;

  public function __construct( $table_name = null, $dimension = 384, $engine = 'InnoDB' ) {
    $this->table_name = $table_name ?: 'wp_vector_embeddings';
    $this->dimension  = $dimension;
    $this->engine     = $engine;

    // Initialize database connection
    $this->initialize_db_connection();
    $this->vector = new VectorTable( $this->mysqli, $this->table_name, $this->dimension, $this->engine );
  }

  private function initialize_db_connection() {
    $this->mysqli = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );

    if ( $this->mysqli->connect_error ) {
      throw new Exception( 'MySQLi connection failed: ' . $this->mysqli->connect_error );
    }
  }

  public function initialize() {
    global $wpdb;
    $this->vector->initialize();
    $vectors_table = $this->table_name . '_vectors';
    $sql           = "ALTER TABLE `$vectors_table` MODIFY binary_code VARBINARY(2048) NULL";
    $wpdb->query( $sql );
  }

  public function search( string $vector_string, int $limit = 5 ): array {
    return $this->vector->search( $this->format_array( $vector_string ), $limit );
  }

  public function insert( string $vector_string ): int {
    return $this->vector->upsert( $this->format_array( $vector_string ) );
  }

  private function format_array( string $vector_string ) {
    $vector_string = trim( $vector_string );

    return json_decode( $vector_string, true );
  }
}