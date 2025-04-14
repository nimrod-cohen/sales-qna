<?php
/**
 * Sales Q&A Knowledge Base
 *
 * @wordpress-plugin
 * Plugin Name:   Sales QnA Knowledge Base
 * Plugin URI:    https://github.com/nimrod-cohen/sales-qna
 * Description:   Manage a Hebrew Q&A knowledge base for your sales team.
 * Version:       1.0.1
 * Author:        nimrod-cohen
 * Author URI:    https://github.com/nimrod-cohen/sales-qna
 * License:       GPL-2.0+
 * License URI:   http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:   sales-qna
 */

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}

final class SalesQnA {
  private static $instance = null;
  private const PLUGIN_SLUG = 'sales-qna';
  private const TABLE_NAME = 'sales_qna';
  private const OPENAI_API_KEY = 'openai_api_key';

  public static function get_instance() {
    if (null === self::$instance) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  private function __construct() {
    register_activation_hook(__FILE__, [$this, 'install']);
    register_uninstall_hook(__FILE__, ['SalesQnA', 'uninstall']);

    add_action('plugins_loaded', [$this, 'maybe_upgrade_plugin']);

    add_action('admin_menu', [$this, 'register_admin_page']);
    add_action('rest_api_init', [$this, 'register_api_routes']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

    add_action('admin_init', function () {
      $updater = new \SalesQnA\GitHubPluginUpdater(__FILE__);
    });
  }

  public function register_api_routes() {
    register_rest_route('sales-qna/v1', '/get/', [
      'methods' => 'POST',
      'callback' => [$this, 'get_all_questions'],
      'permission_callback' => '__return_true'
    ]);
    register_rest_route('sales-qna/v1', '/delete/', [
      'methods' => 'POST',
      'callback' => [$this, 'delete_question'],
      'permission_callback' => '__return_true'
    ]);
    register_rest_route('sales-qna/v1', '/save/', [
      'methods' => 'POST',
      'callback' => [$this, 'save_question'],
      'permission_callback' => '__return_true'
    ]);
  }

  public function enqueue_admin_assets($hook) {
    if ($hook !== 'toplevel_page_sales-qna') {
      return;
    }
    self::enqueue_script('sales-qna-script', 'assets/sales-qna.js', ['wpjsutils']);
    self::enqueue_style('sales-qna-style', 'assets/sales-qna.css', []);
  }

  private static function enqueue_style($handle, $src, $deps = []) {
    $url = plugin_dir_url(__FILE__);
    $file = plugin_dir_path(__FILE__) . $src;

    wp_enqueue_style($handle, $url . $src, $deps, filemtime($file));
  }

  private static function enqueue_script($handle, $src, $deps = [], $in_footer = false) {
    $url = plugin_dir_url(__FILE__);
    $file = plugin_dir_path(__FILE__) . $src;

    wp_enqueue_script($handle, $url . $src, $deps, filemtime($file), $in_footer);
  }

  public static function get_option($key, $default = null) {
    $value = get_option(self::PLUGIN_SLUG . '_' . $key);
    return $value !== false ? $value : $default;
  }

  private static function update_option($key, $value, $autoload = null) {
    return update_option(self::PLUGIN_SLUG . '_' . $key, $value, $autoload);
  }

  public static function version() {
    if (!function_exists('get_plugin_data')) {
      require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $plugin_data = get_plugin_data(__FILE__);
    return $plugin_data['Version'];
  }

  public function maybe_upgrade_plugin() {
    $current_version = self::version();
    $stored_version = self::get_option('plugin_version');

    if ($stored_version !== $current_version) {
      $this->run_upgrades($stored_version);
      self::update_option('plugin_version', $current_version);
    }
  }

  public function install() {
    global $wpdb;
    $table_name = $wpdb->prefix . self::TABLE_NAME;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            question TEXT NOT NULL,
            answer TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    self::update_option('plugin_version', '1.0.0');
  }

  private function run_upgrades($old_version) {
    global $wpdb;
    $table_name = $wpdb->prefix . self::TABLE_NAME;

    if (version_compare($old_version, '1.0.1', '<')) {
      $wpdb->query("ALTER TABLE $table_name ADD COLUMN embedding LONGTEXT DEFAULT NULL");
    }
  }

  public static function uninstall() {
    global $wpdb;
    $table_name = $wpdb->prefix . self::TABLE_NAME;
    $wpdb->query("DROP TABLE IF EXISTS $table_name");

    delete_option(self::PLUGIN_SLUG . '_plugin_version');
  }

  public function register_admin_page() {
    add_menu_page(
      'Sales QnA',
      'Sales QnA',
      'manage_options',
      self::PLUGIN_SLUG,
      [$this, 'render_admin_page'],
      'dashicons-format-chat',
      20
    );
  }

  public function get_all_questions($request) {
    //get the search term from POST
    $input = $request->get_json_params();
    $search_term = sanitize_text_field($input['search'] ?? '');

    if (!empty($search_term)) {
      return $this->handle_question($request);
    }

    global $wpdb;
    $table_name = $wpdb->prefix . self::TABLE_NAME;
    $rows = $wpdb->get_results("SELECT id, question, answer FROM $table_name ORDER BY question ASC");
    return rest_ensure_response($rows);
  }

  public function save_question($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . self::TABLE_NAME;

    $input = $request->get_json_params();
    $question = stripslashes(sanitize_text_field($input['question'] ?? ''));
    $answer = stripslashes(sanitize_textarea_field($input['answer'] ?? ''));
    $id = intval($input['id'] ?? 0);
    $embedding = $this->get_openai_embedding($question);
    $data = ['question' => $question, 'answer' => $answer, 'embedding' => json_encode($embedding)];

    if ($id > 0) {
      $wpdb->update($table_name, $data, ['id' => $id]);
    } else {
      $wpdb->insert($table_name, $data);
    }

    return rest_ensure_response(['status' => 'success']);
  }

  public function delete_question($request) {
    global $wpdb;
    $table_name = $wpdb->prefix . self::TABLE_NAME;

    $input = $request->get_json_params();
    $id = intval($input['id'] ?? 0);

    if ($id > 0) {
      $wpdb->delete($table_name, ['id' => $id]);
      return rest_ensure_response(['status' => 'success']);
    }

    return new WP_Error('invalid_id', 'Invalid ID provided.', ['status' => 400]);
  }

  public function render_admin_page() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      if (isset($_POST[self::OPENAI_API_KEY])) {
        $api_key = sanitize_text_field($_POST[self::OPENAI_API_KEY]);
        self::update_option(self::OPENAI_API_KEY, $api_key);
      }

      if (isset($_POST['toggle_direction'])) {
        $dir = $_POST['text_direction'] === 'rtl' ? 'rtl' : 'ltr';
        self::update_option('text_direction', $dir);
      }
    }

    include plugin_dir_path(__FILE__) . 'admin/admin.php';
  }

  private function get_openai_embedding($text) {
    $apiKey = self::get_option(self::OPENAI_API_KEY);

    $response = wp_remote_post('https://api.openai.com/v1/embeddings', [
      'headers' => [
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer ' . $apiKey
      ],
      'body' => json_encode([
        'input' => $text,
        'model' => 'text-embedding-3-small'
      ]),
      'timeout' => 20
    ]);

    if (is_wp_error($response)) {
      return null;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['data'][0]['embedding'] ?? null;
  }

  public function handle_question($request) {
    global $wpdb;
    $table = $wpdb->prefix . self::TABLE_NAME;

    $input = $request->get_json_params();
    $user_question = sanitize_text_field($input['question']);
    $embedding = $this->get_openai_embedding($user_question);
    if (!$embedding) {
      return new WP_Error('embedding_failed', 'Failed to generate embedding.', ['status' => 500]);
    }

    $rows = $wpdb->get_results("SELECT id, question, answer, embedding FROM $table WHERE embedding IS NOT NULL");

    $scored = [];

    foreach ($rows as $row) {
      $stored = json_decode($row->embedding, true);
      if (!is_array($stored)) {
        continue;
      }

      $score = $this->cosine_similarity($embedding, $stored);
      $scored[] = ['id' => $row->id, 'question' => $row->question, 'answer' => $row->answer, 'score' => $score];
    }

    // Sort by descending score
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

    return array_slice($scored, 0, 3); // return top 3 matches
  }

  private function cosine_similarity(array $a, array $b): float {
    $dot = 0;
    $magA = 0;
    $magB = 0;

    for ($i = 0; $i < count($a); $i++) {
      $dot += $a[$i] * $b[$i];
      $magA += $a[$i] ** 2;
      $magB += $b[$i] ** 2;
    }

    return $magA && $magB ? $dot / (sqrt($magA) * sqrt($magB)) : 0.0;
  }
}

// Initialize the plugin
SalesQnA::get_instance();