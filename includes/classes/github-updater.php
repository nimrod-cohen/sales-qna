<?php
/*
 * Plugin name: Misha Update Checker
 * Description: This simple plugin does nothing, only gets updates from a custom server
 * Version: 1.4
 * Author: Misha Rudrastyh, changes by Nimrod Cohen, Oleg Shumar, Milen Petrov
 * Author URI: https://rudrastyh.com
 * License: GPL
 *
 * Make sure to set Author to your github user handle and Version in the plugin header
 * use the .git/hooks/pre-commit and post-commit to automatically update the version number
 * in readme.md files if necessary (there's a pre-commit.sample file)
 * and create a tag with the version number
 */

/**/
namespace classes;

use function add_action;
use function add_filter;
use function dmin_url;
use function delete_site_transient;
use function delete_transient;
use function get_plugin_data;
use function get_transient;
use function is_wp_error;
use function plugin_basename;
use function set_transient;
use function unzip_file;
use function WP_Filesystem;
use function wp_redirect;
use function wp_remote_get;
use function wp_remote_retrieve_response_code;
use function wp_tempnam;
use function wp_update_plugins;
use function wp_upload_dir;
use const ABSPATH;

defined('ABSPATH') || exit;

class GitHubPluginUpdater {
  /**
   * This is the naming of the folder
   */
  private $plugin_slug;
  private $latest_release_cache_key;
  private $cache_allowed;
  private $latest_release = null;
  private $plugin_file = null;
  private $plugin_data = null;
  private $_masterTempFolderName = 'temp_folder';
  private $_masterTempFolderPath = null;
  private $_pluginTempFolderPath = null;

  private function get_plugin_data() {
    if ($this->plugin_data) {
      return;
    }
    $this->plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $this->plugin_file);

  }

  public function __construct($base_file) {
    $this->plugin_file = plugin_basename($base_file);
    $this->plugin_slug = explode('/', plugin_basename($base_file))[0];
    $this->latest_release_cache_key = $this->plugin_slug . '_release';
    $this->cache_allowed = true;
    $this->get_plugin_data();

    // Make sure we have master temp folder available
    $uploads_dir = wp_upload_dir();
    $this->_masterTempFolderPath = $uploads_dir['basedir'] . '/' . $this->_masterTempFolderName;
    if (!is_dir($this->_masterTempFolderPath)) {
      mkdir($this->_masterTempFolderPath, 0755, true);
    }

    // Make sure we have plugin temp folder available
    $this->_pluginTempFolderPath = $this->_masterTempFolderName . '/' . $this->plugin_slug . '_tmp';
    if (!is_dir($this->_pluginTempFolderPath)) {
      mkdir($this->_pluginTempFolderPath, 0755, true);
    }

    add_filter('plugins_api', [$this, 'get_plugin_info'], 20, 3);
    add_filter('site_transient_update_plugins', [$this, 'update']);
    add_action('upgrader_process_complete', [$this, 'finish_install'], 10, 2);
    add_action('upgrader_post_install', [$this, 'fix_folder'], 10, 3);

    add_action('admin_post_' . $this->plugin_slug . '_clear_cache', [$this, 'clear_latest_release_cache']);
    add_action('admin_notices', [$this, 'display_cache_cleared_message']);
    add_filter('plugin_action_links_' . $this->plugin_file, [$this, 'add_clear_cache_link']);
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
    // do nothing if you're not getting plugin information right now
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
      'download_link' => $this->latest_release['zipball_url']
    ];

    return (object) $plugin;
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

    // Make the API request to GitHub
    $response = wp_remote_get($github_api_url);
    if (is_wp_error($response)) {
      return false;
    }

    $tag_name = $this->latest_release["tag_name"] ?? '';
    $this->latest_release = json_decode(wp_remote_retrieve_body($response), true);
    $this->latest_release["version"] = preg_replace('/[^0-9.]/', '', $tag_name);

    if ($this->cache_allowed) {
      set_transient($this->latest_release_cache_key, $this->latest_release, 5 * MINUTE_IN_SECONDS);
    }

    return true;
  }

  public function update($transient) {

    if (empty($transient->checked)) {
      return $transient;
    }

    // GitHub API URL for the latest release
    if (!$this->get_latest_release() || !$this->latest_release) {
      return $transient;
    }

    if (
      version_compare($this->plugin_data["Version"], $this->latest_release["version"], '<')) {
      $res = new \stdClass();
      $res->slug = $this->plugin_slug;
      $res->plugin = $this->plugin_file; // misha-update-plugin/misha-update-plugin.php
      $res->new_version = $this->latest_release["version"];
      $res->tested = $this->plugin_data["TestedUpTo"] ?? null;

      $res->package = $this->process_zip_file($this->latest_release["zipball_url"]);

      $transient->response[$res->plugin] = $res;
    }

    return $transient;
  }

  public function finish_install($upgrader, $options) {
    if (
      'update' !== $options['action']
      || 'plugin' === $options['type']
    ) {
      return;
    }

    // just clean the cache when new plugin version is installed
    if ($this->cache_allowed) {
      delete_transient($this->latest_release_cache_key);
    }
  }

  public function fix_folder($response, $hook_extra, $result) {
    $tmp_name = $this->get_tmp_name();
    if (file_exists($tmp_name)) {
      unlink($tmp_name);
    }

    $this->delete_directory($this->_pluginTempFolderPath);
  }

  /**
   * Process and manipulate a zip file from a given URL.
   *
   * @param string $file_url The URL of the zip file to be processed.
   *
   * @return string The path to the newly created zip file after processing, or an error message.
   */
  public function process_zip_file($file_url) {
    require_once ABSPATH . 'wp-admin/includes/file.php';

    $response = wp_remote_get($file_url, array('timeout' => 300));
    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
      return 'Error downloading the zip file.';
    }

    $zip_content = $response['body'];
    $tmp_file = wp_tempnam($file_url);
    file_put_contents($tmp_file, $zip_content);

    WP_Filesystem();

    $unzipfile = unzip_file($tmp_file, $this->_pluginTempFolderPath);
    if (is_wp_error($unzipfile)) {
      unlink($tmp_file);
      return 'Error unzipping the file.';
    }

    unlink($tmp_file);
    $unzipped_dirs = glob($this->_pluginTempFolderPath . '/*', GLOB_ONLYDIR);
    if (empty($unzipped_dirs)) {
      return 'Error: No directory found after unzipping.';
    }

    $unzipped_dir = $unzipped_dirs[0];
    $new_name = $this->_pluginTempFolderPath . '/' . $this->plugin_slug;

    if (!$this->safe_rename($unzipped_dir, $new_name)) {
      return 'Error renaming the directory.';
    }

    $zip = new \ZipArchive();
    $new_zip_name = $this->get_tmp_name();

    if ($zip->open($new_zip_name, \ZipArchive::CREATE) === true) {
      $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($new_name, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
      foreach ($iterator as $filename => $fileobject) {
        $local_path = $this->plugin_slug . '/' . substr($filename, strlen($new_name) + 1);
        if (!$fileobject->isDir()) {
          $zip->addFile($filename, $local_path);
        }
      }
      $zip->close();
    } else {
      return 'Error creating the .zip file.';
    }

    return $new_zip_name;
  }

  private function safe_rename($source, $destination, $retries = 5) {
    // Make sure destination does not exist, so it will not throw errors
    $this->delete_directory($destination);

    clearstatcache();
    for ($i = 0; $i < $retries; $i++) {
      if (rename($source, $destination)) {
        return true;
      }
      clearstatcache();
    }
    return false;
  }

  /**
   * Retrieves a temporary file name for a specific directory.
   *
   * @return string The temporary file name including the directory path.
   */
  private function get_tmp_name() {
    $uploads_dir = wp_upload_dir();

    return $uploads_dir['basedir'] . '/' . $this->plugin_slug . ".zip";
  }

  /**
   * Clean-up. Deletes a directory and its contents recursively.
   *
   * @param string $dir The directory path to be deleted.
   *
   * @return bool True if the directory and its contents are successfully deleted, false otherwise.
   */
  private function delete_directory($dir) {
    if (!is_dir($dir)) {
      return false;
    }

    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
      $path = "$dir/$file";
      is_dir($path) ? $this->delete_directory($path) : unlink($path);
    }

    return rmdir($dir);
  }
}