<?php

namespace interfaces;


/**
 * Interface for vector providers
 */
interface VectorProviderInterface {

  /**
   * Search the vector db for a similar vector string
   *
   * @param string $vector_string
   * @param int $limit
   *
   * @return array Array of similar items
   */
  public function search(string $vector_string, int $limit = 5): array;

  /**
   * Insert an embedded vector string into the db
   *
   * @param string $vector_string
   *
   * @return array The ID of a newly inserted item
   */
  public function insert(string $vector_string): int;
}