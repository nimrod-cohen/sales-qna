<?php
/*
 * A plugin updater helper class for WordPress plugins hosted on GitHub.
 * Version: 1.6
 * Author - Misha Rudrastyh, changes by Nimrod Cohen, Oleg Shumar, Milen Petrov
 * Author URI - https://rudrastyh.com
 * License: GPL
 *
 * Make sure to set plugin's Author to your github user handle and Version in the plugin header
 * use the .git/hooks/pre-commit and post-commit to automatically update the version number
 * in readme.md files if necessary (there's a pre-commit.sample file)
 * and create a tag with the version number
 */

namespace classes;

defined('ABSPATH') || exit;

class GitHubPluginUpdater {
  private $plugin_slug;
  private $latest_release_cache_key;
  private $cache_allowed;
  private $latest_release = null;
  private $plugin_file = null;
  private $plugin_data = null;
  private static $http_filter_registered = false;

  private function get_plugin_data() {
    if ($this->plugin_data) {
      return;
    }
    $this->plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $this->plugin_file);
  }

  public function __construct($base_file) {
    $this->plugin_file = str_replace(WP_PLUGIN_DIR . '/', '', $base_file);
    $this->plugin_slug = explode('/', plugin_basename($base_file))[0];
    $this->latest_release_cache_key = $this->plugin_slug . '_release';
    $this->cache_allowed = true;
    $this->get_plugin_data();

    add_filter('plugins_api', [$this, 'get_plugin_info'], 20, 3);
    add_filter('site_transient_update_plugins', [$this, 'update']);
    add_action('upgrader_process_complete', [$this, 'finish_install'], 10, 2);
    add_filter('upgrader_source_selection', [$this, 'fix_source_dir'], 10, 4);

    add_action('admin_post_' . $this->plugin_slug . '_clear_cache', [$this, 'clear_latest_release_cache']);
    add_action('admin_notices', [$this, 'display_cache_cleared_message']);
    add_filter('plugin_action_links_' . $this->plugin_file, [$this, 'add_clear_cache_link']);

    if (!self::$http_filter_registered && self::get_token()) {
      add_filter('http_request_args', [__CLASS__, 'inject_github_auth'], 10, 2);
      self::$http_filter_registered = true;
    }
  }

  private static function get_token() {
    if (defined('GITHUB_UPDATER_TOKEN') && GITHUB_UPDATER_TOKEN) {
      return GITHUB_UPDATER_TOKEN;
    }
    return null;
  }

  public static function inject_github_auth($parsed_args, $url) {
    $host = parse_url($url, PHP_URL_HOST);
    if (!in_array($host, ['api.github.com', 'codeload.github.com', 'github.com'], true)) {
      return $parsed_args;
    }
    $token = self::get_token();
    if (!$token) {
      return $parsed_args;
    }
    if (!isset($parsed_args['headers'])) {
      $parsed_args['headers'] = [];
    }
    $parsed_args['headers']['Authorization'] = 'Bearer ' . $token;
    return $parsed_args;
  }

  public function add_clear_cache_link($links) {
    $url = admin_url('admin-post.php?action=' . $this->plugin_slug . '_clear_cache');
    $link = "<a href='$url'>Clear Cache</a>";
    return array_merge($links, [$link]);
  }

  public function clear_latest_release_cache() {
    if ($this->cache_allowed) {
      delete_transient($this->latest_release_cache_key);
    }
    delete_site_transient('update_plugins');
    wp_update_plugins();

    wp_redirect(add_query_arg('cache_cleared_' . $this->plugin_slug, 'true', wp_get_referer()));
    exit;
  }

  public function display_cache_cleared_message() {
    if (!isset($_GET['cache_cleared_' . $this->plugin_slug])) {
      return;
    }
    echo "<div class='notice notice-success is-dismissible'>
        <p>Cache cleared successfully</p>
      </div>
      ";
  }

  function get_plugin_info($res, $action, $args) {
    if ('plugin_information' !== $action || $this->plugin_slug !== $args->slug) {
      return $res;
    }
    if (!$this->get_latest_release() || !$this->latest_release) {
      return $res;
    }

    $plugin = [
      'name' => $this->plugin_data['Name'],
      'slug' => $this->plugin_slug,
      'requires' => $this->plugin_data['RequiresWP'],
      'tested' => $this->plugin_data['TestedUpTo'] ?? null,
      'version' => $this->latest_release['tag_name'],
      'author' => $this->plugin_data['AuthorName'],
      'author_profile' => $this->plugin_data['AuthorURI'],
      'last_updated' => $this->latest_release['published_at'],
      'homepage' => $this->plugin_data['PluginURI'],
      'short_description' => $this->plugin_data['Description'],
      'sections' => [
        'Description' => $this->plugin_data['Description'],
        'Updates' => $this->latest_release['body']
      ],
      'download_link' => $this->get_download_url($this->latest_release['tag_name'])
    ];

    return (object) $plugin;
  }

  private function get_download_url($tag_name) {
    return 'https://codeload.github.com/'
      . $this->plugin_data['AuthorName'] . '/'
      . $this->plugin_slug
      . '/zip/refs/tags/' . $tag_name;
  }

  private function get_latest_release() {
    if ($this->latest_release) {
      return true;
    }

    $transient = null;
    if ($this->cache_allowed) {
      $transient = get_transient($this->latest_release_cache_key);
    }

    if ($transient) {
      $this->latest_release = $transient;
      return true;
    }

    $github_api_url = 'https://api.github.com/repos/' . $this->plugin_data['AuthorName'] . '/' . $this->plugin_slug . '/releases/latest';

    $headers = [
      'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
    ];
    $token = self::get_token();
    if ($token) {
      $headers['Authorization'] = 'Bearer ' . $token;
    }
    $response = wp_remote_get($github_api_url, [
      'headers' => $headers,
    ]);
    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
      return false;
    }

    $this->latest_release = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($this->latest_release['tag_name'])) {
      return false;
    }
    $this->latest_release["version"] = preg_replace('/[^0-9.]/', '', $this->latest_release["tag_name"]);

    if ($this->cache_allowed) {
      set_transient($this->latest_release_cache_key, $this->latest_release, 5 * MINUTE_IN_SECONDS);
    }

    return true;
  }

  public function update($transient) {
    if (empty($transient->checked)) {
      return $transient;
    }

    if (!$this->get_latest_release() || !$this->latest_release) {
      return $transient;
    }

    if (version_compare($this->plugin_data["Version"], $this->latest_release["version"], '<')) {
      $res = new \stdClass();
      $res->slug = $this->plugin_slug;
      $res->plugin = $this->plugin_file;
      $res->new_version = $this->latest_release["version"];
      $res->tested = $this->plugin_data["TestedUpTo"] ?? null;
      $res->package = $this->get_download_url($this->latest_release['tag_name']);

      $transient->response[$res->plugin] = $res;
    }

    return $transient;
  }

  /**
   * After WordPress extracts the zip, rename the source directory to match
   * the plugin slug. GitHub zips extract to "{repo}-{tag}/" but WordPress
   * expects the folder to match the plugin directory name.
   */
  public function fix_source_dir($source, $remote_source, $upgrader, $hook_extra) {
    if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_file) {
      return $source;
    }

    $expected = trailingslashit($remote_source) . trailingslashit($this->plugin_slug);
    if ($source === $expected) {
      return $source;
    }

    global $wp_filesystem;
    if ($wp_filesystem->move($source, $expected)) {
      return $expected;
    }

    return new \WP_Error('rename_failed', 'Could not rename extracted folder to ' . $this->plugin_slug);
  }

  public function finish_install($upgrader, $options) {
    if (
      'update' !== $options['action']
      || 'plugin' !== $options['type']
    ) {
      return;
    }

    if ($this->cache_allowed) {
      delete_transient($this->latest_release_cache_key);
    }
  }
}
