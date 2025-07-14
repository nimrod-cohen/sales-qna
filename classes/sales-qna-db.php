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

    // Convert to JSON string
    $tagsJson = json_encode($tags);

    $result = $wpdb->update(
      $this->questions_table,
      ['tags' => $tagsJson], // Data to update
      ['id' => $id]);

    return $result;
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

  public function get_answers(string $question) {
    $embedding = $this->embedding_provider->get_embedding($question);
    $vector_result = $this->vector_provider->search($embedding);

    global $wpdb;

    if (empty($vector_result)) {
      return [];
    }

    // Prepare vector data with similarity scores
    $vector_data = array_map(function($item) {
      return [
        'vector_id' => $item['id'] ?? null,
        'similarity' => $item['similarity'] ?? 0
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
        q.id AS question_id,
        q.vector_id, 
        q.question, 
        q.intent_id,
        q.tags,
        i.name AS intent_name,
        i.answer AS intent_answer
     FROM {$this->questions_table} q
     LEFT JOIN {$this->intents_table} i ON q.intent_id = i.id
     WHERE q.vector_id IN ($placeholders)",
        $vector_ids
      ),
      ARRAY_A
    );

    // Lookup by vector_id for fast access
    $question_lookup = array_column($questions_with_intents, null, 'vector_id');

    $combined_results = [];

    foreach ($vector_data as $vector) {
      $vector_id = $vector['vector_id'];

      if (!isset($question_lookup[$vector_id])) {
        continue;
      }

      $question_data = $question_lookup[$vector_id];
      $intent_id = $question_data['intent_id'];

      // Check if this intent is already added
      $existing = array_filter($combined_results, fn($item) => $item['id'] === $intent_id);
      $existing_key = key($existing);

      $current_tags = !empty($question_data['tags']) ? json_decode($question_data['tags'], true) : [];

      $similar_question = [
        'question'   => $question_data['question'],
        'similarity' => $vector['similarity'],
        'tags'       => $current_tags,
      ];

      if ($existing) {
        // Add to existing similar_questions
        $combined_results[$existing_key]['similar_questions'][] = $similar_question;

        // Merge tags (ensure uniqueness)
        $combined_results[$existing_key]['tags'] = array_values(
          array_unique(array_merge($combined_results[$existing_key]['tags'], $current_tags))
        );
      } else {
        // First time seeing this intent
        $combined_results[] = [
          'id'                => $intent_id,
          'name'              => $question_data['intent_name'],
          'answer'            => $question_data['intent_answer'],
          'question'          => $question_data['question'],
          'similarity'        => $vector['similarity'],
          'tags'              => $current_tags,
          'similar_questions' => [],
        ];
      }
    }

    // Reindex and sort by similarity descending
    usort($combined_results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
    return $combined_results;
  }
}