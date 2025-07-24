<?php

namespace classes;

use SalesQnA;

class SalesQnADB {
  private static $_instance = null;
  private $questions_table = null;
  private $intents_table = null;
  private $embedding_provider;
  private $vector_provider;

  private function __construct($embedding_provider, $vector_provider) {
    global $wpdb;
    $this->questions_table = $wpdb->prefix . "sales_qna_questions";
    $this->intents_table = $wpdb->prefix . "sales_qna_intents";

    $this->embedding_provider = $embedding_provider;
    $this->vector_provider = $vector_provider;
  }

  public static function get_instance($embedding_provider, $vector_provider) {
    if (self::$_instance === null) {
      self::$_instance = new self($embedding_provider, $vector_provider);
    }

    return self::$_instance;
  }

  public function install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $intents_table   = $this->intents_table;
    $questions_table = $this->questions_table;

    $sql_intents = "CREATE TABLE $intents_table (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        answer TEXT NOT NULL,
        tags TEXT NOT NULL,
        slug TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset_collate;";

    $sql_questions = "CREATE TABLE $questions_table (
        id INT AUTO_INCREMENT PRIMARY KEY,
        question TEXT NOT NULL,
        intent_id INT NOT NULL,
        vector_id INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset_collate;";

    dbDelta($sql_intents);
    dbDelta($sql_questions);

    $constraint_exists = $wpdb->get_var($wpdb->prepare("
        SELECT CONSTRAINT_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_NAME = %s
          AND COLUMN_NAME = 'intent_id'
          AND CONSTRAINT_NAME = 'fk_intent_id'
          AND CONSTRAINT_SCHEMA = DATABASE()
    ", $questions_table));

    if (!$constraint_exists) {
      $wpdb->query("
            ALTER TABLE $questions_table
            ADD CONSTRAINT fk_intent_id
            FOREIGN KEY (intent_id)
            REFERENCES $intents_table(id)
            ON DELETE CASCADE
        ");
    }


    $this->vector_provider->initialize();

    SalesQnA::update_option('plugin_version', '1.0.0');
  }

  public function run_upgrades($old_version) {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $questions_table = $this->questions_table;

    if (version_compare($old_version, '1.0.5', '<')) {
      $constraint = $wpdb->get_var($wpdb->prepare("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = %s
              AND COLUMN_NAME = 'intent_id'
              AND CONSTRAINT_NAME = 'fk_intent_id'
              AND CONSTRAINT_SCHEMA = DATABASE()
        ", $questions_table));

      if ($constraint) {
        $wpdb->query("ALTER TABLE {$questions_table} DROP FOREIGN KEY fk_intent_id");
      }

      SalesQnA::update_option('plugin_version', '1.0.5');
    }
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

  public function update_question(string $id, string $question) {
    global $wpdb;

    $embedding = $this->embedding_provider->get_embedding($question);

    if (!$embedding) {
      return 'embedding_failed';
    }

    $vectorId = $this->insert_embedding($embedding);

    if (!$vectorId) {
      return 'vector_insert_failed';
    }

    $data = ['question' => $question, 'vector_id' => $vectorId];

    $result = $wpdb->update(
      $this->questions_table,
      $data,
      ['id' => $id],
      ['%s','%d'],
      ['%d']
    );
    if ($result === false) {
      return 'question_update_failed';
    }

    if ($result === 0) {
      return 'question_not_updated';
    }

    return $result;
  }

  public function delete_question($id) {
    global $wpdb;

    $vector_id = $wpdb->get_var(
      $wpdb->prepare(
        "SELECT vector_id FROM {$this->questions_table} WHERE id = %d",
        $id
      )
    );

    $wpdb->delete($this->questions_table, ['id' => $id]);

    if ($vector_id) {
      $vector_table = $this->vector_provider->get_vector_table() . '_vectors';
      $wpdb->delete($vector_table, ['id' => $vector_id]);
    }

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

    $questions_table = $this->questions_table;
    $vector_table = $this->vector_provider->get_vector_table() . '_vectors';

    $vector_ids = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT vector_id FROM {$questions_table} WHERE intent_id = %d",
        $id
      )
    );

    $wpdb->delete($questions_table, ['intent_id' => $id]);

    if (!empty($vector_ids)) {
      $in_placeholders = implode(',', array_fill(0, count($vector_ids), '%d'));
      $wpdb->query(
        $wpdb->prepare(
          "DELETE FROM {$vector_table} WHERE id IN ($in_placeholders)",
          ...$vector_ids
        )
      );
    }

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
        }
      }
    }

    $results = array_values($grouped);
    usort($results, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

    return $results;
  }

  public function export() {
    global $wpdb;

    $intents = $wpdb->get_results("SELECT * FROM {$this->intents_table}", ARRAY_A);

    foreach ($intents as &$intent) {
      $intent_id = $intent['id'];

      $questions = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM {$this->questions_table} WHERE intent_id = %d", $intent_id),
        ARRAY_A
      );

      $vector_table = $this->vector_provider->get_vector_table() . '_vectors';

      foreach ($questions as &$question) {
        $vector_id = $question['vector_id'];

        $vectors = $wpdb->get_results(
          $wpdb->prepare("SELECT * FROM {$vector_table} WHERE id = %d", $vector_id),
          ARRAY_A
        );

        foreach ($vectors as &$vector) {
          if (isset($vector['binary_code'])) {
            $vector['binary_code'] = base64_encode($vector['binary_code']);
          }
        }

        $question['vectors'] = $vectors;
      }

      $intent['questions'] = $questions;
    }

    return $intents;
  }

  public function import($data)
  {
    global $wpdb;

    $intents_table = $this->intents_table;
    $questions_table = $this->questions_table;
    $vectors_table = $this->vector_provider->get_vector_table() . '_vectors';

    foreach ($data['intents'] as $intent) {
      // Remove old ID to let DB auto-increment
      unset($intent['id']);
      $questions = $intent['questions'] ?? [];
      unset($intent['questions']);

      $wpdb->insert($intents_table, $intent);
      $new_intent_id = $wpdb->insert_id;

      foreach ($questions as $question) {
        unset($question['id']);
        $vectors = $question['vectors'] ?? [];
        unset($question['vectors']);

        $question['intent_id'] = $new_intent_id;

        $vector_ids = [];
        foreach ($vectors as $vector) {
          unset($vector['id']);

          if (isset($vector['binary_code']) && base64_decode($vector['binary_code'], true) !== false) {
            $vector['binary_code'] = base64_decode($vector['binary_code']);
          }

          $wpdb->insert($vectors_table, $vector);
          $vector_ids[] = $wpdb->insert_id;
        }

        $question['vector_id'] = $vector_ids[0] ?? null;

        $wpdb->insert($questions_table, $question);
      }
    }
  }
}