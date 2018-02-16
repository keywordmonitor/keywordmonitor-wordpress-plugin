<?php
/*
  Plugin Name: KeywordMonitor WordPress Plugin
  Description: Ein Wordpress Plugin, welches sich über die KeywordMonitor API die aktuellen Rankings eines Projektes holt und im Dashboard ausgibt. Basiert auf dem Antispam Bee Quellcode von <a href="https://github.com/sergejmueller">Sergej Müller</a> und wurde erweitert von <a href="https://github.com/crilla">Christian Schmidt</a> und <a href="https://github.com/Abromeit">Daniel Abromeit</a>.
  Author: Christian Schmidt (KeywordMonitor)
  Author URI: https://www.keywordmonitor.de/
  Plugin URI: https://github.com/crilla/keywordmonitor-wordpress-plugin
  Version: 1.2.3
*/

/**
 * security
 */
if (!class_exists('WP')) {
    die();
}


/**
 * wp_keywordmonitor_de
 */
class wp_keywordmonitor_de
{
    public static $short;
    public static $default;
    private static $base;

    /**
     * plugin loader / constructor (kinda)
     */
    public static function init()
    {
        if ((defined('DOING_AJAX') && DOING_AJAX) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }

        // init internal vars //
        self::$base = plugin_basename(__FILE__);
        self::$short = 'wp_keywordmonitor_de';
        self::$default = array('options' => array(
            'dashboard_widget' => 1, #not in use right now
            'api_key' => '',
            'username' => '',
            'project_active_id' => 0,
            'project_active_rankings' => array(),
            'project_active_rankings_updated' => 0,
            'projects_list' => array()
        ));

        if (!is_admin()) {
            return;
        }

        // BEGIN register actions //
        add_action(
            'admin_menu', array(__CLASS__, 'add_sidebar_menu')
        );

        if (self::_current_page('dashboard')) {
            add_action(
                'wp_dashboard_setup', array(__CLASS__, 'dashboard_add_widget')
            );
        } elseif (self::_current_page('options')) {
            add_action(
                'admin_init', array(__CLASS__, 'init_plugin_sources')
            );
        } elseif (self::_current_page('admin-post')) {
            require_once(dirname(__FILE__) . '/keywordmonitor_wp_gui.class.php');

            add_action(
                'admin_post_wp_keywordmonitor_de_save_changes', array('wp_keywordmonitor_de_gui', 'save_changes')
            );
        }

        // additional credits and option links
        add_filter('plugin_row_meta', array('wp_keywordmonitor_de', 'register_additional_plugin_links'), 10, 2);

        // END register actions //
    }

    // INSTALL  ////////////////////////////////////////////////////////////////

    /**
     * on plugin activation
     */
    public static function activate()
    {
        add_option(self::$short, array(), '', 'no');
    }

    /**
     * on plugin deactivation
     */
    public static function deactivate()
    {
        //void
    }

    /**
     * on plugin del./remove
     */
    public static function uninstall()
    {
        global $wpdb;

        delete_option('wp_keywordmonitor_de');
        $wpdb->query("OPTIMIZE TABLE `" . $wpdb->options . "`");
    }

    // HELPER //////////////////////////////////////////////////////////////////

    /**
     * check if current admin-page is [page-XY]
     *
     * @param   string $page Kennzeichnung der Seite
     * @return  boolean         TRUE Bei Erfolg
     */
    private static function _current_page($page)
    {
        switch ($page) {
            case 'dashboard':
                return (empty($GLOBALS['pagenow']) or (!empty($GLOBALS['pagenow']) && $GLOBALS['pagenow'] == 'index.php'));

            case 'options':
                return (!empty($_REQUEST['page']) && $_REQUEST['page'] == self::$short);

            case 'plugins':
                return (!empty($GLOBALS['pagenow']) && $GLOBALS['pagenow'] == 'plugins.php');

            case 'admin-post':
                return (!empty($GLOBALS['pagenow']) && $GLOBALS['pagenow'] == 'admin-post.php');

            default:
                return false;
        }
    }

    // RESSOURCES  /////////////////////////////////////////////////////////////

    /**
     * register ressources (e.g. css + js)
     */
    public static function init_plugin_sources()
    {
        $plugin = get_plugin_data(__FILE__);
    }

    /**
     * init options-page
     */
    public static function add_sidebar_menu()
    {
        /* menu cfg */
        $page = add_options_page(
            'KeywordMonitor',
            '<img src="' . plugins_url('img/icon.png', __FILE__) . '" id="ab_icon" height="9" width="9" alt="KeywordMonitor" /> KeywordMonitor',
            'manage_options',
            self::$short, array('wp_keywordmonitor_de_gui',
                'load_page'
            )
        );

        /* load php */
        add_action(
            'load-' . $page, array(__CLASS__, 'init_options_page')
        );
    }

    /**
     * init js
     */
    public static function add_options_js()
    {
        wp_enqueue_script('wp_keywordmonitor_de_js');
    }

    /**
     * init css
     */
    public static function add_options_css()
    {
        wp_enqueue_style('wp_keywordmonitor_de_css');
    }

    /**
     * init gui
     */
    public static function init_options_page()
    {
        require_once(dirname(__FILE__) . '/keywordmonitor_wp_gui.class.php');
    }

    // DASHBOARD ///////////////////////////////////////////////////////////////

    /**
     * init dashboard widget
     */
    public static function dashboard_add_widget()
    {
        if (!current_user_can('level_2')) {
            return;
        }

        /* add widget */
        wp_add_dashboard_widget(
            'wp_keywordmonitor_de_widget',
            '<img src="' . plugins_url('img/logo.png', __FILE__) . '" id="ab_icon" height="20" width="183" 
                alt="KeywordMonitor" style="margin-top:5px;" />',
            array(__CLASS__, 'dashboard_show_rankings')
        );
    }

    /**
     * dashboard-rankings output
     */
    public static function dashboard_show_rankings()
    {
        $id = (int)self::get_option('project_active_id');
        $rankings = (array)self::get_option('project_active_rankings');
        $updated = (int)self::get_option('project_active_rankings_updated');

        if (
            empty($rankings) ||
            (isset($rankings[0]['date']) && $rankings[0]['date'] !== date('Y-m-d') && $updated < (time() - 3600)) //update max. once in 60min
        ) {
            $username = self::get_option('username');
            $api_key = self::get_option('api_key');
            $project_active_id = self::get_option('project_active_id');

            if (!empty($username) && !empty($api_key) && !empty($project_active_id)) {
                $fetch_rankings = self::fetch_keyword_rankings($username, $api_key, $project_active_id);
                if ($fetch_rankings) {
                    if (!empty($fetch_rankings['ranking']) && !empty($fetch_rankings['__attributes__']['project_id'])) {
                        self::update_options(array(
                            'project_active_rankings' => $fetch_rankings['ranking'],
                            'project_active_rankings_updated' => time()
                        ));
                        $rankings = $fetch_rankings['ranking'];
                        $updated = time();
                        echo '<div><em>Die Daten wurden soeben aktualisiert</em></div>';
                    } else {
                        echo '<div><em>Bei der API Abfrage ist ein Fehler aufgetreten!</em></div>';
                    }
                }
            }
        }

        if (empty($rankings)) {
            echo '<div>Zur Zeit sind keine Daten verfügbar</div>';
        } else {
            $tmp_ranking = array();
            $tmp_keyword = array();
            foreach ($rankings as $v) {
                $tmp_ranking[] = $v['ranking'];
                $tmp_keyword[] = $v['keyword'];
            }
            array_multisort($tmp_ranking, SORT_ASC, $tmp_keyword, SORT_ASC, $rankings);
            unset($tmp_ranking, $tmp_keyword);

            echo '<p style="text-align:right;">';
            echo '<a href="https://app.keywordmonitor.net/" target="_blank">Rankings auf KeywordMonitor ansehen &raquo;</a><br />';
            echo '</p>';

            echo '<table style="border:0; width:100%;" class="striped">';
            echo '<thead>';
            echo '<th style="text-align:left;">Keyword</th>';
            echo '<th style="text-align:left;">Ranking</th>';
            echo '<th style="text-align:left;">Veränderung</th>';
            echo '<th style="text-align:left;">URL</th>';
            echo '</thead>';
            echo '<tbody>';

            foreach ($rankings as $v) {

                echo '<tr>';
                echo '<td><a href="options-general.php?page=wp_keywordmonitor_de&show=rankings&keyword_id=' . esc_html($v['keyword_id']) . '">' . esc_html($v['keyword']) . '</a></td>';
                echo '<td>' . esc_html($v['ranking']) . '</td>';
                if ($v['ranking_change'] > 0) {
                    echo '<td style="color:#080">+' . esc_html($v['ranking_change']) . '</td>';
                } elseif ($v['ranking_change'] < 0) {
                    echo '<td style="color:#800">' . esc_html($v['ranking_change']) . '</td>';
                } else {
                    echo '<td style="color:#888">' . esc_html($v['ranking_change']) . '</td>';
                }
                echo '<td><a title="' . esc_attr($v['url']) . '" href="' . esc_attr($v['url']) . '" target="_blank" style="font-size:16px;">[&#8599;]</a></td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';

            $last_update = $updated;
            $next_update = $last_update + (60 * 60);
            echo '
                <em><small>
                Letzte Aktualisierung ' . date('d.m.Y H:i', $last_update) . ', 
                Nächste Aktualisierung möglich ' . date('d.m.Y H:i', $next_update) . '
                </small></em>
            ';

        }
    }

    // OPTIONS /////////////////////////////////////////////////////////////////

    /**
     * Rückgabe der Optionen
     * @return array $options  Array mit Optionen
     */
    public static function get_options()
    {
        if (!($options = wp_cache_get(self::$short))) {
            $options = wp_parse_args(
                get_option(self::$short), self::$default['options']
            );

            wp_cache_set(self::$short, $options);
        }

        return $options;
    }

    /**
     * Rückgabe eines Optionsfeldes
     * @param string $field Name des Feldes
     * @return mixed Wert des Feldes
     */
    public static function get_option($field)
    {
        $options = self::get_options();

        return @$options[$field];
    }

    /**
     * Aktualisiert ein Optionsfeld
     * @param string $field Name des Feldes
     * @param mixed $value Wert des Feldes
     */
    private static function _update_option($field, $value)
    {
        self::update_options(array($field => $value));
    }

    /**
     * Aktualisiert mehrere Optionsfelder
     * @param array $data Array mit Feldern
     */
    public static function update_options($data)
    {
        $options = array_merge((array)get_option(self::$short), $data);

        update_option(self::$short, $options);

        wp_cache_set(self::$short, $options);
    }

    /**
     * @param $username
     * @param $api_key
     * @param string $action
     * @return string
     */
    private static function _get_api_url($username, $api_key, $action = '')
    {
        return 'https://api.keywordmonitor.de/v2'
            . '?username=' . urlencode($username)
            . '&api_key=' . urlencode($api_key)
            . '&format=json'
            . ($action ? '&action=' . urlencode($action) : '');
    }

    /**
     * @param $url
     * @param $json_key
     * @return string
     */
    private static function _exec_api_call($url, $json_key)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            $c = curl_exec($ch);
            curl_close($ch);

        } elseif (ini_get('allow_url_fopen')) {
            $c = file_get_contents($url);
        } else {
            return '';
        }

        if ($c && ($c = json_decode($c, true))) {
            if (!empty($c[$json_key])) {
                return $c[$json_key];
            } else {
                return $c;
            }
        }
        return '';
    }

    /**
     * @param $username
     * @param $api_key
     * @return string
     */
    public static function fetch_projects($username, $api_key)
    {
        $url = self::_get_api_url($username, $api_key, 'list_projects');

        return self::_exec_api_call($url, 'project');
    }

    /**
     * @param $username
     * @param $api_key
     * @param $project_id
     * @return string
     */
    public static function fetch_keywords($username, $api_key, $project_id)
    {
        $url = self::_get_api_url($username, $api_key, 'project_keywords')
            . '&project_id=' . urlencode($project_id);

        return self::_exec_api_call($url, 'keyword');
    }

    /**
     * @param $username
     * @param $api_key
     * @param $project_id
     * @param null $keyword_id
     * @return string
     */
    public static function fetch_keyword_rankings($username, $api_key, $project_id, $keyword_id = null)
    {
        $ini_timezone_old = ini_set('date.timezone', 'Europe/Berlin');

        $url = self::_get_api_url($username, $api_key, 'project_keyword_rankings')
            . '&project_id=' . urlencode($project_id)
            . ($keyword_id ? '&keyword_id=' . urlencode($keyword_id) : '')
            . '&date=' . date('Y-m-d', (date('H') < 12 ? strtotime('yesterday') : time())); //wait for collected rankings
        //time() aids 1h DST timediff @00:00h-01:00h
        ini_set('date.timezone', $ini_timezone_old);

        //return self::_exec_api_call($url, 'rankings');
        return self::_exec_api_call($url);
    }

    /**
     * @param $links
     * @param $file
     * @return array
     */
    function register_additional_plugin_links($links, $file)
    {
        if ($file == self::$base) {
            $links[] = '<a href="options-general.php?page=' . self::$short . '">Einstellungen</a>';
            $links[] = '<a href="http://www.keywordmonitor.de">KeywordMonitor</a>';
        }
        return $links;
    }
}

/**
 * FINALLY REGISTER OUR HOOKS
 */

add_action(
    'plugins_loaded', array('wp_keywordmonitor_de', 'init')
);

register_activation_hook(
    __FILE__, array('wp_keywordmonitor_de', 'activate')
);

register_deactivation_hook(
    __FILE__, array('wp_keywordmonitor_de', 'deactivate')
);

register_uninstall_hook(
    __FILE__, array('wp_keywordmonitor_de', 'uninstall')
);
