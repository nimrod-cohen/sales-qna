<?php

namespace SalesQnA\Classes;

use providers\OpenAiProvider;
use SalesQnA;
use VectorTableProvider;

class SalesQnADB {
  private static $_instance = null;
  private $questions_table = null;
  private $intents_table = null;
  private $vector_table = null;

  private $embedding_provider;
  private $vector_provider;

  private function __construct() {
    global $wpdb;
    $this->questions_table = $wpdb->prefix . "sales_qna_questions";
    $this->intents_table = $wpdb->prefix . "sales_qna_intents";
    $this->vector_table = $wpdb->prefix . "sales_qna_vector";

    $this->embedding_provider = new OpenAiProvider();
    $this->vector_provider = new VectorTableProvider();
  }

  public static function get_instance() {
    if (is_null(self::$_instance)) {
      self::$_instance = new SalesQnADB();
    }

    return self::$_instance;
  }

  public function install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();

    // Create table with intent_id as primary key, answer as LONGTEXT, created_at timestamp
    $sql = " CREATE TABLE $this->intents_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            answer TEXT NOT NULL,
            slug TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )$charset_collate;

        CREATE TABLE $this->questions_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            question TEXT NOT NULL,
            tags TEXT NOT NULL,
            intent_id INT NOT NULL,
            vector_id INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (intent_id) REFERENCES {$this->intents_table}(id) ON DELETE CASCADE
        )$charset_collate;
    ";

    dbDelta($sql);

    $this->vector_provider->initialize();

    SalesQnA::update_option('plugin_version', '1.0.0');
  }

  public function run_upgrades($old_version) {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();

    // if (version_compare($old_version, '1.0.1', '<')) {
    //  db works here

    //   SalesQnA::update_option('plugin_version', '1.0.1');
    // }
  }

  public static function uninstall() {
    global $wpdb;
    $tables = [
      $wpdb->prefix . 'sales_qna_topics',
      $wpdb->prefix . 'sales_qna_questions'
    ];

    foreach ($tables as $table) {
      $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }

    delete_option('sales_qna_plugin_version');  // Use the actual option name
  }

  public function update_question(string $id, string $question): bool {
    global $wpdb;
    $wpdb->update($this->questions_table, [
      'content' => $question
    ], ['id' => $id]);

    return true;
  }

  public function add_question(string $question, string $intentId): int {
    global $wpdb;

    $embedding = $this->embedding_provider->get_embedding($question);

    if (!$embedding) {
      return false;
    }

    $vectorId = $this->insert_embedding($embedding);

    if (!$vectorId) {
      return false;
    }
    $inserted = $wpdb->insert($this->questions_table, [
      'question'   => $question,
      'vector_id' => $vectorId,
      'intent_id'   => $intentId
    ]);

    if (!$inserted) {
      return false;
    }

    return $wpdb->insert_id;
  }

  public function delete_question($id) {
    global $wpdb;
    $wpdb->delete($this->questions_table, ['id' => $id]);
    return true;
  }

  public function save_tags($id, $tags) {
    global $wpdb;

    $cleanTags = array_filter(array_map('trim', $tags), function ($tag) {
      return strlen($tag) > 0 && strlen($tag) <= 50;
    });

    // Convert to JSON string
    $tagsJson = json_encode($cleanTags);

    $result = $wpdb->update(
      $this->questions_table,
      ['tags' => $tagsJson], // Data to update
      ['id' => $id]);

    return $result;
  }

  public function get_all_questions(string $search_term = '') {
    global $wpdb;

    $base_query = "
        SELECT q.*, q.content as question, q.tags as tags, i.name AS intent_name 
        FROM {$this->questions_table} q
        LEFT JOIN {$this->intents_table} i ON q.intent_id = i.id
    ";

    $params = [];
    if (!empty($search_term)) {
      $base_query .= " WHERE q.content LIKE %s";
      $params[] = '%' . $wpdb->esc_like($search_term) . '%';
    }

    $query = !empty($params) ? $wpdb->prepare($base_query, ...$params) : $base_query;

    return $wpdb->get_results($query, ARRAY_A);
  }

  public function add_intent(string $name): bool {
    global $wpdb;

    $result = $wpdb->insert($this->intents_table, [
      'name' => $name,
    ]);

    return $result !== false;
  }

  public function update_intent(string $id, ?string $name = null, ?string $answer = null): bool {
    global $wpdb;

    $data = [];

    if (!empty($name)) {
      $data['name'] = sanitize_text_field($name);
    }

    if (!empty($answer)) {
      $data['answer'] = sanitize_textarea_field($answer);
    }

    // Don't proceed if no valid fields to update
    if (empty($data)) {
      return false;
    }

    // Update intent depending on data provided
    $result = $wpdb->update(
      $this->intents_table,
      $data,
      ['id' => $id],
      array_map(function($item) { return '%s'; }, $data),
      ['%s']
    );

    return $result !== false;
  }

  public function delete_intent($id) {
    global $wpdb;
    $wpdb->delete($this->intents_table, ['id' => $id]);
    return true;
  }

  public function get_all_intents() {
    global $wpdb;

    $results = $wpdb->get_results(
      "SELECT i.id, i.name, i.answer, q.question, q.id AS question_id, q.tags as question_tags
         FROM {$this->intents_table} i
         LEFT JOIN {$this->questions_table} q ON q.intent_id = i.id
         ORDER BY i.id",
      ARRAY_A
    );

    if (empty($results)) {
      return [];
    }

    $intents = [];
    foreach ($results as $row) {
      $intent_id = $row['id'];

      if (!isset($intents[$intent_id])) {
        $intents[$intent_id] = [
          'id' => $row['id'],
          'name' => $row['name'],
          'answer' => $row['answer'],
          'questions' => []
        ];
      }

      if (!empty($row['question'])) {
        $intents[$intent_id]['questions'][] = [
          'id' => $row['question_id'],
          'text' => $row['question'],
          'tags' => !empty($row['question_tags']) ? json_decode($row['question_tags'], true) : []
        ];
      }
    }

    return array_values($intents);
  }

  public function insert_embedding($content){
    return $this->vector_provider->insert($content);
  }

  private function generate_slug(string $text): string {
    $slug = strtolower($text);
    $slug = str_replace(' ', '_', $slug);
    $slug = preg_replace('/[^a-z0-9_]/', '', $slug);
    $slug = preg_replace('/_+/', '_', $slug);
    $slug = trim($slug, '_');
    return $slug;
  }

  public function get_results(string $content) {
    $embedding = $this->embedding_provider->get_embedding($content);
    $vector_result = $this->vector_provider->search($embedding);

    global $wpdb;

    if (empty($vector_result)) {
      return [];
    }

    // Prepare vector data with similarity scores
    $vector_data = array_map(function($item) {
      return [
        'vector_id' => $item['id'] ?? null,
        'similarity' => $item['similarity'] ?? 0,
        'original_data' => $item
      ];
    }, $vector_result);

    // Filter valid vector IDs
    $vector_data = array_filter($vector_data, function($item) {
      return !is_null($item['vector_id']);
    });

    if (empty($vector_data)) {
      return [];
    }

    $vector_ids = array_column($vector_data, 'vector_id');
    $placeholders = implode(',', array_fill(0, count($vector_ids), '%d'));

    // Get questions with their intent data in one query
    $questions_with_intents = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT 
                q.id, 
                q.vector_id, 
                q.content, 
                q.intent_id,
                i.name AS intent_name,
                i.answer AS intent_answer
             FROM {$this->questions_table} q
             LEFT JOIN {$this->intents_table} i ON q.intent_id = i.id
             WHERE q.vector_id IN ($placeholders)",
        $vector_ids
      ),
      ARRAY_A
    );

    // Create lookup by vector_id
    $question_lookup = array_column($questions_with_intents, null, 'vector_id');

    // Merge all data while preserving similarity scores
    $combined_results = [];
    foreach ($vector_data as $vector) {
      $vector_id = $vector['vector_id'];
      if (isset($question_lookup[$vector_id])) {
        $combined_results[] = array_merge(
          $vector['original_data'],
          $question_lookup[$vector_id],
          [
            'similarity' => $vector['similarity'],
            // Explicitly include intent data
            'intent' => [
              'id' => $question_lookup[$vector_id]['intent_id'],
              'name' => $question_lookup[$vector_id]['intent_name'],
              'answer' => $question_lookup[$vector_id]['intent_answer']
            ]
          ]
        );
      }
    }

    // Sort by similarity (highest first)
    usort($combined_results, function($a, $b) {
      return $b['similarity'] <=> $a['similarity'];
    });

    return $combined_results;
  }
}