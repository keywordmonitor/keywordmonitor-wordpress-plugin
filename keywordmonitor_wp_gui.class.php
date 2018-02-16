<?php
/* security */
if (!class_exists('wp_keywordmonitor_de')) {
    die();
}

/**
 * wp_keywordmonitor_de_gui
 */
class wp_keywordmonitor_de_gui extends wp_keywordmonitor_de
{

    /**
     * save stuff / update options
     */
    public static function save_changes()
    {
        if (empty($_POST)) {
            wp_die(__('error'));
        }

        check_admin_referer(self::$short);

        //update data
        self::update_options(array(
            'dashboard_widget' => (int)(!empty($_POST['wp_keywordmonitor_de_dashboard_widget'])),
            'api_key' => (!empty($_POST['wp_keywordmonitor_de_api_key']) ? trim($_POST['wp_keywordmonitor_de_api_key']) : ''),
            'username' => (!empty($_POST['wp_keywordmonitor_de_username']) ? trim($_POST['wp_keywordmonitor_de_username']) : ''),
            'project_active_id' => (int)(!empty($_POST['wp_keywordmonitor_de_project_active_id']) ? $_POST['wp_keywordmonitor_de_project_active_id'] : 0),
            'project_active_rankings' => array(),
            'project_active_rankings_updated' => 0
        ));
        $username = self::get_option('username');
        $api_key = self::get_option('api_key');

        if (!empty($username) && !empty($api_key)) {
            self::update_options(array('projects_list' => self::fetch_projects($username, $api_key)));
        }

        //exit
        wp_safe_redirect(
            add_query_arg(array('updated' => 'true'), wp_get_referer())
        );
        die();
    }

    /**
     * load_page
     */
    function load_page()
    {
        if (!empty($_GET['keyword_id']) && !empty($_GET['show']) && $_GET['show'] == 'rankings') {
            self::show_rankings();
        } elseif (!empty($_GET['show']) && $_GET['show'] == 'options') {
            self::options_page();
        } else {
            self::options_page();
        }
    }

    /**
     * show_rankings
     */
    public function show_rankings()
    {

        $keyword_id = $_GET['keyword_id'];
        $options = self::get_options();
        $fetch_rankings = self::fetch_keyword_rankings($options['username'], $options['api_key'], $options['project_active_id'], $keyword_id);

        if (!empty($fetch_rankings['ranking']) && !empty($fetch_rankings['__attributes__']['keyword'])) {

            $rankings = $fetch_rankings['ranking'];
            $count_ranking_entries = 0;
            $rankings_chart_values = array();
            foreach ($rankings as $ranking) {
                $rankings_chart_values[] = "['" . $ranking['date'] . "', " . $ranking['ranking'] . "]";
                $count_ranking_entries++;
            }
            ?>
            <div class="wrap" id="wp_keywordmonitor_de_main">
                <h1>
                    KeywordMonitor - Ranking Entwicklung
                    <a href="https://app.keywordmonitor.net" class="add-new-h2">Zum KeywordMonitor Login</a>
                </h1>

                <h2 class="title">
                    <?php echo $fetch_rankings['__attributes__']['keyword']; ?>
                    <em>(<?php echo $fetch_rankings['__attributes__']['keyword_group']; ?>)</em>
                </h2>

                <script type="text/javascript" src="https://www.google.com/jsapi"></script>
                <script type="text/javascript">
                    google.load("visualization", "1", {packages: ["corechart"]});
                    google.setOnLoadCallback(drawChart);

                    function drawChart() {
                        var data = new google.visualization.DataTable();

                        data.addColumn('string', 'Year');
                        data.addColumn('number', 'Position');

                        data.addRows([
                            <?php echo implode(",", $rankings_chart_values); ?>
                        ]);
                        var options = {
                            height: 300,
                            vAxis: {direction: '-1'}
                        };

                        var chart = new google.visualization.LineChart(document.getElementById('chart_div'));
                        chart.draw(data, options);
                    }
                </script>
                <div id="chart_div"
                     style="width:100%; height:300px; margin-bottom: 30px; padding: 20px; background: #fff;"></div>

                <script type='text/javascript'>
                    google.load('visualization', '1', {packages: ['table']});
                    google.setOnLoadCallback(drawTable);

                    function drawTable() {
                        var data = new google.visualization.DataTable();

                        data.addColumn('string', 'Datum');
                        data.addColumn('string', 'Url');
                        data.addColumn('string', 'Position');
                        data.addColumn('string', 'Veränderung');
                        data.addRows(<?php echo $count_ranking_entries; ?>);

                        <?php
                        $i = 0;
                        $rankings = array_reverse($rankings);
                        foreach ($rankings as $ranking) {
                            echo "data.setCell(" . $i . ", 0, '{$ranking['date']}');\n";
                            echo "data.setCell(" . $i . ", 1, '{$ranking['url']}');\n";
                            echo "data.setCell(" . $i . ", 2, '{$ranking['ranking']}');\n";
                            echo "data.setCell(" . $i . ", 3, '{$ranking['ranking_change']}');\n";

                            $i++;
                        }
                        ?>

                        var table = new google.visualization.Table(document.getElementById('table_div'));
                        table.draw(data, {
                            showRowNumber: false,
                            event: 'event',
                            page: 'enable',
                            pageSize: '25',
                            sortAscending: 'True',
                            pagingSymbols: {prev: 'Zurück', next: 'Weiter'}
                        });
                    }
                </script>
                <div id="table_div" style="width:100%;"></div>
            </div>
            <?php
        } else {
            ?>
            <div class="wrap" id="wp_keywordmonitor_de_main">
                <h1>
                    KeywordMonitor - Ranking Entwicklung
                    <a href="https://app.keywordmonitor.net" class="add-new-h2">Zum KeywordMonitor Login</a>
                </h1>
                <p>Bei der API Abfrage ist ein Fehler aufgetreten!</p>
            </div>
            <?php
        }
    }

    /**
     * gui-output
     */
    public function options_page()
    {

        echo '
	    <div class="wrap" id="wp_keywordmonitor_de_main">
            <h1>KeywordMonitor - Einstellungen <a href="https://app.keywordmonitor.net" class="add-new-h2">Zum KeywordMonitor Login</a></h1>
            <form action="' . admin_url('admin-post.php') . '" method="post">
        ';

        $options = self::get_options();
        wp_nonce_field(self::$short);

        echo '
		<p>
		    Bitte trage in den folgenden Feldern Deinen KeywordMonitor Usernamen und Deinen persönlichen  API Key ein.<br/>
		    Den API Key findest du, in dem du im <a href="https://app.keywordmonitor.net/" target="_blank">KeywordMonitor</a> 
		    links im Menü auf den Punkt "Account Details" klickst.
		</p>
		
		<h2 class="title">Einstellungen</h2>
		
		<table class="form-table">
            <tbody>
            
                <tr>
                    <th scope="row"><label for="wp_keywordmonitor_de_username">KeywordMonitor Username</label></th>
                    <td><input type="text" name="wp_keywordmonitor_de_username" id="wp_keywordmonitor_de_username" value="' . esc_attr($options['username']) . '"  class="regular-text" /></td>
                </tr>
            
                <tr>
                    <th scope="row"><label for="wp_keywordmonitor_de_api_key">API-Key</label></th>
                    <td><input type="text" name="wp_keywordmonitor_de_api_key" id="wp_keywordmonitor_de_api_key" value="' . esc_attr($options['api_key']) . '"  class="regular-text" /></td>
                </tr>
	    ';

        if (!empty($options['username']) && !empty($options['api_key'])) {

            $projects = self::get_option('projects_list');

            if (!empty($projects)) {

                $projects_array = array();
                foreach ($projects as $project) {
                    $projects_array[$project['project_group_name']][$project['project_id']] = $project['name'];
                }
                unset($projects);
                unset($project);

                echo '
                <tr>
                    <th scope="row">Projektauswahl</th>
                    <td>
                    <p>
                        Wähle hier das gewünschte Projekt aus. <em>Sofern Du für diese WordPress Installation noch kein 
                        Projekt angelegt hast hole dies bitte vorher nach.</em>
                    </p>';

                echo '<select name="wp_keywordmonitor_de_project_active_id" id="wp_keywordmonitor_de_project_active_id">';
                foreach ($projects_array as $project_group => $projects) {
                    echo '<optgroup label="' . $project_group . '">';
                    foreach ($projects as $project_id => $project_name) {

                        echo '<option value="' . (int)$project_id . '"' . ((int)$project_id === $options['project_active_id'] ? ' selected="selected"' : '') . '>';
                        #echo esc_html($v['hostname']);
                        #echo ' ('; echo esc_html($v['name']); echo ')';
                        echo $project_name;
                        echo '</option>';
                    }

                    echo '</optgroup>';
                }
                echo '</select>';

                echo '
                    </td>
                </tr>
                ';
            }
        }

        echo '
                </tbody>
            </table>

            <input type="hidden" name="action" value="wp_keywordmonitor_de_save_changes" />
            <p class="submit">
                <input type="submit" name="submit" id="submit" class="button button-primary" value="Änderungen übernehmen">
            </p>
            </form>
        </div>
        ';
    }
}
