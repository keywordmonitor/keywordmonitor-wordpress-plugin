<?php
/* security */
if (!class_exists('wp_keywordmonitor_de')) {
    die();
}

/**
 * wp_keywordmonitor_de_GUI
 */
class wp_keywordmonitor_de_GUI extends wp_keywordmonitor_de {

    /**
     * save stuff / update options
     */
    public static function save_changes() {
        if (empty($_POST)) {
            wp_die(__('error'));
        }

        check_admin_referer(self::$short);

//update data
        self::update_options(array(
            'dashboard_widget' => (int) (!empty($_POST['wp_keywordmonitor_de_dashboard_widget'])),
            'api_key' => (!empty($_POST['wp_keywordmonitor_de_api_key']) ? trim($_POST['wp_keywordmonitor_de_api_key']) : ''),
            'username' => (!empty($_POST['wp_keywordmonitor_de_username']) ? trim($_POST['wp_keywordmonitor_de_username']) : ''),
            'project_active_id' => (int) (!empty($_POST['wp_keywordmonitor_de_project_active_id']) ? $_POST['wp_keywordmonitor_de_project_active_id'] : 0 ),
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
    function load_page() {
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
    public function show_rankings() {

        $keyword_id = $_GET['keyword_id'];
        $options = self::get_options();
        $rankings = self::fetch_keyword_rankings($options['username'], $options['api_key'], $options['project_active_id'], $keyword_id);

        $count_ranking_entries = 0;
        $rankings_chart_values = array();
        foreach ($rankings as $ranking) {
            $rankings_chart_values[] = "['" . $ranking['date'] . "', " . $ranking['ranking'] . "]";
            $count_ranking_entries++;
        }
        ?>

        <div class="wrap" id="wp_keywordmonitor_de_main">


            <div id="icon-edit-pages" class="icon32"><br /></div>
            <h2>KeywordMonitor - Ranking Entwicklung <a href="https://app.keywordmonitor.de" class="add-new-h2">Zum KeywordMonitor Login</a></h2>

            <script type="text/javascript" src="https://www.google.com/jsapi"></script>
            <script type="text/javascript">
                google.load("visualization", "1", {packages:["corechart"]});
                google.setOnLoadCallback(drawChart);
                function drawChart() {
                    var data = new google.visualization.DataTable();

                    data.addColumn('string', 'Year');
                    data.addColumn('number', 'Position');

                    data.addRows([
        <? echo implode(",", $rankings_chart_values); ?>
                    ]);
                    var options = {
                        height: 300,
                        vAxis: {direction: '-1'}
                    };

                    var chart = new google.visualization.LineChart(document.getElementById('chart_div'));
                    chart.draw(data, options);
                }
            </script>
            <div id="chart_div" style="width:100%; height:300px;margin-bottom: 30px;"></div>

            <script type='text/javascript'>
                google.load('visualization', '1', {packages:['table']});
                google.setOnLoadCallback(drawTable);
                function drawTable() {
                    var data = new google.visualization.DataTable();

                    data.addColumn('string', 'Datum');
                    data.addColumn('string', 'Url');
                    data.addColumn('string', 'Position');
                    data.addColumn('string', 'Veränderung');

                    data.addRows(<? echo $count_ranking_entries; ?>);

        <?
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
                        showRowNumber: false, event: 'event', page : 'enable', pageSize :'25', sortAscending: 'True', pagingSymbols: {prev: 'Zurück', next: 'weiter'}
                    });

                }
            </script>

            <div id="table_div"></div>


        </div>


        <?
    }

    /**
     * gui-output
     */
    public function options_page() {
        ?>
        <div class="wrap" id="wp_keywordmonitor_de_main">


            <div id="icon-edit-pages" class="icon32"><br /></div>
            <h2>KeywordMonitor.de - Einstellungen <a href="https://app.keywordmonitor.de" class="add-new-h2">Zum KeywordMonitor Login</a></h2>

            <form action="<?php echo admin_url('admin-post.php') ?>" method="post">
       		<?php $options = self::get_options() ?>
      	  	<?php wp_nonce_field(self::$short) ?>


                <p style="max-width: 500px;">Bitte tragen Sie in den folgenden Feldern Ihren KeywordMonitor Usernamen und den API Key ein. Den API Key finden Sie im <a href="https://app.keywordmonitor.de/" target="_blank">KeywordMonitor</a> unter dem Punkt "Account Details".</p>

                <div><label for="wp_keywordmonitor_de_username">KeywordMonitor Username</label></div>
                <div><input type="text" name="wp_keywordmonitor_de_username" id="wp_keywordmonitor_de_username" value="<?php echo esc_attr($options['username']); ?>" /></div>
                <div><label for="wp_keywordmonitor_de_api_key">API-Key</label></div>
                <div><input type="text" name="wp_keywordmonitor_de_api_key" id="wp_keywordmonitor_de_api_key" value="<?php echo esc_attr($options['api_key']); ?>" /></div>

        <?php
        if (!empty($options['username']) && !empty($options['api_key'])) {

            $projects = self::get_option('projects_list');

            if (!empty($projects)) {

                $projects_array = array();
                foreach ($projects as $project) {
                    $projects_array[$project['project_group_name']][$project['project_id']] = $project['name'];
                }
                unset($projects);
                unset($project);

                echo '<p style="max-width: 500px;">Wählen Sie hier Ihr Projekt aus. Sofern Sie noch kein Projekt für diese Wordpress Installation angelegt haben, dann holen Sie dies bitte vorher nach.</p>';
                echo '<div>';
                echo '<select name="wp_keywordmonitor_de_project_active_id" id="wp_keywordmonitor_de_project_active_id">';
                foreach ($projects_array as $project_group => $projects) {
                    echo '<optgroup label="' . $project_group . '">';
                    foreach ($projects as $project_id => $project_name) {


                        echo '<option value="' . (int) $project_id . '"' . ((int) $project_id === $options['project_active_id'] ? ' selected="selected"' : '') . '>';
#echo esc_html($v['hostname']); 
#echo ' ('; echo esc_html($v['name']); echo ')';

                        echo $project_name;
                        echo '</option>';
                    }

                    echo '</optgroup>';
                }
                echo '</select>';
                echo '</div>';
            }
        }
        ?>
                <p class="submit">
                    <input type="hidden" name="action" value="wp_keywordmonitor_de_save_changes" />
                    <input type="submit" class="button-primary" value="Speichern" />
                </p>
            </form>



        </div>
                <?php
            }

        }