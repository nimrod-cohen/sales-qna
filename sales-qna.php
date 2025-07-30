<?php
/**
 * Sales Q&A Knowledge Base
 *
 * @wordpress-plugin
 * Plugin Name:   Sales Q&A Knowledge Base
 * Plugin URI:    https://github.com/nimrod-cohen/sales-qna
 * Description:   Manage a Hebrew Q&A knowledge base for your sales team.
 * Version:       1.8.1
 * Author:        nimrod-cohen
 * Author URI:    https://github.com/nimrod-cohen/sales-qna
 * License:       GPL-2.0+
 * License URI:   http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:   sales-qna
 */

use classes\GitHubPluginUpdater;
use classes\SalesQnADB;
use providers\OpenAiProvider;
use providers\VectorTableProvider;

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}

final class SalesQnA {
  private static $instance = null;
  public const PLUGIN_SLUG = 'sales-qna';
  private const OPENAI_API_KEY = 'openai_api_key';
  private const FONT_AWESOME_HANDLE = 'font-awesome';
  private const FONT_AWESOME_URL = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css';
  private $db = null;

  public static function get_instance() {
    if (null === self::$instance) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  private function __construct() {
    $this->db = SalesQnADB::get_instance(
      new OpenAiProvider(),
      new VectorTableProvider()
    );

    register_activation_hook(__FILE__, [$this->db, 'install']);
    register_uninstall_hook(__FILE__, [SalesQnADB::class, 'uninstall']);

    add_action('plugins_loaded', [$this, 'maybe_upgrade_plugin']);

    add_action('admin_menu', [$this, 'register_admin_page']);
    add_action('rest_api_init', [$this, 'register_api_routes']);

    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_shortcode_assets']);

    add_shortcode('sales_qna_search_page', [$this, 'render_search_page']);

    add_action('admin_init', function () {
      $updater = new GitHubPluginUpdater(__FILE__);
    });
  }

  public function register_api_routes() {
    $this->register_question_routes();
    $this->register_tag_routes();
    $this->register_intent_routes();
    $this->register_answer_routes();
    $this->register_settings_routes();
  }

  private function register_question_routes() {
    register_rest_route('sales-qna/v1', '/questions/delete/', [
      'methods' => 'POST',
      'callback' => [$this, 'delete_question'],
      'permission_callback' => function () {
        return current_user_can('manage_options');
      }
    ]);

    register_rest_route('sales-qna/v1', '/questions/save/', [
      'methods' => 'POST',
      'callback' => [$this, 'save_question'],
      'permission_callback' => function () {
        return current_user_can('manage_options');
      }
    ]);
  }

  private function register_tag_routes() {
    register_rest_route('sales-qna/v1', '/tags/save/', [
      'methods' => 'POST',
      'callback' => [$this, 'save_tags'],
      'permission_callback' => function () {
        return current_user_can('manage_options');
      }
    ]);
  }

  private function register_intent_routes() {
    register_rest_route('sales-qna/v1', '/intents/get/', [
      'methods' => 'POST',
      'callback' => [$this, 'get_all_intents'],
      'permission_callback' => '__return_true'
    ]);

    register_rest_route('sales-qna/v1', '/intents/delete/', [
      'methods' => 'POST',
      'callback' => [$this, 'delete_intent'],
      'permission_callback' => function () {
        return current_user_can('manage_options');
      }
    ]);

    register_rest_route('sales-qna/v1', '/intents/save/', [
      'methods' => 'POST',
      'callback' => [$this, 'save_intent'],
      'permission_callback' => function () {
        return current_user_can('manage_options');
      }
    ]);
  }

  private function register_answer_routes() {
    register_rest_route('sales-qna/v1', '/answers/get', [
      'methods' => 'POST',
      'callback' => [$this, 'get_answers'],
      'permission_callback' => '__return_true'
    ]);
  }

  public function register_settings_routes() {
    register_rest_route('sales-qna/v1', '/settings/save', [
      'methods' => 'POST',
      'callback' => [$this, 'save_settings'],
      'permission_callback' => function () {
        return current_user_can('manage_options');
      }
    ]);

    register_rest_route('sales-qna/v1', '/settings/export', [
      'methods' => 'GET',
      'callback' => [$this, 'sales_qna_export'],
      'permission_callback' => function () {
        return current_user_can('manage_options');
      }
    ]);

    register_rest_route('sales-qna/v1', '/settings/import', [
      'methods' => 'POST',
      'callback' => [$this, 'sales_qna_import'],
      'permission_callback' => function () {
        return current_user_can('manage_options');
      }
    ]);
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

  public static function update_option($key, $value, $autoload = null) {
    return update_option(self::PLUGIN_SLUG . '_' . $key, $value, $autoload);
  }

  public static function version() {
    if (!defined('YOUR_PLUGIN_VERSION')) {
      $plugin_data = get_file_data(__FILE__, ['Version' => 'Version']);
      define('YOUR_PLUGIN_VERSION', $plugin_data['Version']);
    }

    return YOUR_PLUGIN_VERSION;
  }

  public function maybe_upgrade_plugin() {
    $current_version = self::version();
    $stored_version = self::get_option('plugin_version');

    if ($stored_version !== $current_version) {
      $this->db->run_upgrades($stored_version);
      self::update_option('plugin_version', $current_version);
    }
  }

  public function register_admin_page() {
    add_menu_page(
      'Sales Q&A',
      'Sales Q&A',
      'manage_options',
      self::PLUGIN_SLUG,
      [$this, 'render_admin_page'],
      'dashicons-format-chat',
      20
    );
  }

  public function get_answers($request) {
    $input = $request->get_json_params();
    $search_term = sanitize_text_field($input['search'] ?? '');

    $response = $this->db->get_answers($search_term);
    return rest_ensure_response($response);
  }

  public function get_all_intents() {
    $intends = $this->db->get_all_intents();
    return rest_ensure_response($intends);
  }

  public function save_intent($request) {
    $input = $request->get_json_params();
    $name = stripslashes(sanitize_text_field($input['name'] ?? ''));
    $answer = stripslashes(sanitize_text_field($input['answer'] ?? ''));
    $id = $input['id'] ?? false;

    if ($id) {
      $this->db->update_intent($id, $name, $answer);
    } else {
      $this->db->add_intent($name);
    }

    return rest_ensure_response(['status' => 'success']);
  }

  public function delete_intent($request) {
    $input = $request->get_json_params();
    $id = $input['id'] ?? false;

    if (!$id) {
      return new WP_Error('invalid_id', 'Invalid ID provided.', ['status' => 400]);
    }

    $this->db->delete_intent($id);

    return rest_ensure_response(['status' => 'success']);
  }

  public function save_question($request) {
    $input = $request->get_json_params();
    $question = stripslashes(sanitize_text_field($input['question'] ?? ''));
    $intentId = !empty($input['intent_id']) ? $input['intent_id'] : false;
    $id = $input['id'] ?? false;

    if ($id) {
      $result = $this->db->update_question($id, $question);
    } else {
      $result = $this->db->add_question($question, $intentId);
    }

    if (is_string($result)) {
      switch ($result) {
      case 'embedding_failed':
        return new WP_Error('openai_embedding_error', 'Failed to generate embedding. Check your API key.', ['status' => 500]);
      case 'vector_insert_failed':
        return new WP_Error('vector_insert_error', 'Could not insert embedding vector into database.', ['status' => 500]);
      case 'db_insert_failed':
        return new WP_Error('db_insert_error', 'Failed to save question to database.', ['status' => 500]);
      case 'question_update_failed':
        return new WP_Error('question_update_error', 'Error updating question into database.', ['status' => 500]);
      case 'question_not_updated':
        return new WP_Error('db_insert_error', 'Question was not updated.', ['status' => 500]);
      default:
        return new WP_Error('unknown_error', 'An unknown error occurred.', ['status' => 500]);
      }
    }

    return rest_ensure_response(['status' => 'success', 'id' => $result]);
  }

  public function delete_question($request) {
    $input = $request->get_json_params();
    $id = $input['id'] ?? false;

    if (!$id) {
      return new WP_Error('invalid_id', 'Invalid ID provided.', ['status' => 400]);
    }

    $this->db->delete_question($id);

    return rest_ensure_response(['status' => 'success']);
  }

  public function save_tags($request) {
    $input = $request->get_json_params();
    $tags = $input['tags'] ?? [];
    $id = $input['id'] ?? false;

    $result = $this->db->save_tags($id, $tags);

    if ($result === false) {
      return new WP_REST_Response([
        'status' => 'error',
        'message' => 'Failed to save tags'
      ], 500);
    }

    return new WP_REST_Response(['status' => 'success'], 200);
  }

  public function save_settings($request) {
    $params = $request->get_json_params();

    if (!empty($params['apiKey'])) {
      self::update_option(SELF::OPENAI_API_KEY, sanitize_text_field($params['apiKey']));
    }

    if (!empty($params['direction']) && in_array($params['direction'], ['ltr', 'rtl'])) {
      self::update_option('text_direction', $params['direction']);
    }

    if (!empty($params['adminThreshold'])) {
      self::update_option('admin_threshold', sanitize_text_field($params['adminThreshold']));
    }

    return rest_ensure_response(['status' => 'success']);
  }

  public function render_admin_page() {
    include plugin_dir_path(__FILE__) . 'admin/admin.php';
  }

  public function render_search_page() {
    ob_start();
    include plugin_dir_path(__FILE__) . 'public/search.php';

    return ob_get_clean();
  }

  public function enqueue_admin_assets($hook) {
    if ($hook !== 'toplevel_page_sales-qna') {
      return;
    }
    self::enqueue_script('sales-qna-script', 'admin/sales-qna-admin-panel.js', ['wpjsutils']);
    self::enqueue_style('sales-qna-panel-style', 'admin/sales-qna-admin-panel.css', []);

    wp_localize_script('sales-qna-script', 'SalesQnASettings', [
      'apiKey' => SalesQnA::get_option('openai_api_key', ''),
      'direction' => SalesQnA::get_option('text_direction', 'ltr'),
      'adminThreshold' => SalesQnA::get_option('admin_threshold', '0.5'),
      'nonce' => wp_create_nonce('wp_rest')
    ]);

    wp_enqueue_style(self::FONT_AWESOME_HANDLE, self::FONT_AWESOME_URL);
  }

  public function enqueue_shortcode_assets() {
    if (is_singular() && has_shortcode(get_post()->post_content, 'sales_qna_search_page')) {
      self::enqueue_script('sales-qna-search', 'public/sales-qna-search.js', ['wpjsutils']);
      self::enqueue_style('sales-qna-search', 'public/sales-qna-search.css', []);

      wp_enqueue_style(self::FONT_AWESOME_HANDLE, self::FONT_AWESOME_URL);
    }
  }

  public function sales_qna_export() {
    $intents = $this->db->export();

    $jsonData = json_encode(['intents' => $intents], JSON_PRETTY_PRINT);

    $zipFile = tempnam(sys_get_temp_dir(), 'sales_qna_export_') . '.zip';
    $zip = new ZipArchive();

    if ($zip->open($zipFile, ZipArchive::CREATE) === true) {
      $zip->addFromString('export.json', $jsonData);
      $zip->close();

      header('Content-Type: application/zip');
      header('Content-Disposition: attachment; filename="sales_qna_export.zip"');
      header('Content-Length: ' . filesize($zipFile));

      readfile($zipFile);
      unlink($zipFile);
      exit;
    } else {
      return new WP_Error('zip_failed', 'Failed to create zip file', ['status' => 500]);
    }
  }

  public function sales_qna_import() {
    if (empty($_FILES['file'])) {
      return new WP_REST_Response(['error' => 'No file uploaded.'], 400);
    }

    $uploaded_file = $_FILES['file'];
    $tmp_path = $uploaded_file['tmp_name'];

    $zip = new ZipArchive();
    $extract_to = wp_upload_dir()['basedir'] . '/sales-qna-import-' . time();
    mkdir($extract_to);

    if ($zip->open($tmp_path) === TRUE) {
      $zip->extractTo($extract_to);
      $zip->close();
    } else {
      return new WP_REST_Response(['error' => 'Failed to extract zip file.'], 500);
    }

    $json_files = glob($extract_to . '/*.json');
    if (empty($json_files)) {
      return new WP_REST_Response(['error' => 'No JSON file found in archive.'], 400);
    }

    $json_file = $json_files[0];
    $json = file_get_contents($json_file);
    $data = json_decode($json, true);

    if (!$data || !isset($data['intents'])) {
      return new WP_REST_Response(['error' => 'Invalid JSON format.'], 400);
    }

    $this->db->import($data);

    // Cleanup
    array_map('unlink', glob("$extract_to/*.*"));
    rmdir($extract_to);

    return new WP_REST_Response(['success' => true, 'message' => 'Data imported.']);
  }
}

require_once __DIR__ . '/vendor/autoload.php';

function sales_qna_load_includes(array $subdirs) {
  $base = plugin_dir_path(__FILE__) . 'includes/';

  foreach ($subdirs as $subdir) {
    $directory = $base . $subdir;

    if (!is_dir($directory)) {
      continue;
    }

    foreach (glob("{$directory}/*.php") as $file) {
      require_once $file;
    }
  }
}

sales_qna_load_includes(['interfaces', 'providers', 'classes']);

// Initialize the plugin
SalesQnA::get_instance();