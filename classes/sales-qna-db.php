<?php

namespace SalesQnA\Classes;

use providers\OpenAiProvider;
use SalesQnA;
use VectorTableProvider;

class SalesQnADB {
  private static $_instance = null;
  private $questions_table = null;
  private $intents_table = null;
  private $embedding_provider;
  private $vector_provider;

  private function __construct() {
    global $wpdb;
    $this->questions_table = $wpdb->prefix . "sales_qna_questions";
    $this->intents_table = $wpdb->prefix . "sales_qna_intents";

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

    $sql = " CREATE TABLE $this->intents_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            answer TEXT NOT NULL,
            tags TEXT NOT NULL,
            slug TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )$charset_collate;

        CREATE TABLE $this->questions_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            question TEXT NOT NULL,
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
      $wpdb->prefix . 'sales_qna_questions',
      $wpdb->prefix . 'sales_qna_intents',
    ];

    foreach ($tables as $table) {
      $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }

    delete_option('sales_qna_plugin_version');  // Use the actual option name
  }

  public function add_question(string $question, string $intentId) {
    global $wpdb;

    $embedding = $this->embedding_provider->get_embedding($question);

    if (!$embedding) {
      return 'embedding_failed';
    }

    $vectorId = $this->insert_embedding($embedding);

    if (!$vectorId) {
      return 'vector_insert_failed';
    }

    $inserted = $wpdb->insert($this->questions_table, [
      'question'   => $question,
      'vector_id' => $vectorId,
      'intent_id'   => $intentId
    ]);

    if (!$inserted) {
      return 'db_insert_failed';
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

    $tagsJson = json_encode($tags);

    $result = $wpdb->update(
      $this->intents_table,
      ['tags' => $tagsJson],
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
      "SELECT i.id, i.name, i.answer, i.tags as intent_tags, q.question, q.id AS question_id
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
          'tags' => !empty($row['intent_tags']) ? json_decode($row['intent_tags'], true) : [],
          'questions' => []
        ];
      }

      if (!empty($row['question'])) {
        $intents[$intent_id]['questions'][] = [
          'id' => $row['question_id'],
          'text' => $row['question'],
        ];
      }
    }

    return array_values($intents);
  }

  public function get_answers(string $question) {
    $embedding = $this->embedding_provider->get_embedding($question);
    $vector_results = $this->vector_provider->search($embedding);

    if (empty($vector_results)) return [];

    $vector_data = array_filter(array_map(function($item) {
      return isset($item['id']) ? [
        'vector_id'  => $item['id'],
        'similarity' => $item['similarity'] ?? 0
      ] : null;
    }, $vector_results));

    if (empty($vector_data)) return [];

    $questions = $this->get_questions_by_vector_ids(array_column($vector_data, 'vector_id'));

    return $this->group_by_intent($vector_data, $questions);
  }

  private function insert_embedding($content){
    return $this->vector_provider->insert($content);
  }

  private function get_questions_by_vector_ids(array $vector_ids): array {
    global $wpdb;

    if (empty($vector_ids)) return [];

    $placeholders = implode(',', array_fill(0, count($vector_ids), '%d'));

    return $wpdb->get_results(
      $wpdb->prepare(
        "SELECT 
                q.vector_id,
                q.question,
                q.intent_id,
                i.name AS intent_name,
                i.answer AS intent_answer,
                i.tags
             FROM {$this->questions_table} q
             LEFT JOIN {$this->intents_table} i ON q.intent_id = i.id
             WHERE q.vector_id IN ($placeholders)",
        $vector_ids
      ),
      ARRAY_A
    );
  }

  private function group_by_intent(array $vector_data, array $questions): array {
    $grouped = [];

    foreach ($vector_data as $vector) {
      foreach ($questions as $q) {
        if ($q['vector_id'] != $vector['vector_id']) continue;

        $intent_id = $q['intent_id'];
        $tags = is_array(json_decode($q['tags'] ?? '[]', true))
          ? json_decode($q['tags'], true)
          : [];

        if (!isset($grouped[$intent_id])) {
          $grouped[$intent_id] = [
            'id'                => $intent_id,
            'name'              => $q['intent_name'],
            'answer'            => $q['intent_answer'],
            'question'          => $q['question'],
            'similarity'        => $vector['similarity'],
            'tags'              => $tags,
            'similar_questions' => [],
          ];
        } else {
          $grouped[$intent_id]['similar_questions'][] = [
            'question'   => $q['question'],
            'similarity' => $vector['similarity'],
            'tags'       => $tags,
          ];

          // Merge tags (unique)
          $grouped[$intent_id]['tags'] = array_values(array_unique(array_merge(
            $grouped[$intent_id]['tags'],
            $tags
          )));
        }
      }
    }

    $results = array_values($grouped);
    usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

    return $results;
  }
}