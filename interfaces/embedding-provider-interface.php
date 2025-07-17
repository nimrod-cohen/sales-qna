<?php

namespace interfaces;

/**
 * Interface for embedding providers
 */
interface EmbeddingProviderInterface {
  /**
   * Get embedding for given content
   *
   * @param string $content
   * @return string|null JSON encoded vector or null on failure
   */
  public function get_embedding(string $content): ?string;
}