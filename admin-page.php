<?php
class Proxy_Manager {
    private $wpdb;
    private $option_name = 'coin_price_comparison_proxies';
    private $proxy_source_url;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        //$results_settings = $this->wpdb->get_results("SELECT proxy_url FROM plugin_settings");
        //$this->proxy_source_url = $results_settings[0]->proxy_url;
        $this->proxy_source_url = '';
        $settings = $wpdb->get_row("SELECT proxy_url FROM plugin_settings");
        if ($settings && isset($settings->proxy_url)) {
            $this->proxy_source_url = $settings->proxy_url;
        }
    }

    public function get_proxies() {
        return get_option($this->option_name, array());
    }

    public function add_proxy($proxy) {
        $proxies = $this->get_proxies();
        if (!in_array($proxy, $proxies)) {
            $proxies[] = $proxy;
            update_option($this->option_name, $proxies);
        }
    }

    public function remove_proxy($proxy) {
        $proxies = $this->get_proxies();
        $proxies = array_diff($proxies, array($proxy));
        update_option($this->option_name, $proxies);
    }

    public function get_random_proxy() {
        $proxies = $this->get_proxies();
        if (empty($proxies)) {
            $this->fetch_proxies_from_url();
            $proxies = $this->get_proxies();
        }
        return !empty($proxies) ? $proxies[array_rand($proxies)] : '127.0.0.1:8080';
    }

    private function fetch_proxies_from_url() {
        $response = wp_remote_get($this->proxy_source_url);

        if (is_wp_error($response)) {
            error_log('Помилка отримання проксі: ' . $response->get_error_message());
            return;
        }

        $body = wp_remote_retrieve_body($response);

        if (preg_match_all('/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}:\d{2,5})/', $body, $matches)) {
            $proxies = $matches[1];

            foreach ($proxies as $proxy) {
                $this->add_proxy($proxy);
            }
        } else {
            error_log('Не вдалося витягти проксі з сайту.');
        }
    }
}

class Coin_Price_Parser {
    public $url;
    public $xpath;
    private $proxy_manager;

    public function __construct($url, $xpath) {
        $this->url = $url;
        $this->xpath = $xpath;
        $this->proxy_manager = new Proxy_Manager();
    }

    public function set_xpath($xpath) {
        $this->xpath = $xpath;
    }

    public function reparse_all_coins() {
        global $wpdb;
        $table_name = $wpdb->prefix . "coins";
        
        $batch_size = 5;
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        
        $total_coins = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        
        $coins = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name LIMIT %d OFFSET %d",
            $batch_size,
            $offset
        ));
        
        $results = array(
            'processed' => 0,
            'updated' => 0,
            'failed' => 0,
            'logs' => array()
        );

        foreach ($coins as $coin) {
            $start_time = microtime(true);
            $results['processed']++;
            
            $this->url = $coin->buy_url;
            $updated_data = array(
                'last_parsed' => current_time('mysql')
            );
            
            $columns = get_dynamic_columns();
            foreach ($columns as $column) {
                if ($column->important == 1 && $column->additionally == 'xPath') {
                    $xpath_field = "x_path_" . $column->column_name;
                    if (!empty($coin->$xpath_field)) {
                        $this->set_xpath($coin->$xpath_field);
                        $value = $this->parse();
                        if ($value !== false) {
                            $updated_data[$column->column_name] = $value;
                        }
                    }
                }
            }
            
            $update_status = $wpdb->update(
                $table_name,
                $updated_data,
                array('id' => $coin->id)
            );
            
            if ($update_status !== false) {
                $results['updated']++;
                $results['logs'][] = array(
                    'status' => 'success',
                    'coin_id' => $coin->id,
                    'message' => sprintf(
                        'Updated successfully. New values: %s',
                        json_encode($updated_data)
                    ),
                    'time' => round(microtime(true) - $start_time, 2) . 's'
                );
            } else {
                $results['failed']++;
                $results['logs'][] = array(
                    'status' => 'error',
                    'coin_id' => $coin->id,
                    'message' => $wpdb->last_error,
                    'time' => round(microtime(true) - $start_time, 2) . 's'
                );
            }
            
            sleep(1); // Prevent server overload
        }
        
        update_option('coin_parser_last_run_logs', $results);
        
        return array(
            'processed' => $offset + count($coins),
            'total' => $total_coins,
            'progress' => round(($offset + count($coins)) / $total_coins * 100, 2),
            'completed' => count($coins) < $batch_size,
            'next_offset' => $offset + $batch_size,
            'logs' => $results['logs']
        );
    }

    public function parse() {
        $html = $this->fetch_html($this->url);
        if (empty($html)) {
            error_log("Отримано порожній HTML");
            return null;
        }

        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);

        try {
            $cleaned_xpath = stripslashes($this->xpath);
            error_log("Очищений XPath: " . $cleaned_xpath);
            $xpath->registerNamespace("php", "http://php.net/xpath");
            $xpath->registerPhpFunctions(['normalize-space', 'substring-before']);
            $result = @$xpath->evaluate($cleaned_xpath);
            error_log("Сирий результат XPath: " . print_r($result, true));

            if ($result === false) {
                $error_message = "Невірний вираз XPath: " . $cleaned_xpath;
                error_log($error_message);
                return $error_message;
            }

            if (is_object($result) && $result instanceof DOMNodeList) {
                if ($result->length > 0) {
                    $value = $result->item(0)->nodeValue;
                    error_log("Значення DOMNodeList: " . $value);
                } else {
                    $error_message = "Не знайдено вузлів для XPath: " . $cleaned_xpath;
                    error_log($error_message);
                    return $error_message;
                }
            } else {
                $value = $result;
                error_log("Прямий результат XPath: " . $value);
            }

            
            $value = trim($value);
            
            $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            
            $value = preg_replace('/[^0-9,\.\s]/', '', $value);
            
            $value = str_replace(' ', '', $value);
            
            $value = str_replace(',', '.', $value);
            
            if (substr_count($value, '.') > 1) {
                $parts = explode('.', $value);
                $last = array_pop($parts);
                $value = implode('', $parts) . '.' . $last;
            }
            
            error_log("Очищене значення: " . $value);
            
            if (is_numeric($value)) {
                $value = floatval($value);
                error_log("Числове значення: " . $value);
            }

            return $value;
        } catch (Exception $e) {
            $error_message = "Помилка XPath: " . $e->getMessage();
            error_log($error_message);
            return $error_message;
        }
    }


    private function fetch_html($url) {
        $args = array(
            'timeout' => 30,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        );

        $proxy = $this->proxy_manager->get_random_proxy();
        if ($proxy) {
            $args['proxy'] = $proxy;
        }

        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            $error_message = "Помилка отримання URL: " . $response->get_error_message();
            error_log($error_message);
            return $error_message;
        }

        return wp_remote_retrieve_body($response);
    }
}

class Import_Processor {
    private $batch_size = 10;
    private $proxy_manager;

    public function __construct() {
        $this->proxy_manager = new Proxy_Manager();
    }

    private function log_import($message, $status = 'info', $data = []) {
        $log_entry = [
            'timestamp' => current_time('mysql'),
            'status' => $status,
            'message' => $message,
            'data' => $data
        ];
        
        $current_logs = get_option('coin_import_logs', []);
        array_unshift($current_logs, $log_entry);

        $current_logs = array_slice($current_logs, 0, 1000);
        
        update_option('coin_import_logs', $current_logs);
    }

    public function process_import($input) {
    $this->log_import("Starting import process");
    
    if (is_string($input) && file_exists($input)) {
        $json_data = file_get_contents($input);
        $this->log_import("Reading from file: " . $input);
    } elseif (is_string($input)) {
        $json_data = $input;
        $this->log_import("Processing direct JSON input");
    } elseif (is_array($input)) {
        $json_data = json_encode($input);
        $this->log_import("Processing array input");
    } else {
        $this->log_import("Invalid input format", "error");
        return ['success' => false, 'error' => 'Invalid input format'];
    }

    $coins_data = json_decode($json_data, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        $this->log_import("JSON decode error: " . json_last_error_msg(), "error");
        return ['success' => false, 'error' => 'Invalid JSON'];
    }

    $this->log_import("Found " . count($coins_data['coins']) . " coins to import");

    foreach ($coins_data['coins'] as $coin) {
        try {
            $result = $this->process_single_coin($coin);
            if ($result['success']) {
                $this->log_import("Successfully imported coin: " . $coin['name'], "success", $result);
            } else {
                $this->log_import("Failed to import coin: " . $coin['name'], "error", $result);
            }
        } catch (Exception $e) {
            $this->log_import("Exception while importing coin: " . $coin['name'], "error", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    $this->log_import("Import process completed");
    return ['success' => true];
}

private function process_single_coin($coin_data) {
    global $wpdb;
    try {
        $parser = new Coin_Price_Parser($coin_data['buy_url'], '');
        $data = array(
            'buy_url' => $coin_data['buy_url'],
            'last_updated' => current_time('mysql'),
            'last_parsed' => current_time('mysql')
        );
        
        foreach (get_dynamic_columns() as $column) {
            if ($column->important == 1) {
                if ($column->additionally == 'xPath') {
                    $xpath_field = "x_path_{$column->column_name}";
                    if (isset($coin_data[$xpath_field])) {
                        $parser->set_xpath($coin_data[$xpath_field]);
                        $value = $parser->parse();
                        if ($value !== false) {
                            $data[$column->column_name] = $value;
                            $data[$xpath_field] = $coin_data[$xpath_field];
                        }
                    }
                }
            }
        }
        
        $availability_column = $wpdb->get_row("SELECT column_name FROM {$wpdb->prefix}coin_columns WHERE additionally = 'availability'");
        if ($availability_column && isset($data['sell_price']) && !empty($data['sell_price']) && $data['sell_price'] !== '-') {
            $data[$availability_column->column_name] = '1';
        }

        $table_name = $wpdb->prefix . "coins";
        
        if ($wpdb->insert($table_name, $data)) {
            return array('success' => true, 'message' => 'Coin imported successfully');
        }
        
        return array('success' => false, 'message' => 'Database insert failed');

    } catch (Exception $e) {
        return array('success' => false, 'message' => $e->getMessage());
    }
}

    private function schedule_batch_processing($batch) {
        wp_schedule_single_event(time(), 'process_coin_batch', array($batch));
    }

    public function process_batch($batch) {
        error_log("Starting batch processing");
        global $wpdb;
        $table_name = $wpdb->prefix . "coins";
        $columns_table = $wpdb->prefix . "coin_columns";

        foreach ($batch as $coin_data) {
            if (empty($coin_data['buy_url'])) {
                error_log("Error: missing required field buy_url for coin");
                continue;
            }

            try {
                $parser = new Coin_Price_Parser($coin_data['buy_url'], '');
                $data = array(
                    'buy_url' => $coin_data['buy_url'],
                    'last_updated' => current_time('mysql')
                );

                $columns = $wpdb->get_results("SELECT * FROM $columns_table");
                error_log("Retrieved columns: " . print_r($columns, true));

                foreach ($columns as $column) {
                    $column_name = $column->column_name;
                    if ($column->important == 1) {
                        if ($column->additionally == 'xPath') {
                            $xpath_field = "x_path_$column_name";
                            if (isset($coin_data[$xpath_field])) {
                                $parser->set_xpath($coin_data[$xpath_field]);
                                $value = $parser->parse();
                                if ($value !== false) {
                                    $data[$column_name] = $value;
                                    $data[$xpath_field] = $coin_data[$xpath_field];
                                } else {
                                    error_log("Failed to parse value for field $column_name. XPath: " . $coin_data[$xpath_field]);
                                }
                            }
                        } elseif (isset($coin_data[$column_name])) {
                            $data[$column_name] = $coin_data[$column_name];
                        }
                    } else {
                        error_log("rabotaet-data 0");
                        switch ($column->additionally) {
                            case 'domain':
                                $data[$column_name] = parse_url($coin_data['buy_url'], PHP_URL_HOST);
                                error_log("rabotaet-data 1");
                                break;
                            case 'availability':
                                $data[$column_name] = (isset($data['sell_price']) && !empty($data['sell_price']) && $data['sell_price'] !== '-') ? '1' : '0';
                                break;
                            case 'calc':
                                error_log("rabotaet-data 3");
                                error_log($data['sell_price']);
                                error_log($data['buy_price']);
                                if (isset($data['sell_price']) && isset($data['buy_price']) &&
                                    is_numeric($data['sell_price']) && is_numeric($data['buy_price']) &&
                                    $data['sell_price'] > 0 && $data['buy_price'] > 0) {
                                    $sell_price = floatval($data['sell_price']);
                                    $buy_price = floatval($data['buy_price']);
                                    $result = ($sell_price - $buy_price) / $sell_price * 100;
                                    $data[$column_name] = number_format($result, 2, '.', '');
                                } else {
                                    $data[$column_name] = "-";
                                }
                                break;
                        }
                    }
                }

                if (!empty($data)) {
                    $existing_coin = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM $table_name WHERE buy_url = %s",
                        $data['buy_url']
                    ));

                    if ($existing_coin) {
                        $wpdb->update($table_name, $data, array('id' => $existing_coin->id));
                        error_log("Updated existing record with ID: " . $existing_coin->id);
                    } else {
                        $result = $wpdb->insert($table_name, $data);
                        if ($result === false) {
                            error_log("Error inserting data into DB: " . $wpdb->last_error);
                        } else {
                            error_log("Successfully added new record with ID: " . $wpdb->insert_id);
                        }
                    }
                }

                $this->proxy_manager->get_random_proxy();
                sleep(rand(1, 5));

            } catch (Exception $e) {
                error_log("Exception while processing coin: " . $e->getMessage());
            }
        }

        error_log("Finished batch processing");
    }
}


$import_processor = new Import_Processor();
add_action('process_coin_batch', [$import_processor, 'process_batch']);

function create_styles_table() {
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

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

function add_last_parsed_column() {
    global $wpdb;
    $table_name = $wpdb->prefix . "coins";
    
    $column_exists = $wpdb->get_results($wpdb->prepare(
        "SELECT COLUMN_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = %s 
        AND TABLE_NAME = %s 
        AND COLUMN_NAME = 'last_parsed'",
        DB_NAME,
        $table_name
    ));

    if (empty($column_exists)) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN last_parsed DATETIME DEFAULT NULL");
    }
}

register_activation_hook(__FILE__, 'add_last_parsed_column');
add_action('plugins_loaded', 'add_last_parsed_column');


register_activation_hook(__FILE__, 'create_styles_table');

function initialize_default_styles() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'coin_comparison_styles';

    $default_styles = array(
        array(
            'media_query' => '',
            'selector' => '.coin-comparison-table',
            'styles' => 'width: 100%; border-collapse: collapse;'
        ),
        array(
            'media_query' => '',
            'selector' => '.coin-comparison-table th, .coin-comparison-table td',
            'styles' => 'padding: 10px; border: 1px solid #ddd;'
        ),
        array(
            'media_query' => '',
            'selector' => '.coin-comparison-table th',
            'styles' => 'background-color: #f2f2f2; font-weight: bold;'
        ),
        array(
            'media_query' => '@media (max-width: 768px)',
            'selector' => '.coin-comparison-table',
            'styles' => 'font-size: 14px;'
        ),
    );

    foreach ($default_styles as $style) {
        $wpdb->insert($table_name, $style);
    }
}

register_activation_hook(__FILE__, 'initialize_default_styles');

//add_action('wp_head', 'insert_plugin_styles');

function insert_plugin_styles() {
    echo '<style>' . generate_plugin_styles() . '</style>';
}

function generate_plugin_styles() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'coin_comparison_styles';

    $styles = $wpdb->get_results("SELECT * FROM $table_name ORDER BY media_query", ARRAY_A);

    $css = '';
    $current_media_query = '';

    foreach ($styles as $style) {
        if ($style['media_query'] !== $current_media_query) {
            if ($current_media_query !== '') {
                $css .= "}\n";
            }
            if ($style['media_query'] !== '') {
                $css .= $style['media_query'] . " {\n";
            }
            $current_media_query = $style['media_query'];
        }

        $css .= $style['selector'] . " {\n";
        $css .= "    " . $style['styles'] . "\n";
        $css .= "}\n";
    }

    if ($current_media_query !== '') {
        $css .= "}\n";
    }

    return $css;
}

function coin_comparison_admin_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . "coins";
    $table_name_style = $wpdb->prefix . 'coin_comparison_styles';
    $error_messages = array();
    $success_message = '';
    $form_data = array();

    function validate_xpath($url, $xpath) {
        $parser = new Coin_Price_Parser($url, $xpath);
        $result = $parser->parse();
        if ($result !== null) {
            $result = str_replace(' ', '', $result);
            $result = str_replace(',', '.', $result);
            return floatval($result);
        }
        return false;
    }
    if (isset($_POST['save_styles'])) { 
    $wpdb->query("DELETE FROM $table_name_style"); // Remove all records from the table
    foreach ($_POST['styles'] as $style) { 
        $style['media_query'] = stripslashes($style['media_query']);
        $style['selector'] = stripslashes($style['selector']);
        $style['styles'] = stripslashes($style['styles']);
        $wpdb->insert($table_name_style, array( 
            'media_query' => sanitize_text_field($style['media_query']), 
            'selector' => sanitize_text_field($style['selector']), 
            'styles' => sanitize_textarea_field($style['styles']) 
        )); 
    } 
    echo '<div class="updated"><p>Стили сохранены.</p></div>'; 
}


    if (isset($_POST['import_coins'])) {
        if (!empty($_FILES['import_file']['tmp_name'])) {
            $file_content = file_get_contents($_FILES['import_file']['tmp_name']);
            $coins_data = json_decode($file_content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $import_processor = new Import_Processor();
                
                $import_page_id = intval($_POST['import_page_id']);
                
                foreach ($coins_data as &$coin) {
                    $coin['page_id'] = $import_page_id;
                }
                
                $result = $import_processor->process_import($coins_data);
                
                if ($result['success']) {
                    $success_message = 'Імпорт успішно запущено. Оброблено монет: ' . $result['processed_count'];
                } else {
                    $error_messages['import'] = 'Помилка при імпорті: ' . $result['message'];
                }
            } else {
                $error_messages['import'] = 'Невірний формат файлу. Будь ласка, завантажте коректний JSON файл.'.$coins_data;
            }
        } else {
            $error_messages['import'] = 'Файл не був завантажений. Будь ласка, виберіть файл для імпорту.';
        }
    }
    $pages = get_pages();

    $columns = get_dynamic_columns();


if (isset($_POST['add_coin'])) {
    global $wpdb;
    $form_data = array();
    $error_messages = array();
    $columns_table = $wpdb->prefix . 'coin_columns';
    $table_name = $wpdb->prefix . 'coins';

    $form_data['buy_url'] = esc_url_raw($_POST['buy_url']);
    if (empty($form_data['buy_url']) || !filter_var($form_data['buy_url'], FILTER_VALIDATE_URL)) {
        $error_messages['buy_url'] = "Невірний формат URL для покупки. Будь ласка, введіть коректний URL, що починається з http:// або https://.";
    }

    foreach ($columns as $column) {
        $field_name = $column->column_name;
        if ($column->important == 1) {
            if ($column->additionally == 'xPath') {
                $xpath_field = "x_path_$field_name";
                $form_data[$xpath_field] = sanitize_text_field($_POST[$xpath_field]);
            } else {
                $form_data[$field_name] = sanitize_text_field($_POST[$field_name]);
                if (empty($form_data[$field_name])) {
                    $error_messages[$field_name] = "Поле '{$column->column_label}' не може бути порожнім.";
                }
            }
        }
    }

    $form_data['page_id'] = intval($_POST['page_id']);

    if (empty($error_messages)) {
        $data = array(
            'buy_url' => $form_data['buy_url'],
            'page_id' => $form_data['page_id']
        );

        foreach ($columns as $column) {
            $field_name = $column->column_name;
            if ($column->important == 1) {
                if ($column->additionally == 'xPath') {
                    $xpath_field = "x_path_$field_name";
                    $data[$xpath_field] = $form_data[$xpath_field];
                    $value = validate_xpath($form_data['buy_url'], $form_data[$xpath_field]);
                    $data[$field_name] = ($value !== false) ? $value : '-';
                } else {
                    $data[$field_name] = $form_data[$field_name];
                }
            } else {
                switch ($column->additionally) {
                    case 'domain':
                        $data[$field_name] = parse_url($form_data['buy_url'], PHP_URL_HOST);
                        break;
                    case 'conditional':
                        $data[$field_name] = !empty($data['sell_price']) ? 1 : 0;
                        break;
                    case 'calc':
                        if (isset($data['sell_price'], $data['buy_price']) &&
                            is_numeric($data['sell_price']) && is_numeric($data['buy_price']) &&
                            $data['sell_price'] > 0 && $data['buy_price'] > 0) {
                            $sell_price = floatval($data['sell_price']);
                            $buy_price = floatval($data['buy_price']);
                            $result = ($sell_price - $buy_price) / $sell_price * 100;
                            $data[$field_name] = number_format($result, 2, '.', '');
                        } else {
                            $data[$field_name] = "-";
                        }
                        break;
                }
            }
        }

        $spread_column = $wpdb->get_row("SELECT column_name FROM $columns_table WHERE additionally = 'calc'");

        if ($spread_column) {
            if (isset($data['sell_price'], $data['buy_price']) && 
                is_numeric($data['sell_price']) && is_numeric($data['buy_price']) && 
                $data['sell_price'] >= 1 && $data['buy_price'] >= 1) {
                $spread = ($data['sell_price'] - $data['buy_price']) / $data['sell_price'] * 100;
                $data[$spread_column->column_name] = number_format($spread, 2, '.', '');
            }
        }

        $availability_column = $wpdb->get_row("SELECT column_name FROM {$wpdb->prefix}coin_columns WHERE additionally = 'availability'");
        if ($availability_column && isset($data['sell_price']) && !empty($data['sell_price']) && $data['sell_price'] !== '-') {
            $data[$availability_column->column_name] = '1';
        }

        $wpdb->insert($table_name, $data);

        $success_message = 'Нову монету успішно додано.';
        $form_data = array();
    }
}

if (isset($_POST['toggle_active'])) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'coins';
    
    $id = intval($_POST['id']);
    $active = intval($_POST['active']);
    
    $wpdb->update(
        $table_name,
        array('active_button' => $active),
        array('id' => $id),
        array('%d'),
        array('%d')
    );
    
    $success_message = $active ? 'Кнопку успішно активовано.' : 'Кнопку успішно деактивовано.';
}
 if (isset($_POST['delete_coin'])) {
        $id = intval($_POST['id']);
        $wpdb->delete($table_name, array('id' => $id));
        $success_message = 'Монету успішно видалено.';
    }
if (!empty($error_messages)) {
    echo '<div class="error"><ul>';
    foreach ($error_messages as $field => $error) {
        echo '<li><strong>' . esc_html($field) . '</strong>: ' . esc_html($error) . '</li>';
    }
    echo '</ul></div>';
}

if (!empty($success_message)) {
    echo '<div class="updated"><p>' . esc_html($success_message) . '</p></div>';
}

echo '<h1>Налаштування плагіна</h1>';

echo '<div id="coin-parsing-progress" style="display:none;">
        <div class="progress-bar" style="width:100%; height:20px; background:#f0f0f0;">
            <div id="progress-bar-fill" style="width:0%; height:100%; background:#0073aa;"></div>
        </div>
        <p>Processed: <span id="coins-processed">0</span> of <span id="total-coins">0</span></p>
    </div>
    <script>
    function startParsing() {
    jQuery("#coin-parsing-progress").css("display", "block");
    jQuery("#coin-parsing-progress").show();
    jQuery("#logs-container").empty();
    parseNextBatch(0);
}

function parseNextBatch(offset) {
    jQuery.ajax({
        url: ajaxurl,
        type: "POST",
        data: {
            action: "parse_coins_batch",
            offset: offset
        },
        success: function(response) {
            if (response.success) {
                updateProgress(response.data);
                displayLogs(response.data.logs);
                if (!response.data.completed) {
                    parseNextBatch(response.data.next_offset);
                } else {
                    jQuery("#logs-container").prepend(
                        \'<div class="log-entry success">Parsing completed successfully!</div>\'
                    );
                }
            }
        }
    });
}

function updateProgress(data) {
    jQuery("#coins-processed").text(data.processed);
    jQuery("#total-coins").text(data.total);
    jQuery("#progress-bar-fill").css("width", data.progress + "%");
}

function displayLogs(logs) {
    logs.forEach(function(log) {
        const logClass = log.status === "success" ? "success" : "error";
        const logEntry = `
            <div class="log-entry ${logClass}" style="margin-bottom: 10px; padding: 5px; border-left: 4px solid ${log.status === "success" ? "#46b450" : "#dc3232"}">
                <strong>Coin ID ${log.coin_id}</strong> - ${log.message}<br>
                <small>Processing time: ${log.time}</small>
            </div>
        `;
        jQuery("#logs-container").prepend(logEntry);
    });
}
    </script>
    <button onclick="startParsing()" class="button button-primary">Update All Coins</button>';
    echo '<div id="coin-parsing-progress" style="display:none;">
    <div class="progress-bar" style="width:100%; height:20px; background:#f0f0f0;">
        <div id="progress-bar-fill" style="width:0%; height:100%; background:#0073aa;"></div>
    </div>
    <p>Processed: <span id="coins-processed">0</span> of <span id="total-coins">0</span></p>
    
    <div id="parsing-logs" style="margin-top: 20px;">
        <h3>Update Logs</h3>
        <div id="logs-container" style="max-height: 300px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #f9f9f9;">
        </div>
    </div>
</div>';

$import_results = get_option('last_import_results');
if ($import_results) {
    echo '<div class="import-results">';
    echo '<h3>Результати останнього імпорту</h3>';
    echo '<p>Всього монет: ' . $import_results['total'] . '</p>';
    echo '<p>Успішно: ' . $import_results['success'] . '</p>';
    echo '<p>Помилок: ' . $import_results['failed'] . '</p>';
    echo '<div class="import-details">';
    foreach ($import_results['details'] as $detail) {
        echo '<div class="detail-row ' . ($detail['status'] == 'Success' ? 'success' : 'error') . '">';
        echo esc_html($detail['name']) . ': ' . esc_html($detail['message']);
        echo '</div>';
    }
    echo '</div>';
    echo '</div>';
}

$table_name_style = $wpdb->prefix . 'coin_comparison_styles';   
$styles = $wpdb->get_results("SELECT * FROM $table_name_style", ARRAY_A);

    
echo '<h2>Додати монету</h2>';
echo '<form method="POST">';
echo '<table>';


echo '<tr><td data-label="">URL для покупки:</td><td data-label=""><input type="text" name="buy_url" value="' . esc_url($form_data['buy_url'] ?? '') . '" required></td></tr>';


foreach ($columns as $column) {
    if ($column->important == 1) {
        $field_name = $column->column_name;
        $field_label = $column->column_label;


        if ($column->additionally == 'xPath') {
            echo '<tr><td data-label="">' . esc_html($field_label) . ' ( xPath ):</td><td data-label="">';
            echo '<input type="text" name="x_path_' . esc_attr($field_name) . '" value="' . esc_attr($form_data['x_path_' . $field_name] ?? '') . '">';
        } else {
            echo '<tr><td data-label="">' . esc_html($field_label) . ':</td><td data-label="">';
            echo '<input type="text" name="' . esc_attr($field_name) . '" value="' . esc_attr($form_data[$field_name] ?? '') . '" required>';
        }

        echo '</td></tr>';
    }
}


echo '<tr><td data-label="">Сторінка:</td><td data-label=""><select name="page_id" required>';
echo '<option value="">Виберіть сторінку</option>';
foreach ($pages as $page) {
    $selected = (isset($form_data['page_id']) && $form_data['page_id'] == $page->ID) ? 'selected' : '';
    echo '<option value="' . esc_attr($page->ID) . '" ' . $selected . '>' . esc_html($page->post_title) . '</option>';
}
echo '</select></td></tr>';

echo '</table>';
echo '<input type="submit" name="add_coin" value="Додати монету" class="button button-primary">';
echo '</form>';


    echo '<h2>Імпорт монет</h2>';
    echo '<form method="POST" enctype="multipart/form-data">';
    echo '<input type="file" name="import_file" accept=".json">';
    echo '<select name="import_page_id" required>';
    echo '<option value="">Виберіть сторінку для імпорту</option>';
    foreach ($pages as $page) {
        echo '<option value="' . esc_attr($page->ID) . '">' . esc_html($page->post_title) . '</option>';
    }
    echo '</select>';
    echo '<input type="submit" name="import_coins" value="Імпортувати монети">';
    echo '</form>';

    $settings = handle_plugin_settings();
    echo '<h2>Налаштування плагіну</h2>';
    echo '<form method="POST">';
    echo '<table>';
    echo '<tr><td data-label="">URL проксі:</td><td data-label=""><input type="text" name="proxy_url" value="' . esc_attr($settings->proxy_url ?? '') . '" style="width: 100%;"></td></tr>';
    echo '<tr><td data-label="">Частота оновлення ( мин. ):</td><td data-label=""><input type="text" name="update_frequency" value="' . esc_attr($settings->update_frequency ?? '') . '" style="width: 100%;"></td></tr>';
    echo '<tr><td data-label="">Стилі кнопки:</td><td data-label=""><textarea name="button_style" rows="5" style="width: 100%;">' . esc_textarea($settings->button_style ?? '') . '</textarea></td></tr>';
    echo '<tr><td data-label="">Назва кнопки:</td><td data-label=""><input type="text" name="button_name" value="' . esc_attr($settings->button_name ?? '') . '" style="width: 100%;"></td></tr>';
    echo '</table>';
    echo '<input type="submit" name="save_settings" value="Зберегти налаштування" class="button button-primary">';
    echo '</form>';

    echo '<h2>Управление колонками</h2>';
    handle_column_management();

    echo '<h2>Настройки стилей плагина</h2>';
    echo '<form method="POST" action="">';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>Медиа-запрос</th><th>Селектор</th><th>Стили</th><th>Действия</th></tr></thead>';
    echo '<tbody id="style-rows">';
    foreach ($styles as $index => $style) {
        echo '<tr>';
        echo '<td><input type="text" name="styles[' . $index . '][media_query]" value="' . esc_attr($style['media_query']) . '" placeholder="@media (max-width: 768px)"></td>';
        echo '<td><input type="text" name="styles[' . $index . '][selector]" value="' . esc_attr($style['selector']) . '"></td>';
        echo '<td><textarea name="styles[' . $index . '][styles]" rows="3">' . esc_textarea($style['styles']) . '</textarea></td>';
        echo '<td><button type="button" class="button remove-style">Удалить</button></td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '<button type="button" id="add-style" class="button">Добавить стиль</button>';
    echo '<input type="submit" name="save_styles" value="Сохранить стили" class="button button-primary">';
    echo '</form>';
    $results = $wpdb->get_results("SELECT * FROM $table_name");
    echo '<h2>Список монет</h2>';
    echo "<div>";
    echo '<div class="export-container">';
        echo '<button class="export-button" id="exportCSV">Експортувати</button>';
    echo '</div>';
   echo '<script>
    jQuery(document).ready(function($) {
        $("#add-style").click(function() {
            var index = $("#style-rows tr").length;
            var newRow = "<tr>" +
                "<td><input type=\"text\" name=\"styles[" + index + "][media_query]\" placeholder=\"@media (max-width: 768px)\"></td>" +
                "<td><input type=\"text\" name=\"styles[" + index + "][selector]\"></td>" +
                "<td><textarea name=\"styles[" + index + "][styles]\" rows=\"3\"></textarea></td>" +
                "<td><button type=\"button\" class=\"button remove-style\">Удалить</button></td>" +
                "</tr>";
            $("#style-rows").append(newRow);
        });

        $(document).on("click", ".remove-style", function() {
            $(this).closest("tr").remove();
        });
    });
</script>';


     echo '<style>
     .log-entry {
    font-family: monospace;
    font-size: 13px;
    line-height: 1.4;
    background: #fff;
}

.log-entry.success {
    background-color: rgba(70, 180, 80, 0.1);
}

.log-entry.error {
    background-color: rgba(220, 50, 50, 0.1);
}

#parsing-logs {
    margin-top: 20px;
    border-radius: 4px;
}

#logs-container:empty:before {
    content: "Waiting for updates...";
    color: #666;
    font-style: italic;
    display: block;
    padding: 10px;
}
body {
    font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
    line-height: 1.6;
    color: #333;
    background-color: #f7f9fc;
    padding: 20px;
}

h1, h2 {
    color: #2c3e50;
    margin-bottom: 20px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
}

h2::after {
    content: "▼";
    margin-left: 10px;
    transition: transform 0.3s ease;
}

h2.closed::after {
    transform: rotate(-90deg);
}


table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    margin-bottom: 20px;
    background-color: #fff;
    box-shadow: 0 2px 15px rgba(0,0,0,0.1);
    border-radius: 8px;
    overflow: hidden;
    table-layout: fixed;
}

th, td {
    padding: 15px;
    text-align: left;
    border-bottom: 1px solid #e0e0e0;
    transition: all 0.3s ease;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

td {
    transition: max-width 0.3s ease, width 0.3s ease, background-color 0.3s ease, box-shadow 0.3s ease;
}


form {
    background-color: #fff;
    padding: 25px;
    border-radius: 8px;
    box-shadow: 0 2px 15px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

input[type="text"], input[type="file"], select, textarea {
    margin: 10px 0;
    width: 100%;
    padding: 12px;
    margin-bottom: 15px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 16px;
    transition: border-color 0.3s ease;
}

input[type="text"]:focus, input[type="file"]:focus, select:focus, textarea:focus {
    border-color: #3498db;
    outline: none;
    box-shadow: 0 0 5px rgba(52, 152, 219, 0.5);
}

 

input[type="submit"], 
button,
.button {
    background-color: #3498db;
    color: #fff;
    border: none;
    padding: 5px;
    margin-bottom: 3px;
    cursor: pointer;
    border-radius: 4px;
    font-size: 12px !important;
    font-weight: 600;
    transition: all 0.3s ease;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: inline-block;
    text-decoration: none;
    text-align: center;
}

input[type="submit"]:hover, 
button:hover,
.button:hover {
    background-color: #2980b9;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

 

.updated, .error {
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 4px;
    font-weight: 600;
}

.updated {
    background-color: #d4edda;
    border-left: 5px solid #28a745;
    color: #155724;
}

.error {
    background-color: #f8d7da;
    border-left: 5px solid #dc3545;
    color: #721c24;
}

 

@media (max-width: 768px) {
    table {
        display: block;
    }
    button{
        padding: 5px;
    font-size: 14px;
    }
    thead {
        display: none;
    }
    
    tbody {
        display: block;
    }
    
    tr {
        display: block;
        margin-bottom: 15px;
        border: 1px solid #ddd;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
    
    td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border: none;
        padding: 10px 15px;
        text-align: right;
        max-width: none;
        overflow: visible;
        white-space: normal;
    }
    
    td:before {
        content: attr(data-label);
        font-weight: bold;
        text-align: left;
        padding-right: 10px;
    }
}
input[type="file"]{
    width: fit-content;
    margin-right: 10px;
}
.button.button-primary {
    display: block;
    width: 100%;
    margin-top: 10px;
}

</style>';

    echo '<script>
    document.addEventListener("DOMContentLoaded", function() {
    var csvBtn = document.getElementById("exportCSV");
    csvBtn.onclick = function(e) {
        e.preventDefault();
        exportTableToCSV("coin_comparison.csv");
    }

    function exportTableToCSV(filename) {
        var csv = [];
        var rows = document.querySelectorAll("#coins-table tr");
        
        for (var i = 0; i < rows.length; i++) {
            var row = [], cols = rows[i].querySelectorAll("td, th");
            
            for (var j = 0; j < cols.length - 1; j++) {
                row.push(cols[j].innerText);
            }
            
            csv.push(row.join(","));        
        }

        downloadCSV(csv.join("\n"), filename);
    }

    function downloadCSV(csv, filename) {
        var csvFile;
        var downloadLink;
        csvFile = new Blob(["\ufeff" + csv], {type: "text/csv;charset=utf-8;"});
        downloadLink = document.createElement("a");
        downloadLink.download = filename;
        downloadLink.href = window.URL.createObjectURL(csvFile);
        downloadLink.style.display = "none";
        document.body.appendChild(downloadLink);
        downloadLink.click();
    }
});

    </script>';
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
   const allCells = document.querySelectorAll("#coins-table td");
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
});
</script>';


    
    if ($results && !empty($results)) {
        display_coins_table($results);
    } else {
        echo '<p>Нет данных о монетах.</p>';
    }
}

function display_coins_table($results) {
    $columns = get_dynamic_columns();
    $pages = get_pages(); 

    echo '<table id="coins-table">';
    echo '<thead>';
    echo '<tr>';
    foreach ($columns as $column) {
        echo '<th>' . esc_html($column->column_label) . '</th>';
    }
    echo '<th>Страница</th>'; 
    echo '<th>Действия</th>';
    echo '</tr>';
    echo '</thead>';

    echo '<tbody>';
    foreach ($results as $row) {
        echo '<tr data-id="' . esc_attr($row->id) . '">';
        foreach ($columns as $column) {
            $column_name = $column->column_name;
            echo '<td data-label="' . esc_attr($column_name) . '" data-column="' . esc_attr($column_name) . '">' . esc_html($row->$column_name) . '</td>';
        }
        
        
        echo '<td data-label="page_id" data-column="page_id">';
        echo esc_html(get_the_title($row->page_id)); 
        echo '</td>';

        $active_button = intval($row->active_button);
        echo '<td data-label="">
            <button class="edit-coin">Редактировать</button>
            <button class="save-coin" style="display:none;">Сохранить</button>
            <button class="cancel-edit" style="display:none;">Отмена</button>';
        $active_value = $active_button === 1 ? 0 : 1;
        $button_label = $active_button === 1 ? 'Деактивувати кнопку' : 'Активувати кнопку';
        echo '<form method="POST" style="display:inline;padding: 0;margin: 0;">
        <input type="hidden" name="id" value="' . esc_attr($row->id) . '">
        <input type="hidden" name="active" value="' . $active_value . '">
        <input type="submit" name="toggle_active" value="' . $button_label . '">
      </form>';
        echo '<form method="POST" style="display:inline;padding: 0;margin: 0;">
            <input type="hidden" name="id" value="' . esc_attr($row->id) . '">
            <input type="submit" name="delete_coin" value="Удалить" onclick="return confirm(\'Вы уверены, что хотите удалить эту монету?\');">
        </form>
        </td>';
        echo '</tr>';
     }
    echo '</tbody>';
    echo '</table></div>';

    echo '<script>
    jQuery(document).ready(function($) {
        $(".edit-coin").click(function() {
            var row = $(this).closest("tr");
            row.find("td:not(:last-child)").each(function() {
                var content = $(this).text();
                var column = $(this).data("column");
                if (column === "page_id") {
                    var currentPageId = $(this).text();
                    var select = $("<select name=\'page_id\' required>");
                    select.append("<option value=\'\'>Выберите страницу</option>");
                    ' . json_encode($pages) . '.forEach(function(page) {
                        var selected = (currentPageId == page.post_title) ? "selected" : "";
                        select.append("<option value=\'" + page.ID + "\' " + selected + ">" + page.post_title + "</option>");
                    });
                    $(this).html(select);
                } else {
                    $(this).html("<input type=\'text\' value=\'" + content + "\'>");
                }
            });
            row.find(".edit-coin").hide();
            row.find(".save-coin, .cancel-edit").show();
        });

        $(".cancel-edit").click(function() {
            var row = $(this).closest("tr");
            row.find("td:not(:last-child)").each(function() {
                var content = $(this).find("input, select").val();
                if ($(this).data("column") === "page_id") {
                    content = $(this).find("select option:selected").text();
                }
                $(this).html(content);
            });
            row.find(".edit-coin").show();
            row.find(".save-coin, .cancel-edit").hide();
        });

        $(".save-coin").click(function() {
            var row = $(this).closest("tr");
            var id = row.data("id");
            var data = {
                action: "update_coin",
                id: id
            };
            row.find("td:not(:last-child)").each(function() {
                var column = $(this).data("column");
                var value = column === "page_id" ? $(this).find("select").val() : $(this).find("input").val();
                data[column] = value;
            });
            $.post(ajaxurl, data, function(response) {
                if (response.success) {
                    row.find("td:not(:last-child)").each(function() {
                        var column = $(this).data("column");
                        if (column === "page_id") {
                            var selectedText = $(this).find("select option:selected").text();
                            $(this).html(selectedText);
                        } else {
                            var content = $(this).find("input").val();
                            $(this).html(content);
                        }
                    });
                    row.find(".edit-coin").show();
                    row.find(".save-coin, .cancel-edit").hide();
                } else {
                    alert("Ошибка при сохранении изменений");
                }
            });
        });
    });
    </script>';
}



function handle_column_management() {
    global $wpdb;
    $columns_table = $wpdb->prefix . 'coin_columns';

    
    if (isset($_POST['add_column'])) {
    $column_name = sanitize_text_field($_POST['column_name']);
    $column_label = sanitize_text_field($_POST['column_label']);
    $additionally = sanitize_text_field($_POST['additionally']);
    
    $wpdb->insert($columns_table, array(
        'column_name' => $column_name,
        'column_label' => $column_label,
        'additionally' => $additionally,
        'order' => $wpdb->get_var("SELECT MAX(`order`) FROM $columns_table") + 1
    ));
    $table_name = $wpdb->prefix . "coins";
    $wpdb->query("ALTER TABLE $table_name ADD COLUMN $column_name varchar(255) COLLATE utf8mb4_unicode_520_ci");
    
    echo '<div class="updated"><p>Колонка добавлена.</p></div>';
}


    
    if (isset($_POST['delete_column'])) {
    $column_id = intval($_POST['delete_column']);
    $column = $wpdb->get_row($wpdb->prepare("SELECT * FROM $columns_table WHERE id = %d", $column_id));
    if ($column) {
        $wpdb->delete($columns_table, array('id' => $column_id));
        $table_name = $wpdb->prefix . "coins";

        $wpdb->query("ALTER TABLE $table_name DROP COLUMN " . esc_sql($column->column_name));
        
        echo '<div class="updated"><p>Колонка "' . esc_html($column->column_label) . '" удалена.</p></div>';
    } else {
        echo '<div class="error"><p>Колонка не найдена.</p></div>';
    }
}


    
    if (isset($_POST['save_order'])) {
        $column_order = $_POST['column_order'];
        foreach ($column_order as $order => $id) {
            $wpdb->update($columns_table, array('order' => $order), array('id' => $id));
        }
        echo '<div class="updated"><p>Порядок колонок сохранен.</p></div>';
    }

    
    if (isset($_POST['edit_column'])) {
    $column_id = intval($_POST['edit_column']);
    $new_label = sanitize_text_field($_POST['new_label']);
    $important = intval($_POST['important']);

    $wpdb->update($columns_table, array(
        'column_label' => $new_label,
        'important' => $important
    ), array('id' => $column_id));

    echo '<div class="updated"><p>Обновлено.</p></div>';
}
    
    echo '<div><form method="POST">';
    echo '<input type="text" name="column_name" placeholder="Ім\'я колонки" required>';
    echo '<input type="text" name="column_label" placeholder="Мітка колонки" required>';

    echo '<select name="additionally">';
    $existing_additionally_values = $wpdb->get_results("SELECT DISTINCT additionally FROM wp_coin_columns WHERE additionally != ''", ARRAY_A);
    foreach ($existing_additionally_values as $value) {
        echo '<option value="' . esc_attr($value['additionally']) . '">' . esc_html($value['additionally']) . '</option>';
    }
    echo '</select>';

    echo '<input type="submit" name="add_column" value="Додати колонку" class="button button-primary">';
    echo '</form>';

    
     $columns = $wpdb->get_results("SELECT * FROM $columns_table ORDER BY `order`");
    if ($columns) {
        echo '<form method="POST">';
        echo '<ul id="sortable-columns">';
        foreach ($columns as $column) {
            echo '<li data-id="' . $column->id . '">';
            echo '<span class="column-label">' . esc_html($column->column_label) . '</span>';
            echo ' (' . esc_html($column->column_name) . ')';
            echo ' <button type="button" class="edit-column">Редагувати</button>';
            echo ' <button type="submit" name="delete_column" value="' . $column->id . '">Видалити</button>';
            echo '<input type="hidden" name="column_order[]" value="' . $column->id . '">';
            echo '</li>';
        }
        echo '</ul>';
        echo '<input type="submit" name="save_order" value="Зберегти порядок" class="button button-primary">';
        echo '</form></div>';

        
        echo '<script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>';
        echo "<script>jQuery(document).ready(function($) {
    $('#sortable-columns').on('click', '.edit-column', function() {
        var li = $(this).closest('li');
        var label = li.find('.column-label');
        var currentLabel = label.text();
        var columnId = li.data('id');
        var currentImportant = li.data('important'); 
        label.html('<input type=\"text\" name=\"new_label\" value=\"' + currentLabel + '\">');
        label.append('<select name=\"important\"><option value=\"1\"' + (currentImportant == 1 ? ' selected' : '') + '>Використовувати при парсингу</option><option value=\"0\"' + (currentImportant == 0 ? ' selected' : '') + '>Не використовувати при парсингу</option></select>');
        $(this).text('Зберегти').removeClass('edit-column').addClass('save-column');
    });

    $('#sortable-columns').on('click', '.save-column', function() {
        var li = $(this).closest('li');
        var label = li.find('.column-label');
        var newLabel = li.find('input[name=\"new_label\"]').val();
        var important = parseInt(li.find('select[name=\"important\"]').val(), 10); 
        var columnId = li.data('id');
        $.post(ajaxurl, {
            action: 'edit_column_label',
            column_id: columnId,
            new_label: newLabel,
            important: important 
        }, function(response) {
            if (response.success) {
                label.text(newLabel);
                li.data('important', important); 
                li.find('.save-column').text('Редагувати').removeClass('save-column').addClass('edit-column');
            } else {
                alert('Помилка при оновленні назви колонки: ' + (response.data || 'Невідома помилка'));
            }
        });
    });

    $('#sortable-columns').sortable({
        update: function(event, ui) {
            var order = $(this).sortable('toArray', {attribute: 'data-id'});
            $.post(ajaxurl, {
                action: 'update_columns_order',
                order: order
            }, function(response) {
                if (!response.success) {
                    alert('Помилка при оновленні порядку колонок: ' + (response.data || 'Невідома помилка'));
                }
            });
        }
    });
});</script>";

    }
}
add_action('wp_ajax_edit_column_label', 'edit_column_label_callback');


function edit_column_label_callback() {
    global $wpdb;
    $columns_table = $wpdb->prefix . 'coin_columns';

    $column_id = isset($_POST['column_id']) ? intval($_POST['column_id']) : 0;
    $new_label = isset($_POST['new_label']) ? sanitize_text_field($_POST['new_label']) : '';
    $important = isset($_POST['important']) ? intval($_POST['important']) : 0;

    if (empty($column_id) || empty($new_label)) {
        wp_send_json_error('Недопустимые данные: ID колонки или новое название отсутствуют');
        return;
    }

    $result = $wpdb->update(
        $columns_table,
        array(
            'column_label' => $new_label,
            'important' => $important
        ),
        array('id' => $column_id),
        array('%s', '%d'), 
        array('%d')
    );

    if ($result !== false) {
        wp_send_json_success(array('new_label' => $new_label));
    } else {
        $last_error = $wpdb->last_error;
        wp_send_json_error("Ошибка при обновлении базы данных: $last_error");
    }
}


function get_dynamic_columns() {
    global $wpdb;
    $columns_table = $wpdb->prefix . 'coin_columns';
    return $wpdb->get_results("SELECT * FROM $columns_table ORDER BY `order`");
}
function handle_plugin_settings() {
    global $wpdb;
    $table_name = 'plugin_settings';

    if (isset($_POST['save_settings'])) {
        $proxy_url = sanitize_text_field($_POST['proxy_url']);
        $button_style = sanitize_textarea_field($_POST['button_style']);
        $button_name = sanitize_text_field($_POST['button_name']);
        $update_frequency = sanitize_text_field($_POST['update_frequency']);

        $wpdb->query($wpdb->prepare(
    "UPDATE $table_name 
    SET 
        proxy_url = %s,
        button_style = %s,
        button_name = %s,
        update_frequency = %d
    WHERE id = (SELECT id FROM (SELECT id FROM $table_name ORDER BY id ASC LIMIT 1) AS t)",
    $proxy_url,
    $button_style,
    $button_name,
    $update_frequency
));


        echo '<div class="updated"><p>Налаштування збережено.</p></div>';
    }

    $settings = $wpdb->get_row("SELECT * FROM $table_name LIMIT 1");

    return $settings;
}

function create_columns_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'coin_columns';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        column_name varchar(255) NOT NULL,
        column_label varchar(255) NOT NULL,
        `order` int(11) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    
    $initial_columns = array(
        array('name', 'Название'),
        array('weight', 'Вес'),
        array('seller', 'Продавец'),
        array('sell_price', 'Цена продажи'),
        array('buy_price', 'Цена покупки'),
        array('availability', 'Доступность'),
        array('spread', 'Спред')
    );

    foreach ($initial_columns as $index => $column) {
        $wpdb->insert($table_name, array(
            'column_name' => $column[0],
            'column_label' => $column[1],
            'order' => $index
        ));
    }
}


register_activation_hook(__FILE__, 'create_columns_table');
add_action('wp_ajax_update_coin', 'update_coin_ajax');

function update_coin_ajax() {
    global $wpdb;
    $table_name = $wpdb->prefix . "coins";
    
    $id = intval($_POST['id']);
    $data = array();
    
    foreach ($_POST as $key => $value) {
        if ($key !== 'action' && $key !== 'id') {
            $data[$key] = sanitize_text_field($value);
        }
    }

    $availability_column = $wpdb->get_row("SELECT column_name FROM {$wpdb->prefix}coin_columns WHERE additionally = 'availability'");
    if ($availability_column && isset($data['sell_price']) && !empty($data['sell_price']) && $data['sell_price'] !== '-') {
        $data[$availability_column->column_name] = '1';
    }

    $result = $wpdb->update($table_name, $data, array('id' => $id));
    wp_send_json_success($result);
}

function display_parser_logs() {
    $logs = get_option('coin_parser_last_run_logs');
    if ($logs) {
        echo '<div class="parser-logs">';
        echo '<h3>Логи последнего парсинга</h3>';
        echo '<p>Обработано: ' . $logs['processed'] . '</p>';
        echo '<p>Обновлено: ' . $logs['updated'] . '</p>';
        echo '<p>Ошибок: ' . $logs['failed'] . '</p>';
        
        echo '<div class="log-details" style="max-height: 300px; overflow-y: auto;">';
        foreach ($logs['logs'] as $log) {
            $class = $log['status'] === 'success' ? 'notice-success' : 'notice-error';
            echo '<div class="log-entry ' . $class . '" style="margin: 5px 0; padding: 5px;">';
            echo 'ID: ' . esc_html($log['coin_id']) . ' | ';
            echo 'Status: ' . esc_html($log['status']) . ' | ';
            echo 'Time: ' . esc_html($log['time']) . ' | ';
            echo 'Message: ' . esc_html($log['message']);
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
    }
}

function display_parsing_progress() {
    $total_coins = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}coins");
    $current_progress = get_option('coin_parsing_progress', 0);
    
    echo '<div class="parsing-progress">';
    echo '<p>Progress: ' . $current_progress . ' / ' . $total_coins . ' coins</p>';
    echo '<div class="progress-bar" style="width: ' . ($current_progress/$total_coins*100) . '%"></div>';
    echo '</div>';
}

add_action('wp_ajax_parse_coins_batch', 'parse_coins_batch_handler');
function parse_coins_batch_handler() {
    $parser = new Coin_Price_Parser('', '');
    $results = $parser->reparse_all_coins();
    wp_send_json_success($results);
}