<?php
/**
 * Plugin Name: Coin Comparison
 * Description: Плагін для порівняння цін на інвестиційні монети.
 * Version: 1.0
 * Author: Developer
 */
 


require_once plugin_dir_path(__FILE__) . 'admin-page.php';

function coin_comparison_install() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'coin_comparison_styles';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        media_query varchar(255) DEFAULT '',
        selector varchar(255) NOT NULL,
        styles text NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    $sql .= "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}plugin_settings (
        id INT NOT NULL AUTO_INCREMENT,
        proxy_url VARCHAR(255),
        PRIMARY KEY (id)
    ) $charset_collate;";    
    
    $sql .= "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}coin_columns (
        id INT NOT NULL AUTO_INCREMENT,
        column_name VARCHAR(255) NOT NULL,
        additionally TEXT,
        `order` INT NOT NULL DEFAULT 0,
        PRIMARY KEY (id)
    ) $charset_collate;";  

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Добавление предустановленных стилей
    $default_styles = [
        ['media_query' => '', 'selector' => '.coin-comparison-table', 'styles' => 'width: 100%; border-collapse: collapse;'],
        ['media_query' => '', 'selector' => '.coin-comparison-table th, .coin-comparison-table td', 'styles' => 'padding: 10px; border: 1px solid #ddd;'],
        ['media_query' => '', 'selector' => '.coin-comparison-table th', 'styles' => 'background-color: #f2f2f2; font-weight: bold;'],
        ['media_query' => '@media (max-width: 768px)', 'selector' => '.coin-comparison-table', 'styles' => 'font-size: 14px;'],
    ];

    foreach ($default_styles as $style) {
        $wpdb->insert(
            $table_name,
            [
                'media_query' => $style['media_query'],
                'selector' => $style['selector'],
                'styles' => $style['styles'],
            ]
        );
    }
    $table_name = $wpdb->prefix . "coins";
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        weight varchar(100),
        seller varchar(255),
        sell_price decimal(10,2),
        buy_price decimal(10,2),
        availability varchar(50),
        spread decimal(5,2),
        buy_url varchar(255),
        x_path_sell_price varchar(255),
        x_path_buy_price varchar(255),
        page_id bigint(20) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

register_activation_hook(__FILE__, 'coin_comparison_install');

function coin_comparison_admin_menu() {
    add_menu_page(
        'Coin Comparison',  
        'Coins',            
        'manage_options',   
        'coin_comparison',  
        'coin_comparison_admin_page' 
    );
}

add_action('admin_menu', 'coin_comparison_admin_menu');

function coin_comparison_generate_css() {
    global $wpdb;
    $table_name = $wpdb->prefix . "coin_comparison_styles";
    $styles = $wpdb->get_results("SELECT * FROM $table_name", ARRAY_A);

    $css = '';
    $current_media_query = '';
    $inside_media_query = false;

    foreach ($styles as $style) {
        if ($style['media_query'] !== $current_media_query) {
            if ($inside_media_query) {
                $css .= "}\n";
                $inside_media_query = false;
            }
            if ($style['media_query'] !== '') {
                $css .= $style['media_query'] . " {\n";
                $inside_media_query = true;
            }
            $current_media_query = $style['media_query'];
        }

        $css .= $style['selector'] . " {\n";
        $css .= "    " . $style['styles'] . "\n";
        $css .= "}\n";
    }

    if ($inside_media_query) {
        $css .= "}\n";
    }

    return $css;
}

function coin_comparison_shortcode() {
    static $executed = false;
    if ($executed) {
        return '';
    }
    $executed = true;
    ob_start(); 

    global $wpdb, $post;
    $table_name = $wpdb->prefix . "coins";
    $columns_table = $wpdb->prefix . "coin_columns";
    $settings_table = "plugin_settings";
    //$settings_table = $wpdb->"plugin_settings";
    $settings = $wpdb->get_row("SELECT * FROM $settings_table WHERE id = 1");
    
    if (isset($post) && !empty($post->ID)) {
        $current_page_id = $post->ID;
    } else {
        $current_page_id = 6;
    }

    
    $columns = $wpdb->get_results("
        SELECT column_name, column_label, `order`, additionally, custom_function, important 
        FROM $columns_table 
        ORDER BY `order` ASC
    ", ARRAY_A);

    
    $coin_columns = implode(', ', array_column($columns, 'column_name'));
    $filtered_columns = array_filter($columns, function($column) {
    return $column['column_name'] !== 'action';
});


$filtered_columns = implode(', ', array_column($filtered_columns, 'column_name'));
    $results = $wpdb->get_results($wpdb->prepare("
    SELECT $filtered_columns, active_button 
    FROM $table_name 
    WHERE page_id = %d
", $current_page_id), ARRAY_A);
    $results_settings = $wpdb->get_results(
        "SELECT button_style, button_name FROM plugin_settings"
    );

    $button_style = $results_settings[0]->button_style;
    $button_name = $results_settings[0]->button_name;

    $dynamic_css = coin_comparison_generate_css();
    echo '<style>' . $dynamic_css . '' . $button_style .'</style>';

    echo '<div class="coin-comparison-container">';
    echo '<button class="filter-button">Filtry</button>';
    echo '<div class="filters-container">';

    
    foreach ($columns as $column) {
        if ($column['column_name'] == 'action') {
        continue; 
        }
        if ($column['column_name'] == 'name') {
            echo '<div class="filter">
                <h3>' . esc_html($column['column_label']) . '</h3>
                <input type="text" id="name-filter" placeholder="Wpisz część lub całą nazwę" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
            </div>';
        } elseif (in_array($column['column_name'], ['weight', 'sell_price', 'buy_price', 'spread'])) {
            $values = array_column($results, $column['column_name']);
            $values = array_filter($values, 'is_numeric'); 
            $min = !empty($values) ? min($values) : 0;
            $max = !empty($values) ? max($values) : 0;
            echo '<div class="filter">
                <h3>' . esc_html($column['column_label']) . '</h3>
                <div class="range-slider-container">
                    <input type="range" id="' . esc_attr($column['column_name']) . '-slider-min" class="range-slider min-slider" min="' . $min . '" max="' . $max . '" value="' . $min . '">
                    <input type="range" id="' . esc_attr($column['column_name']) . '-slider-max" class="range-slider max-slider" min="' . $min . '" max="' . $max . '" value="' . $max . '">
                </div>
                <div class="range-values">
                    <input type="number" id="' . esc_attr($column['column_name']) . '-min" value="' . $min . '" min="' . $min . '" max="' . $max . '">
                    <input type="number" id="' . esc_attr($column['column_name']) . '-max" value="' . $max . '" min="' . $min . '" max="' . $max . '">
                </div>
            </div>';
        } elseif ($column['column_name'] == 'seller') {
    $sellers = array_unique(array_column($results, 'seller'));
    $unique_sellers = array_unique($sellers);
    echo '<div class="filter">
        <h3>' . esc_html($column['column_label']) . '</h3>
        <div class="checkbox-list" data-filter="seller">';
    foreach ($unique_sellers as $seller) {
    $count = count(array_filter($results, function($item) use ($seller) { return $item['seller'] === $seller; }));
    echo '<label><input type="checkbox" name="seller" value="' . esc_attr($seller) . '"> ' . esc_html($seller) . ' (' . $count . ')</label>';
}
    echo '</div></div>';
        } elseif ($column['column_name'] == 'availability') {
    echo '<div class="filter">
        <h3>' . esc_html($column['column_label']) . '</h3>
        <div class="checkbox-list" data-filter="availability">
            <label><input type="checkbox" name="availability" value="1"> Dostępny</label>
            <label><input type="checkbox" name="availability" value="0"> Niedostępny</label>
        </div>
    </div>';
}
    }

    echo '</div>';
    $newest_date = $wpdb->get_var($wpdb->prepare(
        "SELECT MAX(last_updated) FROM $table_name WHERE page_id = %d",
        $current_page_id
    ));
    $last_updated = new DateTime($newest_date);
    $current_time = new DateTime();
    $interval = $current_time->diff($last_updated);
    $hours = $interval->h + ($interval->days * 24);

    if ($hours < 1) {
        $update_text = 'Dane są aktualizowane co kilka godzin';
    } elseif ($hours < 2) {
        $update_text = 'Dane są aktualizowane co kilka godzin';
    } else {
        $update_text = 'Dane są aktualizowane co kilka godzin';
    }

    echo '<div class="f-cont"><div class="update-info" style="text-align: right;">' . $update_text . '</div><div class="table-container">';
echo '<table class="coin-table" id="coin-table">';

echo "<thead><tr>";
foreach ($columns as $column) {
    $data_column = in_array($column['column_name'], ['sell_price', 'buy_price', 'spread']) 
        ? ' data-column="' . esc_attr($column['column_name']) . '"' 
        : '';
    echo "<th$data_column>" . esc_html($column['column_label']) . "</th>";
}
echo "</tr></thead>";

echo "<tbody>";
foreach ($results as $row) {
    echo "<tr>";
    foreach ($columns as $column) {
        $value = $row[$column['column_name']];
        if ($column['column_name'] == 'availability') {
            $value = $value ? 'Dostępny' : 'Niedostępny';
        } elseif ($column['column_name'] == 'buy_url') {
            if ($row['availability'] != 0 && isset($row['active_button']) && $row['active_button'] == 1) {
                $value = '<a href="' . esc_url($value) . '" class="buy-button">Купити</a>';
            } else {
                $value = '';
            }
        }
        echo "<td data-label='" . esc_attr($column['column_label']) . "'>" . $value . "</td>";
    }
    echo "</tr>";
}
echo "</tbody>";
echo '</table>';

echo '</div>';

    echo '</div></div>';
    echo '<script>
document.addEventListener("DOMContentLoaded", function() {
    const headers = document.querySelectorAll("h2");
    
    headers.forEach((header, index) => {
        const content = header.nextElementSibling;
        
        if (index < 2) {
            content.style.display = "block";
            header.classList.remove("closed");
        } else {
            content.style.display = "none";
            header.classList.add("closed");
        }
        
        header.addEventListener("click", function() {
            this.classList.toggle("closed");
            
            if (content.style.display === "none") {
                content.style.display = "block";
            } else {
                content.style.display = "none";
            }
        });
    });
    /*
   const allCells = document.querySelectorAll(".coin-table td");
const thresholdWord = "mennicakapitalo";

allCells.forEach(cell => {
    const originalContent = cell.textContent;
    const originalWidth = cell.offsetWidth;
    const originalStyles = {
        maxWidth: cell.style.maxWidth,
        width: cell.style.width,
        whiteSpace: cell.style.whiteSpace,
        overflow: cell.style.overflow,
        textOverflow: cell.style.textOverflow,
        backgroundColor: cell.style.backgroundColor,
        boxShadow: cell.style.boxShadow,
        zIndex: cell.style.zIndex,
        position: cell.style.position
    };
    
    cell.addEventListener("mouseenter", function() {
        const temp = document.createElement("span");
        temp.style.visibility = "hidden";
        temp.style.whiteSpace = "nowrap";
        temp.style.position = "absolute";
        temp.textContent = originalContent;
        document.body.appendChild(temp);
        
        const fullWidth = temp.offsetWidth;
        
        const thresholdTemp = document.createElement("span");
        thresholdTemp.style.visibility = "hidden";
        thresholdTemp.style.whiteSpace = "nowrap";
        thresholdTemp.style.position = "absolute";
        thresholdTemp.textContent = thresholdWord;
        document.body.appendChild(thresholdTemp);
        
        const thresholdWidth = thresholdTemp.offsetWidth;
        
        document.body.removeChild(temp);
        document.body.removeChild(thresholdTemp);
        
        if (fullWidth > thresholdWidth) {
            const newWidth = fullWidth + 40;
            
            this.style.maxWidth = newWidth + "px";
            this.style.width = newWidth + "px";
            this.style.whiteSpace = "normal";
            this.style.overflow = "visible";
            this.style.textOverflow = "clip";
            this.style.backgroundColor = "#f0f8ff";
            this.style.boxShadow = "0 0 10px rgba(52, 152, 219, 0.3)";
            this.style.zIndex = "1";
            this.style.position = "relative";
        }
    });

    cell.addEventListener("mouseleave", function() {
        Object.assign(this.style, originalStyles);
    });
});
const allCells = document.querySelectorAll(".coin-table td");
allCells.forEach(cell => {
    const content = cell.textContent;
    if (content.length > 40) {
        const words = content.split(\' \');
        let newContent = \'\';
        let line = \'\';
        words.forEach(word => {
            if ((line + word).length > 40) {
                newContent += line + \'<br>\';
                line = word + \' \';
            } else {
                line += word + \' \';
            }
        });
        newContent += line;
        cell.innerHTML = newContent;
    }
});*/
});
</script>';

    echo '</div>';
    echo '</div>';

    return ob_get_clean(); 
}

add_shortcode('coin_comparison', 'coin_comparison_shortcode');


/* new code start*/
function coin_comparison_display_after_header() {
    if (is_page('coin-comparison')) {  // Removed is_front_page() check
        echo do_shortcode('[coin_comparison]');
    }
}

add_action('wp_footer', 'coin_comparison_display_after_header');

/*new code end*/


/* original code start

function coin_comparison_display_after_header() {
    if (is_front_page() || is_page('coin-comparison')) {
        echo do_shortcode('[coin_comparison]');
    }
}

add_action('wp_footer', 'coin_comparison_display_after_header');
original code end*/


function coin_comparison_schedule_parsing() {
    global $wpdb;

    
    $interval_result = $wpdb->get_results("SELECT update_frequency FROM plugin_settings");
    $update_frequency = $interval_result[0]->update_frequency;

    
    $interval_in_seconds = $update_frequency * 60;

    if (!wp_next_scheduled('coin_comparison_parse_prices')) {
        wp_schedule_event(time(), $interval_in_seconds, 'coin_comparison_parse_prices');
    }
}

add_action('wp', 'coin_comparison_schedule_parsing');


/* new code start*/
/* new code end*/


/* parser original code start*/

function coin_comparison_parse_prices() {
    global $wpdb;
    $table_name = $wpdb->prefix . "coins";
    $proxy_manager = new Proxy_Manager();
    $proxy = $proxy_manager->get_random_proxy();
    $coins = $wpdb->get_results("SELECT * FROM $table_name");

    foreach ($coins as $coin) {
        $parser = new Coin_Price_Parser($coin->buy_url, $coin->x_path_sell_price, $proxy);
        $sell_price = $parser->parse();
        $parser->xpath = $coin->x_path_buy_price;
        $buy_price = $parser->parse();

        $update_data = array(
            'last_updated' => current_time('mysql')
        );

        if ($sell_price !== null && $buy_price !== null) {
            $sell_price = str_replace(' ', '', $sell_price);
            $sell_price = str_replace(',', '.', $sell_price);
            $buy_price = str_replace(' ', '', $buy_price);
            $buy_price = str_replace(',', '.', $buy_price);

            $sell_price = floatval($sell_price);
            $buy_price = floatval($buy_price);

            $update_data['sell_price'] = $sell_price;
            $update_data['buy_price'] = $buy_price;
            $update_data['availability'] = ($sell_price > 0 && $buy_price > 0) ? 1 : 0;

            // Calculate spread only if both prices are available and greater than 0
            if ($sell_price > 0 && $buy_price > 0) {
                $spread = ($sell_price - $buy_price) / $sell_price * 100;
                $update_data['spread'] = $spread;
            } else {
                $update_data['spread'] = null; // Set spread to null if it can't be calculated
            }
        } else {
            $update_data['availability'] = 0;
            $update_data['spread'] = null; // Set spread to null if either price is missing
        }

        $wpdb->update(
            $table_name,
            $update_data,
            array('id' => $coin->id)
        );
    }
}


/*parser original code end*/



add_action('coin_comparison_parse_prices', 'coin_comparison_parse_prices');

function coin_comparison_filter_ajax() {
    check_ajax_referer('coin_comparison_filter', 'security');
    $filters = $_POST['filters'];
    
    global $wpdb;
    $table_name = $wpdb->prefix . "coins";
    $columns_table = $wpdb->prefix . "coin_columns";
    
    $columns = $wpdb->get_results("
        SELECT column_name, column_label, `order`
        FROM $columns_table 
        ORDER BY `order` ASC
    ", ARRAY_A);
    $sql = "SELECT * FROM $table_name WHERE 1=1";
    
    foreach ($columns as $column) {
        switch ($column['column_name']) {
            case 'name':
                if (!empty($filters['name'])) {
                    $sql .= $wpdb->prepare(" AND name LIKE %s", '%' . $wpdb->esc_like($filters['name']) . '%');
                }
                break;
            case 'weight':
                if (!empty($filters['weight'])) {
                    $sql .= $wpdb->prepare(" AND CAST(REGEXP_REPLACE(weight, '[^0-9.]', '') AS DECIMAL(10,2)) BETWEEN %f AND %f", 
                        floatval($filters['weight']['min']), 
                        floatval($filters['weight']['max'])
                    );
                }
                break;
            case 'sell_price':
            case 'buy_price':
            case 'spread':
                if (!empty($filters[$column['column_name']])) {
                    $sql .= $wpdb->prepare(" AND {$column['column_name']} BETWEEN %f AND %f", 
                        floatval($filters[$column['column_name']]['min']), 
                        floatval($filters[$column['column_name']]['max'])
                    );
                }
                break;
            case 'seller':
                if (!empty($filters['sellers'])) {
                    $sellers = implode("','", array_map('esc_sql', $filters['sellers']));
                    $sql .= " AND seller IN ('$sellers')";
                }
                break;
            case 'availability':
                if (!empty($filters['availability'])) {
                    $availability = implode("','", array_map('esc_sql', $filters['availability']));
                    $sql .= " AND availability IN ('$availability')";
                }
                break;
        }
    }
    
    $results = $wpdb->get_results($sql, ARRAY_A);
    
    ob_start();
    
    foreach ($results as $row) {
        echo "<tr>";
        foreach ($columns as $column) {
            $value = $row[$column['column_name']];
            if ($column['column_name'] == 'availability') {
                $value = $value ? 'Dostępny' : 'Niedostępny';
            } elseif ($column['column_name'] == 'buy_url') {
        if ($row['availability'] != 0 && $row['active_button'] == 1) {
            $value = '<a href="' . esc_url($value) . '" class="buy-button">Купити</a>';
        } else {
            $value = '';
        }}
        echo "<td data-label='" . esc_attr($column['column_label']) . "'>" . $value . "</td>";
        }
        echo "</tr>";
    }
    
    $table_html = ob_get_clean();
    
    wp_send_json_success($table_html);
}
add_action('wp_ajax_coin_comparison_filter', 'coin_comparison_filter_ajax');
add_action('wp_ajax_nopriv_coin_comparison_filter', 'coin_comparison_filter_ajax');

function coin_comparison_enqueue_scripts(){
    wp_enqueue_script('jquery');
    wp_enqueue_script('jquery-ui-core');
    wp_enqueue_script('jquery-ui-slider');
    wp_enqueue_style('jquery-ui-css', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
    wp_enqueue_script('coin-comparison-js', plugin_dir_url(__FILE__) . 'coin-comparison.js', array('jquery', 'jquery-ui-slider'), '1.0', true);

    global $wpdb;
    $table_name = $wpdb->prefix . "coins";
    $columns_table = $wpdb->prefix . "coin_columns";

    
    $columns = $wpdb->get_results("
        SELECT column_name, column_label, `order`, additionally, custom_function, important 
        FROM $columns_table 
        ORDER BY `order` ASC
    ", ARRAY_A);

    $results = $wpdb->get_results("SELECT * FROM $table_name", ARRAY_A);

    $weights = array_map(function($item) {
        preg_match('/(\d+(?:\.\d+)?)/', $item['weight'], $matches);
        return !empty($matches) ? floatval($matches[1]) : 0;
    }, $results);

    $spreads = array_map(function($item) {
        return isset($item['spread']) && $item['spread'] !== null ? floatval($item['spread']) : 0;
    }, $results);

    $coin_data = array(
        'weight' => array(
            'min' => min($weights),
            'max' => max($weights)
        ),
        'sell_price' => array(
            'min' => floatval(min(array_column($results, 'sell_price'))),
            'max' => floatval(max(array_column($results, 'sell_price')))
        ),
        'buy_price' => array(
            'min' => floatval(min(array_column($results, 'buy_price'))),
            'max' => floatval(max(array_column($results, 'buy_price')))
        ),
        'spread' => array(
            'min' => min($spreads),
            'max' => max($spreads)
        ),
        'sellers' => array_values(array_unique(array_column($results, 'seller'))),
        'availability' => array('Dostępny', 'Niedostępny')
    );

    wp_localize_script('coin-comparison-js', 'coinComparisonData', $coin_data);
    wp_localize_script('coin-comparison-js', 'coinComparisonColumns', $columns);
    wp_localize_script('coin-comparison-js', 'coin_comparison_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('coin_comparison_filter')
    ));

    wp_enqueue_style('coin-comparison-css', plugin_dir_url(__FILE__) . 'coin-comparison.css');
}
add_action('wp_enqueue_scripts', 'coin_comparison_enqueue_scripts');
function coin_comparison_log() {
    check_ajax_referer('coin_comparison_filter', 'security');
    if (isset($_POST['message'])) {
        error_log('Coin Comparison: ' . $_POST['message']);
    }
    wp_die();
}
add_action('wp_ajax_coin_comparison_log', 'coin_comparison_log');
add_action('wp_ajax_nopriv_coin_comparison_log', 'coin_comparison_log');