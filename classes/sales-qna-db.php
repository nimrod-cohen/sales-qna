<?php

final class SalesQnADB {
  private static $_instance = null;
  private $questions_table = null;

  private function __construct() {
    global $wpdb;
    $this->questions_table = $wpdb->prefix . "sales_qna";
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
    $sql = "CREATE TABLE {$this->questions_table} (
      intent_id VARCHAR(255) NOT NULL,
      answer LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY  (intent_id)
  ) $charset_collate;";

    dbDelta($sql);
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
      $wpdb->prefix . "sales_qna"
    ];

    foreach ($tables as $table) {
      $wpdb->query("DROP TABLE IF EXISTS $table");
    }

    delete_option(SalesQnA::PLUGIN_SLUG . '_plugin_version');
  }

  public function update_question(string $intentId, string $answer): bool {
    global $wpdb;
    $wpdb->update($this->questions_table, [
      'answer' => $answer
    ], ['intent_id' => $intentId]);

    return true;
  }

  public function add_question(string $intentId, string $answer): bool {
    global $wpdb;
    $wpdb->insert($this->questions_table, [
      'intent_id' => $intentId,
      'answer' => $answer
    ]);
    return true;
  }

  public function delete_question($intentId) {
    global $wpdb;
    $wpdb->delete($this->questions_table, ['intent_id' => $intentId]);
    return true;
  }

  public function get_all_questions() {
    global $wpdb;
    $rows = $wpdb->get_results("SELECT * FROM {$this->questions_table}", ARRAY_A);
    return array_column($rows, 'answer', 'intent_id');
  }
}