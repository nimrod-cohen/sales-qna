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

use SalesQnA\Classes\SalesQnADB;

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}

final class SalesQnA {
  private static $instance = null;
  public const PLUGIN_SLUG = 'sales-qna';
  private const OPENAI_API_KEY = 'openai_api_key';
  private $db = null;

  public static function get_instance() {
    if (null === self::$instance) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  private function __construct() {
    $this->db = SalesQnADB::get_instance();

    register_activation_hook(__FILE__, [$this->db, 'install']);
    register_uninstall_hook(__FILE__, [SalesQnADB::class, 'uninstall']);

    add_action('plugins_loaded', [$this, 'maybe_upgrade_plugin']);

    add_action('admin_menu', [$this, 'register_admin_page']);
    add_action('rest_api_init', [$this, 'register_api_routes']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

    add_shortcode('sales_qna_search_page', [$this, 'render_search_page']);
//    add_action('admin_init', function () {
//      $updater = new \SalesQnA\GitHubPluginUpdater(__FILE__);
//    });

  }

  public function register_api_routes() {
    register_rest_route('sales-qna/v1', '/questions/delete/', [
      'methods' => 'POST',
      'callback' => [$this, 'delete_question'],
      'permission_callback' => '__return_true'
    ]);
    register_rest_route('sales-qna/v1', '/questions/save/', [
      'methods' => 'POST',
      'callback' => [$this, 'save_question'],
      'permission_callback' => '__return_true'
    ]);

    register_rest_route('sales-qna/v1', '/tags/save/', [
      'methods' => 'POST',
      'callback' => [$this, 'save_tags'],
      'permission_callback' => '__return_true'
    ]);

    register_rest_route('sales-qna/v1', '/intents/get/', [
      'methods' => 'GET',
      'callback' => [$this, 'get_all_intents'],
      'permission_callback' => '__return_true'
    ]);

    register_rest_route('sales-qna/v1', '/intents/delete/', [
      'methods' => 'POST',
      'callback' => [$this, 'delete_intent'],
      'permission_callback' => '__return_true'
    ]);

    register_rest_route('sales-qna/v1', '/intents/save/', [
      'methods' => 'POST',
      'callback' => [$this, 'save_intent'],
      'permission_callback' => '__return_true'
    ]);


    register_rest_route('sales-qna/v1', '/answers/get', [
      'methods' => 'POST',
      'callback' => [$this, 'get_answers'],
      'permission_callback' => '__return_true'
    ]);
  }

  public function enqueue_admin_assets($hook) {
    if ($hook !== 'toplevel_page_sales-qna') {
      return;
    }
    self::enqueue_script('sales-qna-script', 'assets/sales-qna.js', ['wpjsutils']);
    self::enqueue_style('sales-qna-style', 'assets/sales-qna.css', []);
    self::enqueue_style('sales-qna-panel-style', 'assets/sales-qna-panel.css', []);
  }

  public function enqueue_assets($hook) {
    self::enqueue_script('sales-qna-search', 'assets/sales-qna-search.js', ['wpjsutils']);
    self::enqueue_style('sales-qna-search', 'assets/sales-qna-search.css', []);
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
    if ( ! defined( 'YOUR_PLUGIN_VERSION' ) ) {
      $plugin_data = get_file_data( __FILE__, [ 'Version' => 'Version' ] );
      define( 'YOUR_PLUGIN_VERSION', $plugin_data['Version'] );
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

  public function get_all_intents() {
    $intends = $this->db->get_all_intents();
    return rest_ensure_response($intends);
  }

  public function save_intent( $request ) {
    $input  = $request->get_json_params();
    $name   = stripslashes( sanitize_text_field( $input['name'] ?? '' ) );
    $answer = stripslashes( sanitize_text_field( $input['answer'] ?? '' ) );
    $id     = $input['id'] ?? false;

    if ( $id ) {
      $this->db->update_intent( $id, $name, $answer );
    } else {
      $this->db->add_intent( $name );
    }

    return rest_ensure_response( [ 'status' => 'success' ] );
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

    $id = $this->db->add_question($question,  $intentId);
    return rest_ensure_response(['status' => 'success', 'id' => $id]);
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

    $success = $this->db->save_tags($id, $tags);

    if (!$success) {
      return new WP_Error(
        'db_save_error',
        'Failed to save tags to the database',
        ['status' => 500]
      );
    }

    return new WP_REST_Response(['status' => 'success'], 200);
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

  public function get_answers($request) {
    $input = $request->get_json_params();
    $search_term = sanitize_text_field($input['search'] ?? '');

    $response = $this->db->get_answers($search_term);
    return rest_ensure_response($response);
  }

  public function render_search_page() {
    ob_start();
    include plugin_dir_path( __FILE__ ) . 'public/search.php';

    return ob_get_clean();
  }
}

require_once __DIR__ . '/vendor/autoload.php';

// Include the main plugin classes
$directory = plugin_dir_path(__FILE__) . '/interfaces';
$files = glob($directory . '/*.php');
foreach ($files as $file) {
  require_once $file;
}

// Include the main plugin classes
$directory = plugin_dir_path(__FILE__) . '/providers';
$files = glob($directory . '/*.php');
foreach ($files as $file) {
  require_once $file;
}

// Include the main plugin classes
$directory = plugin_dir_path(__FILE__) . '/classes';
$files = glob($directory . '/*.php');
foreach ($files as $file) {
  require_once $file;
}

// Initialize the plugin
SalesQnA::get_instance();