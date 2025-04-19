<?php
/**
 * Sales Q&A Knowledge Base
 *
 * @wordpress-plugin
 * Plugin Name:   Sales Q&A Knowledge Base
 * Plugin URI:    https://github.com/nimrod-cohen/sales-qna
 * Description:   Manage a Hebrew Q&A knowledge base for your sales team.
 * Version:       1.0.0
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
  public const PLUGIN_SLUG = 'sales-qna';
  private const OPENAI_API_KEY = 'openai_api_key';
  private $db = null;
  private $dfcx = null;

  public static function get_instance() {
    if (null === self::$instance) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  private function __construct() {
    register_activation_hook(__FILE__, ['SalesQnADB', 'install']);
    register_uninstall_hook(__FILE__, ['SalesQnADB', 'uninstall']);

    add_action('plugins_loaded', [$this, 'maybe_upgrade_plugin']);

    add_action('admin_menu', [$this, 'register_admin_page']);
    add_action('rest_api_init', [$this, 'register_api_routes']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

    add_action('admin_init', function () {
      $updater = new \SalesQnA\GitHubPluginUpdater(__FILE__);
    });

    $this->db = SalesQnADB::get_instance();
    $this->dfcx = DialogFlowCX::get_instance();
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

  public static function update_option($key, $value, $autoload = null) {
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

  public function get_all_questions($request) {
    //get the search term from POST
    $input = $request->get_json_params();
    $search_term = sanitize_text_field($input['search'] ?? '');

    if (!empty($search_term)) {
      $rows = $this->dfcx->find_intent($search_term);
    } else {
      $rows = $this->dfcx->get_intents($search_term);
    }

    //this returns an assoc atray of all intent_ids
    $answers = $this->db->get_all_questions();

    foreach ($rows as &$row) {
      $row['answer'] = $answers[$row['intent_id']] ?? '';
    }

    return rest_ensure_response($rows);
  }

  public function save_question($request) {
    $input = $request->get_json_params();
    $question = stripslashes(sanitize_text_field($input['question'] ?? ''));
    $answer = stripslashes(sanitize_textarea_field($input['answer'] ?? ''));
    $intentId = !empty($input['intent_id']) ? $input['intent_id'] : false;

    if (!$intentId) {
      $intentId = $this->dfcx->add_intent($question);
      if (!$intentId) {
        return new WP_Error('invalid_id', 'Invalid ID provided.', ['status' => 400]);
      }
      $this->db->add_question($intentId, $answer);
    } else {
      $this->db->update_question($intentId, $answer);
    }

    return rest_ensure_response(['status' => 'success']);
  }

  public function delete_question($request) {
    $input = $request->get_json_params();
    $intentId = $input['intent_id'] ?? false;

    if (!$intentId) {
      return new WP_Error('invalid_id', 'Invalid ID provided.', ['status' => 400]);
    }

    if ($this->dfcx->delete_intent($intentId)) {
      $this->db->delete_question($intentId);
    } else {
      return new WP_Error('delete_failed', 'Failed to delete question.', ['status' => 500]);
    }

    return rest_ensure_response(['status' => 'success']);
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
}

require_once __DIR__ . '/vendor/autoload.php';

// Include the main plugin classes
$directory = plugin_dir_path(__FILE__) . '/classes';
$files = glob($directory . '/*.php');
foreach ($files as $file) {
  require_once $file;
}

// Initialize the plugin
SalesQnA::get_instance();