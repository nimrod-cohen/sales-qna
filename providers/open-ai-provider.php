<?php

namespace providers;

use interfaces\EmbeddingProviderInterface;

class OpenAiProvider implements EmbeddingProviderInterface {

  private $api_key;
  private $model;
  private $timeout;

  public function __construct() {
    $this->api_key = \SalesQnA::get_option('openai_api_key');
    $this->model = 'text-embedding-3-small';
    $this->timeout = 15;
  }

  public function get_embedding(string $content): ?string {
    $response = $this->query_openai($content);

    if (is_wp_error($response)) {
      error_log('OpenAI request failed: ' . $response->get_error_message());
      return null;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if ($code !== 200 || empty($data['data'][0]['embedding'])) {
      error_log("OpenAI returned error: $body");
      return null;
    }

    return json_encode($data['data'][0]['embedding']);
  }

  private function query_openai(string $content) {
    return wp_remote_post('https://api.openai.com/v1/embeddings', [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->api_key,
        'Content-Type'  => 'application/json',
      ],
      'body'    => json_encode([
        'input' => $content,
        'model' => $this->model
      ]),
      'timeout' => $this->timeout,
    ]);
  }
}