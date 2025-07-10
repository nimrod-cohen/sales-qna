<?php

use interfaces\VectorProviderInterface;
use MHz\MysqlVector\VectorTable;

class VectorTableProvider implements VectorProviderInterface {
  private $table_name;
  private $dimension;
  private $engine;
  private $mysqli;

  public function __construct($table_name = 'wp_vector_embeddings', $dimension = 384, $engine = 'InnoDB') {
    $this->table_name = $table_name;
    $this->dimension = $dimension;
    $this->engine = $engine;

    // Initialize database connection
    $this->initialize_db_connection();
  }

  private function initialize_db_connection() {
    $this->mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

    if ($this->mysqli->connect_error) {
      throw new Exception('MySQLi connection failed: ' . $this->mysqli->connect_error);
    }
  }

  public function search(string $vector_string, int $limit = 5): array {
    $vector = new VectorTable($this->mysqli, $this->table_name, $this->dimension, $this->engine);
    return $vector->search($this->get_array($vector_string), $limit);
  }

  public function insert(string $vector_string): int {
    $vector = new VectorTable($this->mysqli, $this->table_name, $this->dimension, $this->engine);
    return $vector->upsert($this->get_array($vector_string));
  }

  private function get_array(string $vector_string) {
    $vector_string = trim($vector_string);
    return json_decode($vector_string, true);
  }
}