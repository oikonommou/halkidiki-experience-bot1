<?php
/**
 * Plugin Name: Halkidiki Experience AI Planner
 * Description: Safe AI trip planner for Halkidiki Experience.
 * Version: 1.0.0
 * Author: Halkidiki Experience
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * -------------------------------------------------------
 * 1. SETTINGS
 * -------------------------------------------------------
 */

if (!defined('HALKIDIKI_DEEPSEEK_API_KEY')) {
    $halkidiki_deepseek_api_key = getenv('HALKIDIKI_DEEPSEEK_API_KEY');
    define('HALKIDIKI_DEEPSEEK_API_KEY', $halkidiki_deepseek_api_key ? $halkidiki_deepseek_api_key : '');
}

if (!defined('HALKIDIKI_AI_PLUGIN_DIR')) {
    define('HALKIDIKI_AI_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (!defined('HALKIDIKI_AI_PLUGIN_URL')) {
    define('HALKIDIKI_AI_PLUGIN_URL', plugin_dir_url(__FILE__));
}

/**
analytics
**/
register_activation_hook(__FILE__, 'halkidiki_ai_create_logs_table');

function halkidiki_ai_create_logs_table() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'halkidiki_ai_logs';
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        user_message TEXT NOT NULL,
        detected_region VARCHAR(255) DEFAULT '',
        detected_intent VARCHAR(255) DEFAULT '',
        shown_businesses LONGTEXT NULL,
        PRIMARY KEY (id)
    ) {$charset_collate};";

    dbDelta($sql);
}

/**
 * -------------------------------------------------------
 * 2. LOAD DATASET
 * -------------------------------------------------------
 */

function halkidiki_ai_get_dataset() {
    $file = HALKIDIKI_AI_PLUGIN_DIR . 'data/halkidiki-places.json';

    if (!file_exists($file)) {
        return [
            'services' => [],
            'about_the_area' => [],
            'events' => []
        ];
    }

    $json = file_get_contents($file);
    $data = json_decode($json, true);

    if (!is_array($data)) {
        return [
            'services' => [],
            'about_the_area' => [],
            'events' => []
        ];
    }

    return $data;
}

/**
 * -------------------------------------------------------
 * 3. SIMPLE HELPERS
 * -------------------------------------------------------
 */

function halkidiki_ai_normalize_text($text) {
    $text = wp_strip_all_tags($text);
    $text = trim($text);
    $text = mb_strtolower($text, 'UTF-8');
    $from = ['ά','έ','ή','ί','ό','ύ','ώ','ϊ','ΐ','ϋ','ΰ','ς'];
    $to   = ['α','ε','η','ι','ο','υ','ω','ι','ι','υ','υ','σ'];
    return str_replace($from, $to, $text);
}

function halkidiki_ai_detect_region_canonical($message, $regions_map) {
    $normalized = halkidiki_ai_normalize_text(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $message));
    $alias_map = [
        'Πευκοχώρι' => ['πευκοχωρι','pefkochori','pefkohori','pefko chori','pefkoxori','peukochori','pefkoχωρι'],
        'Άφυτος' => ['αφυτο','αφυτος','afytos','afitos','afithos','afytos halkidiki'],
        'Καλλιθέα' => ['καλλιθεα','kallithea','kalithea'],
        'Χανιώτη' => ['χανιωτη','hanioti','chanioti','xanioti'],
        'Πολύχρονο' => ['πολυχρονο','polychrono','polichrono'],
        'Κρυοπηγή' => ['κρυοπηγη','kriopigi','kryopigi'],
        'Παλιούρι' => ['παλιουρι','paliouri','palioyri'],
        'Νέα Φώκεα' => ['νεα φωκεα','νεα φωκαια','nea fokea','nea fokaia'],
        'Νέα Ποτίδαια' => ['νεα ποτιδαια','nea potidaia'],
        'Σίβηρη' => ['σιβηρη','siviri'],
        'Σκάλα Φούρκας' => ['σκαλα φουρκασ','skala fourkas'],
        'Φούρκα' => ['φουρκα','fourka'],
        'Ποσείδι' => ['ποσειδι','poseidi'],
        'Καλάνδρα' => ['καλανδρα','kalandra'],
        'Αγία Παρασκευή' => ['αγια παρασκευη','agia paraskevi'],
        'Λουτρά' => ['λουτρα','loutra'],
        'Νέα Σκιώνη' => ['νεα σκιωνη','nea skioni'],
        'Μόλα Καλύβα' => ['μολα καλυβα','mola kalyva'],
        'Κασσάνδρα' => ['κασσανδρα','kassandra'],
    ];
    foreach ($alias_map as $canonical => $aliases) {
        foreach ($aliases as $alias) {
            if (strpos($normalized, halkidiki_ai_normalize_text($alias)) !== false) {
                foreach ($regions_map as $region) {
                    if (halkidiki_ai_normalize_text($region['name']) === halkidiki_ai_normalize_text($canonical)) return $region;
                }
                return ['name' => $canonical, 'term_id' => 0, 'slug' => '', 'norm' => halkidiki_ai_normalize_text($canonical)];
            }
        }
    }
    return halkidiki_ai_detect_region_from_message($message, $regions_map);
}

function halkidiki_ai_limit_history($history, $max = 6) {
    if (!is_array($history)) {
        return [];
    }

    $history = array_slice($history, -$max);

    $clean = [];
    foreach ($history as $item) {
        if (!is_array($item)) {
            continue;
        }

        $role = isset($item['role']) ? sanitize_text_field($item['role']) : '';
        $content = isset($item['content']) ? sanitize_textarea_field($item['content']) : '';

        if (!$role || !$content) {
            continue;
        }

        if (!in_array($role, ['user', 'assistant'], true)) {
            continue;
        }

        $clean[] = [
            'role' => $role,
            'content' => $content,
        ];
    }

    return $clean;
}

function halkidiki_ai_get_client_key() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return 'halkidiki_ai_rate_' . md5($ip);
}

function halkidiki_ai_is_rate_limited() {
    $key = halkidiki_ai_get_client_key();
    $last = get_transient($key);

    if ($last) {
        return true;
    }

    set_transient($key, time(), 6);
    return false;
}

/**
 * -------------------------------------------------------
 * CACHE HELPERS
 * -------------------------------------------------------
 */

function halkidiki_ai_make_cache_key($prefix, $value) {
    return 'halkidiki_ai_' . $prefix . '_' . md5(wp_json_encode($value));
}

function halkidiki_ai_get_cached($key) {
    return get_transient($key);
}

function halkidiki_ai_set_cached($key, $value, $seconds = 600) {
    set_transient($key, $value, $seconds);
}

/**
analytics
**/

function halkidiki_ai_cleanup_logs() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'halkidiki_ai_logs';

    // Κράτα μόνο τα τελευταία 90 ημερών
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$table_name} WHERE created_at < %s",
            date('Y-m-d H:i:s', strtotime('-90 days'))
        )
    );

    // Προαιρετικό hard cap: κράτα μόνο τις πιο πρόσφατες 10000 εγγραφές
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");

    if ($count > 10000) {
        $delete_ids = $wpdb->get_col("
            SELECT id
            FROM {$table_name}
            ORDER BY created_at DESC
            LIMIT 10000, 1000000
        ");

        if (!empty($delete_ids)) {
            $delete_ids = array_map('intval', $delete_ids);
            $ids_sql = implode(',', $delete_ids);
            $wpdb->query("DELETE FROM {$table_name} WHERE id IN ({$ids_sql})");
        }
    }
}

function halkidiki_ai_log_interaction($user_message, $business_data = []) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'halkidiki_ai_logs';

    $detected_region = '';
    $detected_intent = '';
    $shown_businesses = [];

    if (!empty($business_data['detected_region'])) {
        $detected_region = $business_data['detected_region'];
    }

    if (!empty($business_data['detected_intent'])) {
        $detected_intent = $business_data['detected_intent'];
    }

    if (!empty($business_data['businesses']) && is_array($business_data['businesses'])) {
        foreach ($business_data['businesses'] as $business) {
            if (!empty($business['name'])) {
                $shown_businesses[] = $business['name'];
            }
        }
    }

    $inserted = $wpdb->insert(
    $table_name,
    [
        'created_at'        => current_time('mysql'),
        'user_message'      => $user_message,
        'detected_region'   => $detected_region,
        'detected_intent'   => $detected_intent,
        'shown_businesses'  => wp_json_encode($shown_businesses),
    ],
    ['%s', '%s', '%s', '%s', '%s']
	);

	if ($inserted !== false) {
		halkidiki_ai_cleanup_logs();
	}
}

/**
 * -------------------------------------------------------
 * LISTEO BUSINESS DETECTION + FILTERING
 * -------------------------------------------------------
 */

function halkidiki_ai_get_listing_post_type() {
    $candidates = ['listing', 'job_listing'];

    foreach ($candidates as $candidate) {
        if (post_type_exists($candidate)) {
            return $candidate;
        }
    }

    return 'listing';
}

function halkidiki_ai_get_listing_taxonomies() {
    $post_type = halkidiki_ai_get_listing_post_type();
    $tax_objects = get_object_taxonomies($post_type, 'objects');

    $result = [
        'category' => '',
        'region'   => '',
        'feature'  => '',
    ];

    foreach ($tax_objects as $slug => $obj) {
        $label = strtolower($obj->label ?? '');
        $slug_l = strtolower($slug);

        if (
            !$result['category'] &&
            (
                strpos($slug_l, 'category') !== false ||
                strpos($slug_l, 'classified') !== false ||
                strpos($label, 'categor') !== false
            )
        ) {
            $result['category'] = $slug;
            continue;
        }

        if (
            !$result['region'] &&
            (
                strpos($slug_l, 'region') !== false ||
                strpos($slug_l, 'location') !== false ||
                strpos($label, 'region') !== false ||
                strpos($label, 'location') !== false
            )
        ) {
            $result['region'] = $slug;
            continue;
        }

        if (
            !$result['feature'] &&
            (
                strpos($slug_l, 'feature') !== false ||
                strpos($label, 'feature') !== false
            )
        ) {
            $result['feature'] = $slug;
            continue;
        }
    }

    return $result;
}

function halkidiki_ai_get_taxonomy_terms_map($taxonomy) {
    if (empty($taxonomy) || !taxonomy_exists($taxonomy)) {
        return [];
    }

    $terms = get_terms([
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
    ]);

    if (is_wp_error($terms) || empty($terms)) {
        return [];
    }

    $map = [];

    foreach ($terms as $term) {
        $map[] = [
            'term_id' => (int) $term->term_id,
            'name'    => $term->name,
            'slug'    => $term->slug,
            'norm'    => halkidiki_ai_normalize_text($term->name . ' ' . $term->slug),
        ];
    }

    return $map;
}

function halkidiki_ai_detect_region_from_message($message, $regions_map) {
    $normalized = halkidiki_ai_normalize_text($message);

    foreach ($regions_map as $region) {
        if (!empty($region['norm']) && strpos($normalized, $region['norm']) !== false) {
            return $region;
        }

        if (!empty($region['name']) && strpos($normalized, halkidiki_ai_normalize_text($region['name'])) !== false) {
            return $region;
        }
    }

    $aliases = [
        'αθως' => 'Άθως',
        'athos' => 'Άθως',
        'κασσανδρα' => 'Κασσάνδρα',
        'κασσάνδρα' => 'Κασσάνδρα',
        'kassandra' => 'Κασσάνδρα',
        'αγια παρασκευη' => 'Αγία Παρασκευή',
        'αγία παρασκευή' => 'Αγία Παρασκευή',
        'agia paraskevi' => 'Αγία Παρασκευή',
        'αφυτος' => 'Άφυτος',
        'άφυτος' => 'Άφυτος',
        'afytos' => 'Άφυτος',
        'kalandra' => 'Καλάνδρα',
        'καλανδρα' => 'Καλάνδρα',
        'καλάνδρα' => 'Καλάνδρα',
        'καλλιθεα' => 'Καλλιθέα',
        'καλλιθέα' => 'Καλλιθέα',
        'kallithea' => 'Καλλιθέα',
        'κασσανδρεια' => 'Κασσανδρεία',
        'κασσανδρεία' => 'Κασσανδρεία',
        'kassandreia' => 'Κασσανδρεία',
        'κρυοπηγη' => 'Κρυοπηγή',
        'κρυοπηγή' => 'Κρυοπηγή',
        'kriopigi' => 'Κρυοπηγή',
        'λουτρα' => 'Λουτρά',
        'λουτρά' => 'Λουτρά',
        'loutra' => 'Λουτρά',
        'μολα καλυβα' => 'Μόλα Καλύβα',
        'μόλα καλύβα' => 'Μόλα Καλύβα',
        'mola kalyva' => 'Μόλα Καλύβα',
        'νεα ποτιδαια' => 'Νέα Ποτίδαια',
        'νέα ποτίδαια' => 'Νέα Ποτίδαια',
        'nea potidaia' => 'Νέα Ποτίδαια',
        'νεα σκιονη' => 'Νέα Σκιώνη',
        'νέα σκιώνη' => 'Νέα Σκιώνη',
        'nea skioni' => 'Νέα Σκιώνη',
        'νεα φωκεα' => 'Νέα Φώκεα',
        'νεα φωκαια' => 'Νέα Φώκεα',
        'νέα φώκεα' => 'Νέα Φώκεα',
        'νέα φωκαία' => 'Νέα Φώκεα',
        'nea fokea' => 'Νέα Φώκεα',
        'palioyri' => 'Παλιούρι',
        'παλιουρι' => 'Παλιούρι',
        'παλιούρι' => 'Παλιούρι',
        'πευκοχωρι' => 'Πευκοχώρι',
        'πευκοχώρι' => 'Πευκοχώρι',
        'pefkochori' => 'Πευκοχώρι',
        'πολυχρονο' => 'Πολύχρονο',
        'πολύχρονο' => 'Πολύχρονο',
        'polychrono' => 'Πολύχρονο',
        'ποσειδι' => 'Ποσείδι',
        'ποσείδι' => 'Ποσείδι',
        'poseidi' => 'Ποσείδι',
        'σιβηρη' => 'Σίβηρη',
        'σίβηρη' => 'Σίβηρη',
        'siviri' => 'Σίβηρη',
        'σκαλα φουρκας' => 'Σκάλα Φούρκας',
        'σκάλα φούρκας' => 'Σκάλα Φούρκας',
        'skala fourkas' => 'Σκάλα Φούρκας',
        'χανιωτη' => 'Χανιώτη',
        'χανιώτη' => 'Χανιώτη',
        'hanioti' => 'Χανιώτη',
        'σιθωνια' => 'Σιθωνία',
        'σιθωνία' => 'Σιθωνία',
        'sithonia' => 'Σιθωνία',
    ];

    foreach ($aliases as $needle => $target_name) {
        if (strpos($normalized, $needle) !== false) {
            foreach ($regions_map as $region) {
                if (halkidiki_ai_normalize_text($region['name']) === halkidiki_ai_normalize_text($target_name)) {
                    return $region;
                }
            }
        }
    }

    return null;
}

function halkidiki_ai_get_region_clusters() {
    // Small, explicit adjacency map for Kassandra.
    // Important: these are NOT broad clusters. They are local fallback routes.
    // The bot must always use the exact requested village first and only then these nearby alternatives.
    return [
        'Κασσάνδρα' => [
            'Κασσάνδρα',
            'Νέα Ποτίδαια',
            'Νέα Φώκεα',
            'Σάνη',
            'Άφυτος',
            'Καλλιθέα',
            'Κρυοπηγή',
            'Πολύχρονο',
            'Χανιώτη',
            'Πευκοχώρι',
            'Παλιούρι',
            'Ποσείδι',
            'Σίβηρη',
            'Σκάλα Φούρκας',
            'Φούρκα',
            'Καλάνδρα',
            'Αγία Παρασκευή',
            'Λουτρά',
            'Νέα Σκιώνη',
            'Μόλα Καλύβα',
            'Κασσανδρεία'
        ],

        // North / entry side
        'Νέα Ποτίδαια' => ['Νέα Ποτίδαια', 'Νέα Φώκεα', 'Σάνη', 'Κασσανδρεία'],
        'Νέα Φώκεα'    => ['Νέα Φώκεα', 'Νέα Ποτίδαια', 'Σάνη', 'Άφυτος'],
        'Σάνη'         => ['Σάνη', 'Νέα Φώκεα', 'Νέα Ποτίδαια', 'Σίβηρη', 'Κασσανδρεία'],

        // East coast
        'Άφυτος'       => ['Άφυτος', 'Καλλιθέα', 'Κρυοπηγή', 'Νέα Φώκεα'],
        'Καλλιθέα'     => ['Καλλιθέα', 'Άφυτος', 'Κρυοπηγή', 'Πολύχρονο'],
        'Κρυοπηγή'     => ['Κρυοπηγή', 'Καλλιθέα', 'Πολύχρονο', 'Άφυτος', 'Χανιώτη'],
        'Πολύχρονο'    => ['Πολύχρονο', 'Κρυοπηγή', 'Χανιώτη', 'Καλλιθέα', 'Πευκοχώρι'],
        'Χανιώτη'      => ['Χανιώτη', 'Πολύχρονο', 'Πευκοχώρι', 'Κρυοπηγή'],
        'Πευκοχώρι'    => ['Πευκοχώρι', 'Χανιώτη', 'Παλιούρι', 'Πολύχρονο'],
        'Παλιούρι'     => ['Παλιούρι', 'Πευκοχώρι', 'Αγία Παρασκευή', 'Λουτρά', 'Χανιώτη'],

        // South / Loutra side
        'Αγία Παρασκευή' => ['Αγία Παρασκευή', 'Λουτρά', 'Παλιούρι', 'Νέα Σκιώνη'],
        'Λουτρά'         => ['Λουτρά', 'Αγία Παρασκευή', 'Παλιούρι', 'Νέα Σκιώνη'],
        'Νέα Σκιώνη'     => ['Νέα Σκιώνη', 'Αγία Παρασκευή', 'Λουτρά', 'Μόλα Καλύβα'],
        'Μόλα Καλύβα'    => ['Μόλα Καλύβα', 'Νέα Σκιώνη', 'Καλάνδρα', 'Ποσείδι'],

        // West coast
        'Καλάνδρα'      => ['Καλάνδρα', 'Ποσείδι', 'Μόλα Καλύβα', 'Σκάλα Φούρκας'],
        'Ποσείδι'       => ['Ποσείδι', 'Καλάνδρα', 'Σκάλα Φούρκας', 'Σίβηρη'],
        'Σκάλα Φούρκας' => ['Σκάλα Φούρκας', 'Φούρκα', 'Σίβηρη', 'Ποσείδι', 'Καλάνδρα'],
        'Φούρκα'        => ['Φούρκα', 'Σκάλα Φούρκας', 'Σίβηρη', 'Καλάνδρα'],
        'Σίβηρη'        => ['Σίβηρη', 'Σκάλα Φούρκας', 'Σάνη', 'Ποσείδι', 'Κασσανδρεία'],

        // Central
        'Κασσανδρεία'   => ['Κασσανδρεία', 'Σίβηρη', 'Σάνη', 'Νέα Φώκεα', 'Καλλιθέα'],
    ];
}

function halkidiki_ai_get_nearby_region_names($region_name) {
    $clusters = halkidiki_ai_get_region_clusters();
    $requested_norm = halkidiki_ai_normalize_text($region_name);

    // Prefer the exact village cluster first.
    // Without this, every village inside the broad “Κασσάνδρα” cluster can return all Kassandra villages,
    // causing far-away suggestions such as Αγία Παρασκευή or Πευκοχώρι for Καλλιθέα.
    foreach ($clusters as $cluster_name => $items) {
        if (halkidiki_ai_normalize_text($cluster_name) === $requested_norm) {
            return array_values(array_unique($items));
        }
    }

    // Fallback: use only small local clusters. Never use the broad Kassandra cluster as fallback
    // unless the user explicitly asked for Kassandra.
    foreach ($clusters as $cluster_name => $items) {
        if (
            halkidiki_ai_normalize_text($cluster_name) !== halkidiki_ai_normalize_text('Κασσάνδρα') &&
            in_array($region_name, $items, true)
        ) {
            return array_values(array_unique($items));
        }
    }

    if ($requested_norm === halkidiki_ai_normalize_text('Κασσάνδρα') && !empty($clusters['Κασσάνδρα'])) {
        return array_values(array_unique($clusters['Κασσάνδρα']));
    }

    return [$region_name];
}

function halkidiki_ai_get_category_families() {
    return [

        'food' => [
            'Fast Food',
            'Pizza & Pasta',
            'Snack Bar',
            'Εστιατόρια - Ταβέρνες',
            'Εστιατόρια – Ταβέρνες'
        ],

        'dessert' => [
            'Άρτος & Γλυκό',
            'Παγωτό',
            'Cafe-Snacks',
            'Brunch',
            'Snack Bar'
        ],

        'coffee' => [
            'Brunch',
            'Cafe-Snacks',
            'Snack Bar',
            'Cafe & Coctail',
            'Cafe & Cocktail'
        ],

        'drink' => [
            'Cafe & Coctail',
            'Cafe & Cocktail',
            'Beach Bars',
            'Clubs',
            'Bars'
        ],

        'icecream' => [
            'Παγωτό',
            'Άρτος & Γλυκό',
            'Cafe-Snacks'
        ],

        'seafood' => [
            'Εστιατόρια - Ταβέρνες',
            'Εστιατόρια – Ταβέρνες'
        ],

        'activities' => [
            'Daily Cruises',
            'Gyms',
            'Kart & Go',
            'Kids Club',
            'Physiotherapy & Massage',
            'Rent a Boat-Yacht',
            'Rent a Car-Moto-Bike',
            'Water Sports'
        ],

        'stay' => [
            'Apartments',
            'Hotels',
            'Luxury Suites',
            'Studios',
            'studios',
            'Villas'
        ],

        'shopping' => [
            'Beauty & Hair',
            'Cannabis Shop',
            'Real Estate',
            'Αξεσουάρ - Σουβενίρ',
            'Ένδυση - Υπόδυση',
            'Κοσμήματα',
            'Οπτικά'
        ],
    ];
}

function halkidiki_ai_detect_business_intent($message) {
    $normalized = halkidiki_ai_normalize_text($message);
    $fam = halkidiki_ai_get_category_families();

    $intent = [
        'category_keywords' => [],
        'feature_keywords'  => [],
        'type'              => '',
    ];

    if (
        strpos($normalized, 'παγωτ') !== false ||
        strpos($normalized, 'ice cream') !== false ||
        strpos($normalized, 'icecream') !== false ||
        strpos($normalized, 'gelato') !== false
    ) {
        $intent['type'] = 'icecream';
        $intent['category_keywords'] = $fam['icecream'];
        return $intent;
    }

    if (
        strpos($normalized, 'γλυκ') !== false ||
        strpos($normalized, 'κρεπα') !== false ||
        strpos($normalized, 'παγωτο') !== false ||
        strpos($normalized, 'dessert') !== false ||
        strpos($normalized, 'ice cream') !== false ||
        strpos($normalized, 'crepe') !== false ||
        strpos($normalized, 'sweet') !== false
    ) {
        $intent['type'] = 'dessert';
        $intent['category_keywords'] = $fam['dessert'];
        return $intent;
    }

    if (
        strpos($normalized, 'brunch') !== false ||
        strpos($normalized, 'breakfast') !== false ||
        strpos($normalized, 'πρωιν') !== false
    ) {
        $intent['type'] = 'brunch';
        $intent['category_keywords'] = ['Brunch', 'Cafe-Snacks'];
        return $intent;
    }

    if (
        strpos($normalized, 'burger') !== false ||
        strpos($normalized, 'μπεργκ') !== false
    ) {
        $intent['type'] = 'burger';
        $intent['category_keywords'] = ['Fast Food', 'Snack Bar', 'Εστιατόρια – Ταβέρνες'];
        return $intent;
    }

    if (
        strpos($normalized, 'pizza') !== false ||
        strpos($normalized, 'πιτσ') !== false
    ) {
        $intent['type'] = 'pizza';
        $intent['category_keywords'] = ['Pizza & Pasta', 'Fast Food', 'Εστιατόρια – Ταβέρνες'];
        return $intent;
    }

    if (
        strpos($normalized, 'ψαρ') !== false ||
        strpos($normalized, 'θαλασσιν') !== false ||
        strpos($normalized, 'seafood') !== false ||
        strpos($normalized, 'fish') !== false
    ) {
        $intent['type'] = 'seafood';
        $intent['category_keywords'] = $fam['seafood'];
        return $intent;
    }

    if (
        strpos($normalized, 'φαω') !== false ||
        strpos($normalized, 'εστιατ') !== false ||
        strpos($normalized, 'ταβερν') !== false ||
        strpos($normalized, 'φαγητ') !== false ||
        strpos($normalized, 'φαΐ') !== false ||
        strpos($normalized, 'food') !== false ||
        strpos($normalized, 'restaurant') !== false
    ) {
        $intent['type'] = 'food';
        $intent['category_keywords'] = $fam['food'];
        return $intent;
    }

    if (
		strpos($normalized, 'ποτο το βραδυ') !== false ||
		strpos($normalized, 'ποτό το βράδυ') !== false ||
		strpos($normalized, 'βραδυνο ποτο') !== false ||
		strpos($normalized, 'βραδινό ποτό') !== false ||
		strpos($normalized, 'nightlife') !== false ||
		strpos($normalized, 'cocktail') !== false ||
		strpos($normalized, 'bar') !== false ||
		strpos($normalized, 'bars') !== false ||
		strpos($normalized, 'club') !== false
	) {
		$intent['type'] = 'nightlife';
		$intent['category_keywords'] = ['Cafe & Coctail', 'Cafe & Cocktail', 'Beach Bars', 'Clubs'];
		$intent['feature_keywords'] = ['Cafe & Coctail', 'Cafe & Cocktail', 'Beach Bars', 'Clubs'];
		return $intent;
	}

	if (
        strpos($normalized, 'μπαρ') !== false ||
        strpos($normalized, 'bar') !== false ||
        strpos($normalized, 'ποτο') !== false ||
        strpos($normalized, 'ποτα') !== false ||
        strpos($normalized, 'κοκτειλ') !== false ||
        strpos($normalized, 'ποτό') !== false ||
        strpos($normalized, 'cocktail') !== false
    ) {
        $intent['type'] = 'drink';
        $intent['category_keywords'] = $fam['drink'];
        $intent['feature_keywords'] = ['Cafe & Coctail', 'Cafe & Cocktail', 'Beach Bars', 'Clubs'];
        return $intent;
    }

    if (
        strpos($normalized, 'club') !== false ||
        strpos($normalized, 'κλαμπ') !== false ||
        strpos($normalized, 'clubs') !== false
    ) {
        $intent['type'] = 'club';
        $intent['category_keywords'] = ['Clubs', 'Beach Bars', 'Cafe & Coctail', 'Cafe & Cocktail'];
        $intent['feature_keywords'] = ['Clubs', 'Beach Bars'];
        return $intent;
    }

    if (
        strpos($normalized, 'καφε') !== false ||
        strpos($normalized, 'καφεδακι') !== false ||
        strpos($normalized, 'καφέ') !== false ||
        strpos($normalized, 'cafe') !== false ||
        strpos($normalized, 'coffee') !== false
    ) {
        $intent['type'] = 'coffee';
        $intent['category_keywords'] = $fam['coffee'];
        $intent['feature_keywords'] = ['Cafe & Coctail', 'Cafe & Cocktail', 'Beach Bars'];
        return $intent;
    }

    if (
        strpos($normalized, 'ξενοδοχ') !== false ||
        strpos($normalized, 'hotel') !== false ||
        strpos($normalized, 'apartments') !== false ||
        strpos($normalized, 'villa') !== false ||
        strpos($normalized, 'διαμον') !== false
    ) {
        $intent['type'] = 'stay';
        $intent['category_keywords'] = $fam['stay'];
        return $intent;
    }

    if (
        strpos($normalized, 'κρουαζ') !== false ||
        strpos($normalized, 'cruise') !== false
    ) {
        $intent['type'] = 'cruise';
        $intent['category_keywords'] = ['Daily Cruises'];
        return $intent;
    }

    if (
        strpos($normalized, 'rent a car') !== false ||
        strpos($normalized, 'αυτοκιν') !== false ||
        strpos($normalized, 'μηχαν') !== false ||
        strpos($normalized, 'bike') !== false ||
        strpos($normalized, 'boat') !== false ||
        strpos($normalized, 'yacht') !== false
    ) {
        $intent['type'] = 'rent';
        $intent['category_keywords'] = ['Rent a Car-Moto-Bike', 'Rent a Boat-Yacht'];
        return $intent;
    }

    if (
        strpos($normalized, 'μασαζ') !== false ||
        strpos($normalized, 'massage') !== false ||
        strpos($normalized, 'physio') !== false
    ) {
        $intent['type'] = 'wellness';
        $intent['category_keywords'] = ['Physiotherapy & Massage'];
        return $intent;
    }

    if (
        strpos($normalized, 'παιδ') !== false ||
        strpos($normalized, 'kids') !== false ||
        strpos($normalized, 'family') !== false ||
        strpos($normalized, 'οικογεν') !== false
    ) {
        $intent['type'] = 'family';
        $intent['category_keywords'] = ['Kids Club', 'Daily Cruises', 'Horse Riding', 'Kart & Go'];
        return $intent;
    }

    if (
        strpos($normalized, 'βολτα') !== false ||
        strpos($normalized, 'βόλτα') !== false ||
        strpos($normalized, 'δραστηριοτ') !== false ||
        strpos($normalized, 'activity') !== false ||
        strpos($normalized, 'water sports') !== false ||
        strpos($normalized, 'θαλασσια σπορ') !== false ||
        strpos($normalized, 'θαλάσσια σπορ') !== false
    ) {
        $intent['type'] = 'activities';
        $intent['category_keywords'] = $fam['activities'];
        return $intent;
    }

    if (
        strpos($normalized, 'αγορα') !== false ||
        strpos($normalized, 'αγορά') !== false ||
        strpos($normalized, 'shopping') !== false ||
        strpos($normalized, 'κοσμημ') !== false ||
        strpos($normalized, 'ρούχ') !== false ||
        strpos($normalized, 'souvenir') !== false ||
        strpos($normalized, 'σουβενιρ') !== false ||
        strpos($normalized, 'σουβενίρ') !== false
    ) {
        $intent['type'] = 'shopping';
        $intent['category_keywords'] = $fam['shopping'];
        return $intent;
    }

    return $intent;
}
function halkidiki_ai_find_matching_term_ids($terms_map, $keywords) {
    $matches = [];

    if (empty($terms_map) || empty($keywords)) {
        return $matches;
    }

    foreach ($terms_map as $term) {
        foreach ($keywords as $keyword) {
            $kw = halkidiki_ai_normalize_text($keyword);

            if (
                strpos($term['norm'], $kw) !== false ||
                strpos($kw, $term['norm']) !== false
            ) {
                $matches[] = (int) $term['term_id'];
                break;
            }
        }
    }

    return array_values(array_unique($matches));
}

function halkidiki_ai_filter_businesses_by_intent($items, $intent) {
    if (empty($items) || !is_array($items)) {
        return [];
    }

    if (empty($intent['type'])) {
        return $items;
    }

    $keywords = [];

    if (!empty($intent['category_keywords']) && is_array($intent['category_keywords'])) {
        $keywords = array_merge($keywords, $intent['category_keywords']);
    }

    if (!empty($intent['feature_keywords']) && is_array($intent['feature_keywords'])) {
        $keywords = array_merge($keywords, $intent['feature_keywords']);
    }

    if (empty($keywords)) {
        return $items;
    }

    $filtered = [];

    foreach ($items as $item) {
        $haystack_parts = [];

        if (!empty($item['categories']) && is_array($item['categories'])) {
            $haystack_parts = array_merge($haystack_parts, $item['categories']);
        }

        if (!empty($item['features']) && is_array($item['features'])) {
            $haystack_parts = array_merge($haystack_parts, $item['features']);
        }

        $haystack = halkidiki_ai_normalize_text(implode(' ', $haystack_parts));

        if ($haystack === '') {
            continue;
        }

        foreach ($keywords as $keyword) {
            $keyword_norm = halkidiki_ai_normalize_text($keyword);

            if ($keyword_norm !== '' && strpos($haystack, $keyword_norm) !== false) {
                $filtered[] = $item;
                break;
            }
        }
    }

    return array_values($filtered);
}

function halkidiki_ai_debug_log($payload) {
    if (!defined('HALKIDIKI_AI_DEBUG') || !HALKIDIKI_AI_DEBUG) {
        return;
    }
    error_log('HALKIDIKI_AI_DEBUG ' . wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function halkidiki_ai_is_followup_message($message) {
    $n = halkidiki_ai_normalize_text($message);
    $tokens = ['αλλα', 'άλλα', 'περισσοτερα', 'περισσότερα', 'δειξε μου αλλα', 'εχει αλλα', 'more', 'show more', 'ναι', 'yes', 'και κοκτειλ', 'και καφε', 'και φαγητο'];
    foreach ($tokens as $t) {
        if (strpos($n, halkidiki_ai_normalize_text($t)) !== false) return true;
    }
    return false;
}

function halkidiki_ai_resolve_business_context($message, $history = [], $last_assistant_reply = '') {
    $resolved = [
        'selected_region' => '',
        'selected_intent' => '',
        'is_business_request' => false,
        'is_more_request' => false,
        'is_yes_nearby_request' => false,
        'allow_nearby' => false,
        'page' => 1,
        'offset' => 0,
        'base_query_message' => $message,
        'needs_clarification' => false,
    ];

    $n = halkidiki_ai_normalize_text($message);
    $is_more = (strpos($n, 'αλλα') !== false || strpos($n, 'άλλα') !== false || strpos($n, 'περισσοτερα') !== false || strpos($n, 'περισσότερα') !== false || strpos($n, 'show more') !== false || trim($n) === 'more');
    $is_yes = in_array(trim($n), ['ναι', 'yes'], true);

    $detected_intent = halkidiki_ai_detect_business_intent($message);
    $is_current_business = halkidiki_ai_is_business_request($message, ['detected_intent' => $detected_intent['type'] ?? '']);
    $taxes = halkidiki_ai_get_listing_taxonomies();
    $region_map = halkidiki_ai_get_taxonomy_terms_map($taxes['region']);
    $pending = (is_array($history) && isset($history['_pending_context']) && is_array($history['_pending_context'])) ? $history['_pending_context'] : [];
    $detected_region = halkidiki_ai_detect_region_canonical($message, $region_map);

    $resolved['selected_region'] = $detected_region['name'] ?? '';
    $resolved['selected_intent'] = $detected_intent['type'] ?? '';
    $resolved['is_more_request'] = $is_more;
    $assistant_norm = halkidiki_ai_normalize_text((string) $last_assistant_reply);
    $resolved['is_yes_nearby_request'] = $is_yes && (strpos($assistant_norm, 'κοντιν') !== false || strpos($assistant_norm, 'nearby') !== false);
    $resolved['is_business_request'] = $is_current_business || $is_more || $is_yes || !empty($resolved['selected_region']) || !empty($resolved['selected_intent']);
    $resolved['allow_nearby'] = true;

    if ($is_more && is_array($history)) {
        $anchor = null;
        $more_count = 1;
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') !== 'user') continue;
            $m = trim((string)($history[$i]['content'] ?? ''));
            $mn = halkidiki_ai_normalize_text($m);
            if (in_array($mn, ['άλλα', 'αλλα', 'περισσότερα', 'περισσοτερα', 'more', 'show more'], true)) {
                $more_count++;
                continue;
            }
            $anchor = $m;
            break;
        }
        if ($anchor) {
            $base = halkidiki_ai_resolve_business_context($anchor, [], '');
            $resolved['selected_region'] = $base['selected_region'];
            $resolved['selected_intent'] = $base['selected_intent'];
            $resolved['page'] = $more_count;
            $resolved['needs_clarification'] = ($resolved['selected_region'] === '' || $resolved['selected_intent'] === '');
        } else {
            $resolved['needs_clarification'] = true;
        }
    } elseif ($resolved['selected_region'] === '' || $resolved['selected_intent'] === '') {
        if ($resolved['selected_region'] === '' && !empty($pending['pending_region'])) $resolved['selected_region'] = $pending['pending_region'];
        if ($resolved['selected_intent'] === '' && !empty($pending['pending_intent'])) $resolved['selected_intent'] = $pending['pending_intent'];
        $resolved['needs_clarification'] = ($resolved['selected_region'] === '' || $resolved['selected_intent'] === '');
    }
    if ($is_yes && !$resolved['is_yes_nearby_request']) $resolved['needs_clarification'] = true;
    if (!empty($resolved['selected_region']) && !empty($resolved['selected_intent'])) $resolved['needs_clarification'] = false;
    $resolved['offset'] = max(0, ($resolved['page'] - 1) * 6);

    return $resolved;
}

function halkidiki_ai_get_filtered_businesses($message, $context = null) {
    $post_type = halkidiki_ai_get_listing_post_type();
    $taxes = halkidiki_ai_get_listing_taxonomies();

    $business_cache_key = halkidiki_ai_make_cache_key('businesses_v10', ['message' => $message, 'context' => $context]);
    $cached_businesses = halkidiki_ai_get_cached($business_cache_key);

    if ($cached_businesses !== false && is_array($cached_businesses)) {
        return $cached_businesses;
    }

    $region_map = halkidiki_ai_get_taxonomy_terms_map($taxes['region']);
    $category_map = halkidiki_ai_get_taxonomy_terms_map($taxes['category']);
    $feature_map = halkidiki_ai_get_taxonomy_terms_map($taxes['feature']);

    $detected_region = halkidiki_ai_detect_region_canonical($message, $region_map);
    $intent = halkidiki_ai_detect_business_intent($message);
    if (is_array($context)) {
        if (!empty($context['selected_region'])) {
            foreach ($region_map as $region_item) {
                if (halkidiki_ai_normalize_text($region_item['name']) === halkidiki_ai_normalize_text($context['selected_region'])) {
                    $detected_region = $region_item;
                    break;
                }
            }
        }
        if (!empty($context['selected_intent'])) {
            $intent = ['type' => $context['selected_intent'], 'category_keywords' => [], 'feature_keywords' => []];
            $intent_from_type = halkidiki_ai_detect_business_intent($context['selected_intent']);
            if (!empty($intent_from_type['type'])) $intent = $intent_from_type;
        }
    }

    $category_term_ids = halkidiki_ai_find_matching_term_ids($category_map, $intent['category_keywords']);
    $feature_term_ids = halkidiki_ai_find_matching_term_ids($feature_map, $intent['feature_keywords']);

    $find_region_term_ids = function($region_names) use ($region_map) {
        $ids = [];

        foreach ($region_map as $region_item) {
            foreach ($region_names as $region_name) {
                if (halkidiki_ai_normalize_text($region_item['name']) === halkidiki_ai_normalize_text($region_name)) {
                    $ids[] = (int) $region_item['term_id'];
                }
            }
        }

        return array_values(array_unique($ids));
    };

    $build_tax_query = function($region_term_ids = []) use ($taxes, $category_term_ids, $feature_term_ids) {
        $tax_query = [];

        if (!empty($region_term_ids) && !empty($taxes['region'])) {
            $tax_query[] = [
                'taxonomy' => $taxes['region'],
                'field'    => 'term_id',
                'terms'    => $region_term_ids,
            ];
        }

        if (!empty($category_term_ids) && !empty($taxes['category'])) {
            $tax_query[] = [
                'taxonomy' => $taxes['category'],
                'field'    => 'term_id',
                'terms'    => $category_term_ids,
            ];
        }

        if (!empty($feature_term_ids) && !empty($taxes['feature'])) {
            $tax_query[] = [
                'taxonomy' => $taxes['feature'],
                'field'    => 'term_id',
                'terms'    => $feature_term_ids,
            ];
        }

        if (count($tax_query) > 1) {
            $tax_query['relation'] = 'AND';
        }

        return $tax_query;
    };

    $hydrate_posts = function($post_ids, $match_scope, $requested_region_name = '') use ($taxes) {
        $items = [];

        foreach ($post_ids as $post_id) {
            $title = get_the_title($post_id);
            $link = get_permalink($post_id);
			$description = get_the_excerpt($post_id);

if (empty($description)) {
    $description = get_post_field('post_content', $post_id);
}

$description = html_entity_decode(wp_strip_all_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$description = trim(preg_replace('/\s+/', ' ', $description));
$title_norm = halkidiki_ai_normalize_text($title);
$desc_norm = halkidiki_ai_normalize_text($description);
if ($title_norm !== '' && strpos($desc_norm, $title_norm) === 0) {
    $description = trim(preg_replace('/^' . preg_quote($title, '/') . '\s*[-–—:,.]?\s*/iu', '', $description));
}
$sentence = preg_split('/[.!;;]/u', $description);
$description = trim($sentence[0] ?? $description);
$description = wp_trim_words($description, 18, '...');

            $categories = [];
            if (!empty($taxes['category'])) {
                $terms = wp_get_post_terms($post_id, $taxes['category'], ['fields' => 'names']);
                if (!is_wp_error($terms) && !empty($terms)) {
                    $categories = $terms;
                }
            }

            $regions = [];
            if (!empty($taxes['region'])) {
                $terms = wp_get_post_terms($post_id, $taxes['region'], ['fields' => 'names']);
                if (!is_wp_error($terms) && !empty($terms)) {
                    $regions = $terms;
                }
            }

            $features = [];
            if (!empty($taxes['feature'])) {
                $terms = wp_get_post_terms($post_id, $taxes['feature'], ['fields' => 'names']);
                if (!is_wp_error($terms) && !empty($terms)) {
                    $features = $terms;
                }
            }

            $display_region = '';
            foreach ($regions as $region_name) {
                if ($requested_region_name && halkidiki_ai_normalize_text($region_name) === halkidiki_ai_normalize_text($requested_region_name)) {
                    $display_region = $region_name;
                    break;
                }
            }

            if ($display_region === '') {
                foreach ($regions as $region_name) {
                    if (halkidiki_ai_normalize_text($region_name) !== halkidiki_ai_normalize_text('Κασσάνδρα')) {
                        $display_region = $region_name;
                        break;
                    }
                }
            }

            if ($display_region === '' && !empty($regions[0])) {
                $display_region = $regions[0];
            }

            $items[] = [
                'name'           => $title,
                'link'           => $link,
                'categories'     => $categories,
                'regions'        => $regions,
                'features'       => $features,
                'match_scope'    => $match_scope,
                'display_region' => $display_region,
				'description'    => $description,
            ];
        }

        return $items;
    };

    $run_query = function($region_term_ids = [], $limit = 12, $exclude_ids = []) use ($post_type, $build_tax_query) {
        $args = [
            'post_type'              => $post_type,
            'post_status'            => 'publish',
            'posts_per_page'         => $limit,
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'orderby'                => 'title',
            'order'                  => 'ASC',
            'fields'                 => 'ids',
            'post__not_in'           => array_map('intval', $exclude_ids),
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];

        $tax_query = $build_tax_query($region_term_ids);
        if (!empty($tax_query)) {
            $args['tax_query'] = $tax_query;
        }

        $query = new WP_Query($args);

        if (empty($query->posts)) {
            return [];
        }

        return array_map('intval', $query->posts);
    };

    $requested_region_name = $detected_region ? $detected_region['name'] : '';
    $results = [];
    $exact_count = 0;
    $nearby_count = 0;
    $used_post_ids = [];
    $nearby_region_names = [];

    $debug = [];
    if (!empty($requested_region_name) && !empty($taxes['region'])) {
        $exact_term_ids = $find_region_term_ids([$requested_region_name]);
        $exact_ids = !empty($exact_term_ids) ? $run_query($exact_term_ids, 200, []) : [];

$exact_items = $hydrate_posts($exact_ids, 'exact', $requested_region_name);
$debug['exact_candidates_before_filter'] = $exact_items;
$exact_items = halkidiki_ai_filter_businesses_by_intent($exact_items, $intent);
$debug['exact_candidates_after_filter'] = $exact_items;
$before_names = array_map(function($i){ return $i['name'] ?? ''; }, $debug['exact_candidates_before_filter']);
$after_names = array_map(function($i){ return $i['name'] ?? ''; }, $debug['exact_candidates_after_filter']);
$debug['exact_removed_by_intent_filter'] = array_values(array_diff($before_names, $after_names));
$offset = is_array($context) ? (int) ($context['offset'] ?? 0) : 0;
$exact_total = count($exact_items);
$exact_items = array_slice($exact_items, $offset, 6);

$results = array_merge($results, $exact_items);
$used_post_ids = array_merge($used_post_ids, $exact_ids);
$exact_count = count($exact_items);

        $nearby_region_names = halkidiki_ai_get_nearby_region_names($requested_region_name);
        $nearby_region_names = array_values(array_filter($nearby_region_names, function($name) use ($requested_region_name) {
            return halkidiki_ai_normalize_text($name) !== halkidiki_ai_normalize_text($requested_region_name)
                && halkidiki_ai_normalize_text($name) !== halkidiki_ai_normalize_text('Κασσάνδρα')
                && halkidiki_ai_normalize_text($name) !== halkidiki_ai_normalize_text('Χαλκιδική');
        }));

        $allow_nearby = is_array($context) ? !empty($context['allow_nearby']) : true;
        if ($exact_total === 0 && $allow_nearby && !empty($nearby_region_names)) {
            $nearby_term_ids = $find_region_term_ids($nearby_region_names);
            $nearby_ids = !empty($nearby_term_ids) ? $run_query($nearby_term_ids, 200, $used_post_ids) : [];

$nearby_items = $hydrate_posts($nearby_ids, 'nearby', $requested_region_name);
$nearby_items = halkidiki_ai_filter_businesses_by_intent($nearby_items, $intent);
$nearby_items = array_values(array_filter($nearby_items, function($item) use ($nearby_region_names) {
    $dr = $item['display_region'] ?? '';
    if ($dr === '') return false;
    foreach ($nearby_region_names as $near) {
        if (halkidiki_ai_normalize_text($dr) === halkidiki_ai_normalize_text($near)) return true;
    }
    return false;
}));
$debug['nearby_candidates_after_filter'] = $nearby_items;
$nearby_items = array_slice($nearby_items, $offset, 6);

$nearby_count = count($nearby_items);
$results = array_merge($results, $nearby_items);
$used_post_ids = array_merge($used_post_ids, $nearby_ids);
        }
    } else {
        $results = [];
    }

    $results = array_slice($results, 0, 6);

    $final_data = [
        'businesses'           => $results,
        'detected_region'      => $requested_region_name,
        'detected_intent'      => $intent['type'],
        'exact_count'          => $exact_count,
        'nearby_count'         => $nearby_count,
        'nearby_region_names'  => $nearby_region_names,
        'exact_total'          => isset($exact_total) ? $exact_total : $exact_count,
        'debug'                => $debug,
    ];

    halkidiki_ai_set_cached($business_cache_key, $final_data, 600);

    return $final_data;
}

function halkidiki_ai_build_businesses_text($message) {
    $data = halkidiki_ai_get_filtered_businesses($message);
    $businesses = $data['businesses'];

    if (count($businesses) > 6) {
        $businesses = array_slice($businesses, 0, 6);
    }

    if (empty($businesses)) {
        return "FILTERED BUSINESSES: No matching partner businesses found for this request.";
    }

    $lines = [];
    $lines[] = "FILTERED PARTNER BUSINESSES:";

    if (!empty($data['detected_region'])) {
        $lines[] = "Requested region: " . $data['detected_region'];
        $lines[] = "Exact-region partner businesses found: " . (int) ($data['exact_count'] ?? 0);
        $lines[] = "Nearby fallback partner businesses included: " . (int) ($data['nearby_count'] ?? 0);
        if (!empty($data['nearby_region_names'])) {
            $lines[] = "Allowed nearby fallback villages: " . implode(", ", $data['nearby_region_names']);
        }
    }

    if (!empty($data['detected_intent'])) {
        $lines[] = "Detected business intent: " . $data['detected_intent'];
    }

    foreach ($businesses as $business) {
        $cat = !empty($business['categories']) ? implode(', ', $business['categories']) : 'N/A';
        $reg = !empty($business['regions']) ? implode(', ', $business['regions']) : 'N/A';
        $feat = !empty($business['features']) ? implode(', ', $business['features']) : 'N/A';
        $scope = !empty($business['match_scope']) ? $business['match_scope'] : 'general';
        $display_region = !empty($business['display_region']) ? $business['display_region'] : 'N/A';

        $desc = !empty($business['description']) ? $business['description'] : 'No description available.';

$lines[] = "- {$business['name']} | Match: {$scope} | Display region: {$display_region} | Categories: {$cat} | Regions: {$reg} | Features: {$feat} | Description: {$desc} | Link: {$business['link']}";
    }

    return implode("\n", $lines);
}

function halkidiki_ai_build_deterministic_business_reply($context, $business_data) {
    $region = $context['selected_region'] ?? '';
    $items = $business_data['businesses'] ?? [];
    if (!empty($context['is_yes_nearby_request']) && empty($items)) {
        return 'Δεν βρήκα κοντινές συνεργαζόμενες επιλογές για αυτό που ζητάτε.';
    }
    if (!empty($context['is_more_request']) && empty($items)) {
        return 'Δεν υπάρχουν άλλες διαθέσιμες επιλογές σε αυτό το φίλτρο. Αν θέλετε, αλλάξτε περιοχή ή κατηγορία.';
    }
    if (!empty($context['needs_clarification'])) {
        if (!empty($context['selected_region']) && empty($context['selected_intent'])) {
            return 'Τι είδους επιλογή ψάχνετε στο/στην ' . $context['selected_region'] . '; φαγητό, καφέ, ποτό, brunch ή κάτι άλλο;';
        }
        if (empty($context['selected_region']) && !empty($context['selected_intent'])) {
            return 'Σε ποια περιοχή θέλετε να το δω;';
        }
        return 'Μπορείτε να μου πείτε περιοχή και τι ακριβώς θέλετε (π.χ. καφέ, φαγητό), για να σας δείξω σωστές επιλογές;';
    }
    if (empty($items)) {
        return 'Δεν βρήκα διαθέσιμες συνεργαζόμενες επιλογές για αυτό που ζητάτε.';
    }
    $lines = [];
    if (($business_data['exact_total'] ?? 0) === 0 && !empty($region) && !empty($business_data['nearby_count'])) {
        $lines[] = "Δεν βρήκα διαθέσιμη συνεργαζόμενη επιλογή ακριβώς στην {$region} για αυτό που ζητάτε. Μπορείτε όμως να δείτε κοντινές επιλογές:";
    } else {
        $lines[] = "Για αυτό που αναζητάτε στο/στην {$region}, μπορείτε να δείτε:";
    }
    foreach ($items as $b) {
        $name = html_entity_decode((string) ($b['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $scope = $b['match_scope'] ?? 'exact';
        $disp = $b['display_region'] ?? '';
        $cat = !empty($b['categories']) ? implode(', ', $b['categories']) : 'συνεργαζόμενη επιλογή';
        $desc = !empty($b['description']) ? $b['description'] : '';
        if ($desc === '') {
            $desc = 'Μια καλή επιλογή';
            if ($disp !== '') $desc .= ' στην περιοχή ' . $disp;
            $desc .= ' για αυτό που ζητάτε.';
        }
        if (halkidiki_ai_normalize_text($name) === halkidiki_ai_normalize_text($desc)) {
            $desc = 'Μια καλή επιλογή για αυτό που ζητάτε.';
        }
        if ($scope === 'nearby' && $disp !== '') {
            $lines[] = "Κοντινή επιλογή στη {$disp}: {$name} — {$desc}";
        } else {
            $lines[] = "- {$name} — {$desc}";
        }
    }
    if (($business_data['exact_total'] ?? 0) > (($context['offset'] ?? 0) + 6)) {
        $lines[] = 'Υπάρχουν και άλλες ακριβείς επιλογές. Αν θέλετε, πείτε «άλλα».';
    }
    return implode("\n", $lines);
}

function halkidiki_ai_detect_region_clean($message, $region_map) {
    return halkidiki_ai_detect_region_canonical($message, $region_map);
}

function halkidiki_ai_detect_intent_clean($message) {
    return halkidiki_ai_detect_business_intent($message);
}

function halkidiki_ai_resolve_pending_context_clean($message, $pending_context, $region_map) {
    $region = halkidiki_ai_detect_region_clean($message, $region_map);
    $intent = halkidiki_ai_detect_intent_clean($message);
    $resolved = [
        'current_region' => $region['name'] ?? '',
        'current_intent' => $intent['type'] ?? '',
        'final_region' => $region['name'] ?? '',
        'final_intent' => $intent['type'] ?? '',
        'needs_clarification' => false,
        'pending_out' => ['pending_region' => '', 'pending_intent' => ''],
    ];
    if ($resolved['final_region'] !== '' && $resolved['final_intent'] === '' && !empty($pending_context['pending_intent'])) {
        $resolved['final_intent'] = $pending_context['pending_intent'];
    } elseif ($resolved['final_region'] === '' && $resolved['final_intent'] !== '' && !empty($pending_context['pending_region'])) {
        $resolved['final_region'] = $pending_context['pending_region'];
    }
    if ($resolved['final_region'] === '' || $resolved['final_intent'] === '') {
        $resolved['needs_clarification'] = true;
        $resolved['pending_out'] = ['pending_region' => $resolved['final_region'], 'pending_intent' => $resolved['final_intent']];
    }
    return $resolved;
}

function halkidiki_ai_query_businesses_clean($final_region, $final_intent) {
    $ctx = ['selected_region' => $final_region, 'selected_intent' => $final_intent, 'allow_nearby' => true, 'offset' => 0];
    return halkidiki_ai_get_filtered_businesses($final_region . ' ' . $final_intent, $ctx);
}

function halkidiki_ai_format_business_reply_clean($resolved, $data) {
    if ($resolved['final_region'] === '' && $resolved['final_intent'] !== '') return 'Σε ποια περιοχή θέλετε να το δω;';
    if ($resolved['final_region'] !== '' && $resolved['final_intent'] === '') return 'Τι είδους επιλογή ψάχνετε στο/στην ' . $resolved['final_region'] . '; φαγητό, καφέ, ποτό, brunch ή κάτι άλλο;';
    if ($resolved['final_region'] === '' && $resolved['final_intent'] === '') return 'Μπορείτε να μου πείτε περιοχή και τι ακριβώς θέλετε (π.χ. καφέ, φαγητό), για να σας δείξω σωστές επιλογές;';
    $items = $data['businesses'] ?? [];
    if (empty($items)) return 'Δεν βρήκα διαθέσιμες συνεργαζόμενες επιλογές για αυτό που ζητάτε.';
    $lines = [];
    if (($data['exact_total'] ?? 0) > 0) {
        $lines[] = 'Για αυτό που αναζητάτε στο ' . $resolved['final_region'] . ', μπορείτε να δείτε:';
    } else {
        $lines[] = 'Στην ' . $resolved['final_region'] . ' δεν βρήκα ακριβώς ταιριαστές συνεργαζόμενες επιλογές, αλλά μπορείτε να δείτε κοντινές επιλογές:';
    }
    foreach (array_slice($items, 0, 6) as $b) {
        $name = html_entity_decode((string)($b['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $desc = halkidiki_ai_clean_business_description($b, $resolved['final_intent'] ?? '', $resolved['final_region'] ?? '');
        if (($b['match_scope'] ?? '') === 'nearby') {
            $lines[] = '- Κοντινή επιλογή στην ' . ($b['display_region'] ?? '') . ': ' . $name . ' — ' . $desc;
        } else {
            $lines[] = '- ' . $name . ' — ' . $desc;
        }
    }
    return implode("\n", $lines);
}

function halkidiki_ai_clean_business_description($business, $intent, $region) {
    $name = html_entity_decode((string)($business['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $desc = html_entity_decode((string)($business['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $desc = trim(wp_strip_all_tags($desc));
    if ($desc !== '') {
        $desc = preg_replace('/^' . preg_quote($name, '/') . '\s*[-–—:,.]?\s*/iu', '', $desc);
        $desc = preg_replace('/^(to|το)\s+' . preg_quote($name, '/') . '\s*[-–—:,.]?\s*/iu', '', $desc);
        $desc = preg_replace('/^' . preg_quote(mb_strtoupper($name, 'UTF-8'), '/') . '\s*[-–—:,.]?\s*/u', '', $desc);
        $parts = preg_split('/[.!;;]/u', $desc);
        $desc = trim($parts[0] ?? $desc);
    }
    if ($desc === '' || halkidiki_ai_normalize_text($desc) === halkidiki_ai_normalize_text($name)) {
        if ($intent === 'coffee') return 'Καλή επιλογή για καφέ και χαλαρή στάση στην περιοχή.';
        if ($intent === 'drink' || $intent === 'nightlife') return 'Ιδανική επιλογή για ποτό ή βραδινή έξοδο στην περιοχή.';
        if ($intent === 'food') return 'Καλή επιλογή για φαγητό και χαλαρή ατμόσφαιρα στην περιοχή.';
        if ($intent === 'dessert' || $intent === 'icecream') return 'Ωραία επιλογή για γλυκό ή παγωτό στην περιοχή.';
        return 'Μια καλή επιλογή για αυτό που ζητάτε.';
    }
    return wp_trim_words($desc, 18, '...');
}

function halkidiki_ai_detect_smalltalk($message) {
    $n = halkidiki_ai_normalize_text($message);
    $patterns = ['γεια','γεια σου','γεια σας','καλημερα','καλησπερα','ευχαριστω','ευχαριστω πολυ','τελεια','οκ','okay','thanks','thank you'];
    foreach ($patterns as $p) {
        if (strpos($n, $p) !== false) return true;
    }
    return false;
}

function halkidiki_ai_build_smalltalk_reply($message) {
    $n = halkidiki_ai_normalize_text($message);
    if (strpos($n, 'ευχαριστ') !== false) return 'Παρακαλώ, χαρά μου! Αν θέλετε, μπορώ να σας βοηθήσω με φαγητό, καφέ, παραλίες ή ένα μικρό πρόγραμμα για τη μέρα σας στη Χαλκιδική.';
    if (strpos($n, 'τελεια') !== false) return 'Χαίρομαι πολύ! Είμαι εδώ αν θέλετε κι άλλη πρόταση για παραλία, φαγητό, ποτό ή πρόγραμμα ημέρας.';
    return 'Γεια σας! Πείτε μου περιοχή και τι ψάχνετε — φαγητό, καφέ, ποτό, παραλία ή πρόγραμμα — και θα σας προτείνω κάτι ταιριαστό.';
}

function halkidiki_ai_detect_planner_request($message) {
    $n = halkidiki_ai_normalize_text($message);
    $tokens = ['προγραμμα','πλανο','day plan','itinerary','εκδρομη','οργανωσε μου τη μερα','τι να κανω σημερα','μια μερα','μια ημερα'];
    foreach ($tokens as $t) if (strpos($n, halkidiki_ai_normalize_text($t)) !== false) return true;
    return false;
}

function halkidiki_ai_build_planner_dataset_context($dataset, $region = '') {
    $parts = [];
    foreach (['beaches' => 'Παραλίες', 'attractions' => 'Αξιοθέατα', 'archaeological_sites' => 'Αρχαιολογικοί χώροι'] as $k => $label) {
        if (!empty($dataset['about_the_area'][$k]) && is_array($dataset['about_the_area'][$k])) {
            $slice = array_slice($dataset['about_the_area'][$k], 0, 3);
            $names = array_map(function($i){ return $i['name'] ?? ''; }, $slice);
            $parts[] = $label . ': ' . implode(', ', array_filter($names));
        }
    }
    if (empty($parts)) return 'Υπάρχουν περιορισμένα διαθέσιμα δεδομένα περιοχής.';
    return implode(' | ', $parts);
}

function halkidiki_ai_build_planner_reply_clean($message, $pending_context = []) {
    $dataset = halkidiki_ai_get_dataset();
    $taxes = halkidiki_ai_get_listing_taxonomies();
    $region_map = halkidiki_ai_get_taxonomy_terms_map($taxes['region']);
    $region = halkidiki_ai_detect_region_clean($message, $region_map);
    $intent = halkidiki_ai_detect_intent_clean($message);
    $region_name = $region['name'] ?? '';
    $style = $intent['type'] ?? '';
    if ($region_name === '') {
        return ['reply' => 'Από ποια περιοχή ξεκινάτε και τι ύφος θέλετε; παραλία, φαγητό, βόλτα ή βραδινή έξοδο;', 'pending' => ['pending_region' => '', 'pending_intent' => '']];
    }
    $food = halkidiki_ai_query_businesses_clean($region_name, 'food');
    $drink = halkidiki_ai_query_businesses_clean($region_name, 'drink');
    $food_names = array_slice(array_map(function($b){ return $b['name'] ?? ''; }, $food['businesses'] ?? []), 0, 2);
    $drink_names = array_slice(array_map(function($b){ return $b['name'] ?? ''; }, $drink['businesses'] ?? []), 0, 2);
    $dataset_ctx = halkidiki_ai_build_planner_dataset_context($dataset, $region_name);
    $beach = $dataset['about_the_area']['beaches'][0]['name'] ?? '';
    $attr = $dataset['about_the_area']['attractions'][0]['name'] ?? ($dataset['about_the_area']['archaeological_sites'][0]['name'] ?? '');
    $reply = "Βεβαίως! Για μια όμορφη μέρα στο {$region_name}, θα σας πρότεινα:\n\nΠρωί:\n";
    if ($beach !== '') {
        $reply .= "Ξεκινήστε με παραλία, ιδανικά προς {$beach}, και έναν χαλαρό καφέ στην περιοχή.\n\n";
    } else {
        $reply .= "Ξεκινήστε με χαλαρή βόλτα και καφέ στην περιοχή.\n\n";
    }
    $reply .= "Μεσημέρι:\n";
    $reply .= !empty($food_names) ? ('Για φαγητό μπορείτε να δείτε: ' . implode(', ', $food_names) . ".\n\n") : "Συνεχίστε με παραλία και ένα ήρεμο γεύμα στην περιοχή.\n\n";
    $reply .= "Απόγευμα:\n";
    if ($attr !== '') {
        $reply .= "Κάντε βόλτα και προσθέστε ένα σημείο ενδιαφέροντος όπως {$attr}.";
    } else {
        $reply .= "{$dataset_ctx}";
    }
    $reply .= "\n\nΒράδυ:\n";
    $reply .= !empty($drink_names) ? ('Για ποτό μπορείτε να δείτε: ' . implode(', ', $drink_names) . '.') : 'Κλείστε τη μέρα με μια χαλαρή βόλτα και ποτό στην περιοχή.';
    if ($style !== '') $reply .= "\n\nΈλαβα υπόψη και την προτίμησή σας για: {$style}.";
    $reply .= "\n\nΑν θέλετε, μπορώ να το κάνω και πιο χαλαρό, πιο οικογενειακό ή πιο βραδινό.";
    return ['reply' => $reply, 'pending' => ['pending_region' => '', 'pending_intent' => '']];
}

function halkidiki_ai_resolve_route_clean($message, $pending_context, $planner_context) {
    $route = ['route' => 'ai', 'region' => '', 'intent' => '', 'pendingContext' => ['pending_region'=>'','pending_intent'=>''], 'plannerContext' => ['active'=>false,'type'=>'']];
    if (halkidiki_ai_detect_smalltalk($message)) {
        $route['route'] = 'smalltalk';
        return $route;
    }
    if (!empty($planner_context['active'])) {
        $route['route'] = 'planner_reply';
        $route['plannerContext'] = ['active'=>false,'type'=>''];
        return $route;
    }
    if (halkidiki_ai_detect_planner_request($message)) {
        $taxes = halkidiki_ai_get_listing_taxonomies();
        $region_map = halkidiki_ai_get_taxonomy_terms_map($taxes['region']);
        $region = halkidiki_ai_detect_region_clean($message, $region_map);
        if (!empty($region['name'])) {
            $route['route'] = 'planner_reply';
            return $route;
        }
        $route['route'] = 'planner_clarification';
        $route['plannerContext'] = ['active'=>true,'type'=>'day_plan'];
        return $route;
    }
    $taxes = halkidiki_ai_get_listing_taxonomies();
    $region_map = halkidiki_ai_get_taxonomy_terms_map($taxes['region']);
    $resolved = halkidiki_ai_resolve_pending_context_clean($message, $pending_context, $region_map);
    $route['region'] = $resolved['final_region'];
    $route['intent'] = $resolved['final_intent'];
    $route['pendingContext'] = $resolved['pending_out'];
    if ($resolved['final_region'] !== '' || $resolved['final_intent'] !== '' || !empty($resolved['needs_clarification'])) {
        $route['route'] = $resolved['needs_clarification'] ? 'business_clarification' : 'business_reply';
        return $route;
    }
    return $route;
}

function halkidiki_ai_is_business_request($message, $business_data = []) {
    $normalized = halkidiki_ai_normalize_text($message);

    $keywords = [
        'φαγητ', 'φαω', 'φαμε', 'εστιατ', 'restaurant', 'food',
        'καφε', 'coffee', 'cafe',
        'ποτο', 'drink', 'bar', 'cocktail', 'club', 'nightlife',
        'παγωτ', 'ice cream', 'gelato',
        'γλυκ', 'dessert', 'sweet',
        'brunch',
        'burger',
        'pizza',
        'seafood', 'ψαρ',
        'ξενοδοχ', 'hotel', 'διαμον', 'stay', 'accommodation',
        'shopping', 'αγορα', 'αγορά', 'souvenir', 'σουβενιρ', 'σουβενίρ',
        'μαγαζ', 'listing', 'partner'
    ];

    foreach ($keywords as $kw) {
        if (strpos($normalized, halkidiki_ai_normalize_text($kw)) !== false) {
            return true;
        }
    }

    if (!empty($business_data['detected_intent']) && $business_data['detected_intent'] !== 'general') {
        return true;
    }

    return false;
}

function halkidiki_ai_get_allowed_business_names($businesses) {
    $allowed = [];

    if (!is_array($businesses)) {
        return $allowed;
    }

    foreach ($businesses as $business) {
        if (!empty($business['name'])) {
            $name = trim(wp_strip_all_tags($business['name']));
            if ($name !== '') {
                $allowed[$name] = halkidiki_ai_normalize_text($name);
            }
        }
    }

    return $allowed;
}

function halkidiki_ai_extract_mentioned_allowed_businesses($reply, $allowed_businesses) {
    $mentioned = [];

    if (empty($reply) || empty($allowed_businesses)) {
        return $mentioned;
    }

    $reply_norm = halkidiki_ai_normalize_text(wp_strip_all_tags($reply));

    foreach ($allowed_businesses as $original => $norm) {
        if ($norm !== '' && strpos($reply_norm, $norm) !== false) {
            $mentioned[] = $original;
        }
    }

    return array_values(array_unique($mentioned));
}

function halkidiki_ai_build_partner_only_fallback_reply($message, $business_data = []) {
    $businesses = $business_data['businesses'] ?? [];
    $intent = $business_data['detected_intent'] ?? '';
    $region = $business_data['detected_region'] ?? '';

    if (empty($businesses)) {
        $text = 'Για αυτό που αναζητάτε';

        if (!empty($region)) {
            $text .= ' στην περιοχή ' . $region;
        }

        $text .= ', δεν εμφανίστηκε αυτή τη στιγμή κάποια ακριβώς ταιριαστή συνεργαζόμενη επιλογή.';

        $text .= ' Αν θέλετε, μπορώ να το δω σε κοντινή περιοχή ή να το φιλτράρω πιο συγκεκριμένα, για παράδειγμα με πιο χαλαρό, πιο βραδινό ή πιο κεντρικό ύφος.';

        return $text;
    }

    $slice = array_slice($businesses, 0, 3);
    $parts = [];

    foreach ($slice as $b) {
        if (!empty($b['name'])) {
            $parts[] = $b['name'];
        }
    }

    if (empty($parts)) {
        return 'Αν θέλετε, πείτε μου λίγο πιο συγκεκριμένα τι ύφος ή περιοχή προτιμάτε και θα το περιορίσω καλύτερα.';
    }

    if (count($parts) === 1) {
        $text = 'Μια πολύ καλή επιλογή';
        if (!empty($region)) {
            $text .= ' στην περιοχή ' . $region;
        }
        $text .= ' είναι το ' . $parts[0] . '.';
    } else {
        $text = 'Μερικές αξιόλογες επιλογές';
        if (!empty($region)) {
            $text .= ' στην περιοχή ' . $region;
        }
        $text .= ' είναι τα ' . implode(', ', $parts) . '.';
    }

    if ($intent === 'nightlife' || $intent === 'drink' || $intent === 'club') {
        $text .= ' Αν θέλετε, μπορώ να το περιορίσω περισσότερο σε cocktail, beach bar ή πιο βραδινή έξοδο.';
    } elseif ($intent === 'food' || $intent === 'burger' || $intent === 'pizza' || $intent === 'seafood' || $intent === 'brunch') {
        $text .= ' Αν θέλετε, μπορώ να σας προτείνω και κάτι πιο συγκεκριμένο ανάλογα με το είδος κουζίνας ή το ύφος που προτιμάτε.';
    } elseif ($intent === 'coffee' || $intent === 'dessert' || $intent === 'icecream') {
        $text .= ' Αν θέλετε, μπορώ να σας δώσω και πιο συγκεκριμένη πρόταση ανάλογα με το στιλ που αναζητάτε.';
    } elseif ($intent === 'stay') {
        $text .= ' Αν θέλετε, μπορώ να σας δείξω και πιο συγκεκριμένες επιλογές ανάλογα με το budget ή το ύφος διαμονής.';
    }

    return $text;
}

function halkidiki_ai_enforce_partner_only_reply($reply, $message, $business_data = []) {
    if (!halkidiki_ai_is_business_request($message, $business_data)) {
        return $reply;
    }

    $businesses = $business_data['businesses'] ?? [];
    $allowed = halkidiki_ai_get_allowed_business_names($businesses);
    $mentioned_allowed = halkidiki_ai_extract_mentioned_allowed_businesses($reply, $allowed);

    if (empty($mentioned_allowed)) {
        return halkidiki_ai_build_partner_only_fallback_reply($message, $business_data);
    }

    return $reply;
}

/**
 * -------------------------------------------------------
 * 4. DATASET TO PROMPT
 * -------------------------------------------------------
 */

function halkidiki_ai_build_dataset_text($dataset) {
    $lines = [];

    $lines[] = "SERVICES";

    if (!empty($dataset['services']['hospitals']) && is_array($dataset['services']['hospitals'])) {
        $lines[] = "HOSPITALS:";
        foreach ($dataset['services']['hospitals'] as $hospital) {
            $name = $hospital['name'] ?? '';
            $description = $hospital['description'] ?? '';
            $type = $hospital['type'] ?? 'general';

            $lines[] = "- {$name} | {$type} | {$description}";
        }
    }

    if (isset($dataset['services']['parking']) && empty($dataset['services']['parking'])) {
        $lines[] = "PARKING: No data available yet.";
    }

    $lines[] = "ABOUT THE AREA";

    $groups = [
        'attractions' => 'ATTRACTIONS',
        'archaeological_sites' => 'ARCHAEOLOGICAL SITES',
        'beaches' => 'BEACHES',
        'thermal_tourism' => 'THERMAL TOURISM',
    ];

    foreach ($groups as $key => $label) {
        if (!empty($dataset['about_the_area'][$key]) && is_array($dataset['about_the_area'][$key])) {
            $lines[] = $label . ':';

            foreach ($dataset['about_the_area'][$key] as $item) {
                $name = $item['name'] ?? '';
                $description = $item['description'] ?? '';
                $lines[] = "- {$name} | {$description}";
            }
        }
    }

    if (!empty($dataset['events']) && is_array($dataset['events'])) {
        $has_events = false;

        foreach ($dataset['events'] as $event_group_key => $event_group_items) {
            if (!empty($event_group_items) && is_array($event_group_items)) {
                $has_events = true;
                $lines[] = 'EVENTS - ' . strtoupper(str_replace('_', ' ', $event_group_key)) . ':';

                foreach ($event_group_items as $item) {
                    $name = $item['name'] ?? '';
                    $description = $item['description'] ?? '';
                    $lines[] = "- {$name} | {$description}";
                }
            }
        }

        if (!$has_events) {
            $lines[] = "EVENTS: No data available yet.";
        }
    } else {
        $lines[] = "EVENTS: No data available yet.";
    }

    return implode("\n", $lines);
}

/**
 * -------------------------------------------------------
 * 5. DEEPSEEK REQUEST
 * -------------------------------------------------------
 */

function halkidiki_ai_call_deepseek($user_message, $history = []) {
    if (empty(HALKIDIKI_DEEPSEEK_API_KEY)) {
        return 'Υπάρχει προσωρινό τεχνικό πρόβλημα. Παρακαλώ δοκιμάστε ξανά σε λίγο.';
    }

	$cache_payload = [
    'message' => $user_message,
    'history' => halkidiki_ai_limit_history($history, 6),
	];

	$ai_cache_key = halkidiki_ai_make_cache_key('reply_v8', $cache_payload);
	$cached_reply = halkidiki_ai_get_cached($ai_cache_key);

	if ($cached_reply !== false && is_string($cached_reply)) {
    return $cached_reply;
	}

    $dataset = halkidiki_ai_get_dataset();
	$dataset_text = halkidiki_ai_build_dataset_text($dataset);
	$business_data = halkidiki_ai_get_filtered_businesses($user_message);
	$businesses_text = halkidiki_ai_build_businesses_text($user_message);

    $system_prompt = <<<EOT
You are the official AI guide and assistant for Halkidiki Experience.

Your role is to help users discover Halkidiki through the Halkidiki Experience dataset and the filtered partner businesses. The current available business and destination data is mainly for Kassandra. If the user asks for Sithonia, Athos, or another Halkidiki area that is not covered by the provided data, say clearly that the available Halkidiki Experience data currently focuses mainly on Kassandra and offer Kassandra alternatives only if helpful.

You can act as:
- a local travel guide
- a business recommendation assistant
- a village discovery assistant
- a travel planner only when the user asks for a plan

CORE RULES:
1. Reply in the same language as the user.
2. Use ONLY the provided Halkidiki Experience dataset and the filtered partner businesses.
3. Do NOT invent places, businesses, events, URLs, opening hours, prices, or facts.
4. Follow the user's request exactly.
5. If the user asks a simple question, give a simple direct answer.
6. Do NOT create a full-day plan unless the user explicitly asks for a full-day plan.
7. If the user asks only for morning, afternoon, evening, beach, food, coffee, or one part of the day, answer only for that part.
8. Keep most answers to 2-4 sentences unless the user asks for a detailed plan.
9. In Greek, address the user politely in plural form.
10. Use a warm, welcoming, premium local-guide tone.
11. Do not use markdown symbols such as **, *, #, or markdown bullets.
12. Do not include raw URLs.

PARTNER BUSINESS RULES:
13. For food, drinks, cafes, restaurants, beach bars, bars, clubs, desserts, ice cream, brunch, shopping, activities, bookings, rentals, wellness, or accommodation, mention ONLY business names that exist in the FILTERED PARTNER BUSINESSES section.
14. Write every partner business name EXACTLY as shown in FILTERED PARTNER BUSINESSES. Preserve spelling, capitalization, spacing, punctuation, and wording.
15. Never translate, shorten, rephrase, uppercase, lowercase, or modify a partner business name.
16. For a business request, suggest 1 to 3 partner businesses if available.
16A. For every partner business you recommend, write the exact business name followed by one short useful sentence describing what makes it suitable. Use the provided Description when available. If no Description exists, describe it only based on its category and region. Do not invent specialties, menu items, prices, awards, opening hours, or facts.
16B. Do not answer with only a list of names. Every recommended business must have a short description sentence.
17. If a business is marked Match: exact, it belongs to the user's requested village/region.
18. If a business is marked Match: nearby, it is NOT in the exact requested village. You MUST clearly label it as a nearby fallback, for example: “Κοντινή επιλογή στη Χανιώτη: Business Name”.
19. Never say or imply that a nearby fallback business is inside the requested village.
20. If the user asks for Pefkochori/Pefkohori/Πευκοχώρι and a listed business has Display region Χανιώτη, Παλιούρι, or Πολύχρονο, explicitly say that it is in that nearby village, not in Πευκοχώρι.
21. Do not recommend far villages as fallback. Use only the nearby fallback businesses already provided in FILTERED PARTNER BUSINESSES.
22. If no exact partner business is available and nearby fallback businesses are available, first say that there are not enough exact matches in the requested village and then present nearby options with their real village.
23. If no partner business exists for a business request, say it clearly and do not mention non-partner businesses.
24. Prefer exact-region partner businesses before nearby fallback businesses.
25. Do not mix exact and nearby businesses without labeling the nearby ones.
26. Treat the provided Nearby fallback villages as the ONLY allowed nearby villages for this request. If a village is not in that list, do not recommend it as nearby.

DESTINATION AND DAY-PLAN RULES:
26. For beaches, attractions, archaeological sites, thermal tourism, and sightseeing, use the AVAILABLE HALKIDIKI EXPERIENCE DATA.
27. A good day plan should be realistic, location-aware, and route-aware. Avoid jumping between far villages without reason.
28. If the user asks for a full plan but does not provide enough details, ask up to 3 short follow-up questions before creating the plan. Ask mainly: where they stay/start from, whether they have a car, and whether they prefer beach/food/sightseeing/nightlife/family/couple style.
29. If the user already provides area and preferences, answer immediately without unnecessary follow-up.
30. Use these day parts when creating a full plan, but DO NOT write clock times next to the headings unless the user specifically asks for times:
Πρωί: coffee/brunch, beach, and possibly one light attraction. Do not put clock times in the heading.
Μεσημέρι: food and possibly beach.
Απόγευμα: attraction, beach, walk, sunset. Do not put clock times in the heading.
Βράδυ: dinner and/or drink. Do not put clock times in the heading.
31. For every area-specific request like “κοντά στη/στο [village]”, keep the answer inside the requested village and the nearby fallback villages already provided in FILTERED PARTNER BUSINESSES only. Never invent extra nearby villages and never jump to far villages. Example: for Καλλιθέα, nearby means only the villages in Allowed nearby fallback villages, not random/far villages.
32. If wind or crowds may affect the experience, offer nearby alternatives using the provided dataset and common route logic, without inventing live weather or crowd data.
33. When the dataset does not support an area outside Kassandra, be transparent and offer a Kassandra-based plan instead.

AVAILABLE HALKIDIKI EXPERIENCE DATA:
{$dataset_text}

FILTERED PARTNER BUSINESSES:
{$businesses_text}
EOT;

    $messages = [
        [
            'role' => 'system',
            'content' => $system_prompt,
        ]
    ];

    $history = halkidiki_ai_limit_history($history, 6);
    foreach ($history as $item) {
        $messages[] = $item;
    }

    $messages[] = [
        'role' => 'user',
        'content' => $user_message,
    ];

    $body = [
        'model' => 'deepseek-chat',
        'messages' => $messages,
        'temperature' => 0.4,
        'max_tokens' => 750,
    ];

    $response = wp_remote_post('https://api.deepseek.com/chat/completions', [
        'timeout' => 45,
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . HALKIDIKI_DEEPSEEK_API_KEY,
        ],
        'body' => wp_json_encode($body),
    ]);

    if (is_wp_error($response)) {
    return 'Σφάλμα API: ' . $response->get_error_message();
	}

    $status_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);

    if ($status_code !== 200) {
    return 'Υπάρχει προσωρινό τεχνικό πρόβλημα. Παρακαλώ δοκιμάστε ξανά σε λίγο.';
	}

    if (!isset($data['choices'][0]['message']['content'])) {
        return 'Δεν βρέθηκε απάντηση από το AI.';
    }

    $reply = trim(wp_strip_all_tags($data['choices'][0]['message']['content']));
	$reply = str_replace(['**', '*', '##', '#'], '', $reply);
	$reply = preg_replace('/\s+/', ' ', $reply);
	$reply = trim($reply);

	$reply = halkidiki_ai_enforce_partner_only_reply($reply, $user_message, $business_data);

	halkidiki_ai_set_cached($ai_cache_key, $reply, 900);

	return $reply;
	}

/**
 * -------------------------------------------------------
 * 6. REST ENDPOINT
 * -------------------------------------------------------
 */

add_action('rest_api_init', function () {
    register_rest_route('halkidiki-ai/v1', '/chat', [
        'methods' => 'POST',
        'callback' => 'halkidiki_ai_chat_endpoint',
        'permission_callback' => '__return_true',
    ]);
});

function halkidiki_ai_chat_endpoint(WP_REST_Request $request) {
    if (halkidiki_ai_is_rate_limited()) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Περίμενε λίγα δευτερόλεπτα και ξαναστείλε μήνυμα.'
        ], 429);
    }

    $params = $request->get_json_params();

    $message = isset($params['message']) ? sanitize_textarea_field($params['message']) : '';
    $history = isset($params['history']) ? $params['history'] : [];
    $pending_context = isset($params['pendingContext']) && is_array($params['pendingContext']) ? $params['pendingContext'] : [];
    $planner_context = isset($params['plannerContext']) && is_array($params['plannerContext']) ? $params['plannerContext'] : ['active'=>false,'type'=>''];
    if (is_array($history)) {
        $history['_pending_context'] = $pending_context;
    }

    if (empty($message)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Δεν βρέθηκε μήνυμα.'
        ], 400);
    }

    $route_data = halkidiki_ai_resolve_route_clean($message, $pending_context, $planner_context);
    $route = $route_data['route'];
    $business_data = ['businesses' => []];
    $out_pending = $route_data['pendingContext'];
    $out_planner = $route_data['plannerContext'];
    $resolved_clean = ['current_region'=>$route_data['region'],'current_intent'=>$route_data['intent'],'final_region'=>$route_data['region'],'final_intent'=>$route_data['intent']];

    switch ($route) {
        case 'smalltalk':
            $reply = halkidiki_ai_build_smalltalk_reply($message);
            break;
        case 'planner_clarification':
            $reply = 'Από ποια περιοχή ξεκινάτε και τι ύφος θέλετε; παραλία, φαγητό, βόλτα ή βραδινή έξοδο;';
            break;
        case 'planner_reply':
            $planner = halkidiki_ai_build_planner_reply_clean($message, $pending_context);
            $reply = $planner['reply'];
            $out_pending = $planner['pending'];
            $out_planner = ['active'=>false,'type'=>''];
            break;
        case 'business_clarification':
        case 'business_reply':
            if ($route === 'business_reply') {
                $business_data = halkidiki_ai_query_businesses_clean($route_data['region'], $route_data['intent']);
            }
            $reply = halkidiki_ai_format_business_reply_clean([
                'final_region' => $route_data['region'],
                'final_intent' => $route_data['intent']
            ], $business_data);
            break;
        default:
            $reply = halkidiki_ai_call_deepseek($message, $history);
    }

$business_cards = [];
$reply_normalized = halkidiki_ai_normalize_text($reply);

if (!empty($business_data['businesses']) && is_array($business_data['businesses'])) {
    foreach ($business_data['businesses'] as $business) {
        $business_name = isset($business['name']) ? trim(wp_strip_all_tags($business['name'])) : '';

        if ($business_name === '') {
            continue;
        }

        if (strpos($reply_normalized, halkidiki_ai_normalize_text($business_name)) !== false) {
            $business_cards[] = $business;
        }
    }

    if (empty($business_cards)) {
        $business_cards = array_slice($business_data['businesses'], 0, 6);
    } else {
        $business_cards = array_slice($business_cards, 0, 6);
    }
}

        halkidiki_ai_debug_log([
            'raw_user_message' => $message,
            'history_length' => is_array($history) ? count($history) : 0,
            'resolved_context' => $resolved_clean,
            'detected_region' => $business_data['detected_region'] ?? '',
            'detected_intent' => $business_data['detected_intent'] ?? '',
            'exact_count' => $business_data['exact_count'] ?? 0,
            'nearby_count' => $business_data['nearby_count'] ?? 0,
            'nearby_region_names' => $business_data['nearby_region_names'] ?? [],
            'debug_lists' => $business_data['debug'] ?? [],
            'final_businesses' => array_map(function($b){
                return ['name'=>$b['name'] ?? '', 'display_region'=>$b['display_region'] ?? '', 'match_scope'=>$b['match_scope'] ?? ''];
            }, $business_data['businesses'] ?? []),
            'normalized_message' => halkidiki_ai_normalize_text($message),
            'current_region_detected' => $resolved_clean['current_region'] ?? '',
            'current_intent_detected' => $resolved_clean['current_intent'] ?? '',
            'pending_region_received' => $pending_context['pending_region'] ?? '',
            'pending_intent_received' => $pending_context['pending_intent'] ?? '',
            'final_region' => $resolved_clean['final_region'] ?? '',
            'final_intent' => $resolved_clean['final_intent'] ?? '',
            'route' => $route,
        ]);

		halkidiki_ai_log_interaction($message, $business_data);

	return new WP_REST_Response([
    'success' => true,
    'reply' => $reply,
    'businesses' => $business_cards,
    'pendingContext' => $out_pending,
    'plannerContext' => $out_planner,
	], 200);
}

/**
 * -------------------------------------------------------
 * 7. SHORTCODE UI
 * -------------------------------------------------------
 */

add_shortcode('halkidiki_ai_planner', 'halkidiki_ai_planner_shortcode');
add_shortcode('halkidiki_ai', 'halkidiki_ai_planner_shortcode');

function halkidiki_ai_planner_shortcode() {
    ob_start();
    ?>
    <div id="halkidiki-ai-planner" style="max-width: 900px; margin: 40px auto; font-family: inherit;">
    <div style="background: linear-gradient(135deg, #1F9AA5 0%, #167C85 100%); color: #fff; border-radius: 22px 22px 0 0; padding: 28px 28px 22px 28px; box-shadow: 0 10px 35px rgba(0,0,0,0.10);">
        <div style="font-size: 13px; letter-spacing: 1px; text-transform: uppercase; opacity: 0.75; margin-bottom: 10px;">
            Halkidiki Experience
        </div>
        <h2 style="margin: 0 0 10px 0; font-size: 30px; line-height: 1.2; color: #fff;">
            AI Travel Planner
        </h2>
        <p style="margin: 0; color: rgba(255,255,255,0.82); font-size: 16px; line-height: 1.6; max-width: 720px;">
            Ζητήστε προτάσεις για τη Χαλκιδική με βάση χωριό, παραλία, αξιοθέατα και συνεργαζόμενα καταστήματα.
        </p>
    </div>

    <div style="border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 22px 22px; background: #ffffff; box-shadow: 0 10px 35px rgba(0,0,0,0.08); overflow: hidden;">

        <div style="display:flex; flex-wrap:wrap; gap:10px; padding:18px 20px; background:#f8fafc; border-bottom:1px solid #eef2f7;">

    <button class="halkidiki-ai-quick" data-msg="Θέλω brunch στο Πευκοχώρι">
        Brunch στο Πευκοχώρι
    </button>

    <button class="halkidiki-ai-quick" data-msg="Θέλω βραδινή έξοδο στη Χανιώτη με ποτό">
        Βραδινή έξοδος
    </button>

    <button class="halkidiki-ai-quick" data-msg="Θέλω να δω παραλίες στην Κασσάνδρα">
        Παραλίες
    </button>

    <button class="halkidiki-ai-quick" data-msg="Θέλω εστιατόριο στην Καλλιθέα">
        Φαγητό στην Καλλιθέα
    </button>

    <button class="halkidiki-ai-quick" data-msg="Θέλω βόλτα στην Άφυτο">
        Άφυτος
    </button>

</div>

<style>
.halkidiki-ai-quick{
    border:1px solid #e5e7eb;
    background:#fff;
    border-radius:999px;
    padding:8px 14px;
    font-size:13px;
    cursor:pointer;
    color:#374151;
    transition:all .2s ease;
}

.halkidiki-ai-quick:hover{
    background:#1F9AA5;
    color:#fff;
    border-color:#1F9AA5;
}

	#halkidiki-ai-chat-box a {
    color: #b08a3c !important;
    text-decoration: underline !important;
    font-weight: 700 !important;
}
</style>

        <div id="halkidiki-ai-chat-box" style="height: 500px; overflow-y: auto; padding: 24px; background: #fcfcfd;">
            <div style="display: flex; margin-bottom: 16px;">
                <div style="max-width: 78%; background: #f3f4f6; color: #111827; border-radius: 18px 18px 18px 6px; padding: 14px 16px; line-height: 1.6; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
                    <strong style="display:block; margin-bottom:6px;">Halkidiki Bot</strong>
                    Γεια σας! Μπορώ να σας βοηθήσω να ανακαλύψετε τη Χαλκιδική, με διαθέσιμα δεδομένα κυρίως για Κασσάνδρα, ανάλογα με το χωριό, την παραλία, την ώρα της ημέρας και το είδος της εμπειρίας που αναζητάτε.
                    <div style="margin-top: 10px; font-size: 13px; color: #6b7280;">
                        Παράδειγμα: Θα ήθελα προτάσεις για φαγητό και ποτό στη Χανιώτη ή στο Πευκοχώρι, ή μια ωραία παραλία κοντά στην Άφυτο.
                    </div>
                </div>
            </div>
        </div>

        <div style="padding: 18px 20px 22px 20px; border-top: 1px solid #eef2f7; background: #ffffff;">
            <div style="font-size: 13px; color: #6b7280; margin-bottom: 10px;">
                Περιγράψτε τι αναζητάτε και θα σας προτείνω κάτι όσο πιο ταιριαστό γίνεται.
            </div>

            <div style="display: flex; gap: 12px; align-items: stretch;">
                <input
                    type="text"
                    id="halkidiki-ai-user-input"
                    placeholder="Π.χ. Θέλω brunch στο Πευκοχώρι και μετά μια κοντινή παραλία"
                    style="flex: 1; padding: 16px 18px; border: 1px solid #d1d5db; border-radius: 14px; background: #fff; font-size: 15px; outline: none; box-shadow: inset 0 1px 2px rgba(0,0,0,0.02);"
                />
                <button
                    type="button"
                    id="halkidiki-ai-send-btn"
                    style="padding: 0 22px; min-width: 132px; border: none; border-radius: 14px; cursor: pointer; background: linear-gradient(135deg, #b08a3c 0%, #d4af5f 100%); color: #fff; font-weight: 700; font-size: 15px; box-shadow: 0 8px 18px rgba(176,138,60,0.25);"
                >
                    Αποστολή
                </button>
            </div>
        </div>
    </div>
</div>

    <script>
    (function() {
        const chatBox = document.getElementById('halkidiki-ai-chat-box');
        const input = document.getElementById('halkidiki-ai-user-input');
        const button = document.getElementById('halkidiki-ai-send-btn');
        const endpoint = '<?php echo esc_url(rest_url('halkidiki-ai/v1/chat')); ?>';

        let history = [];
        let pendingContext = { pending_region: '', pending_intent: '' };
        let plannerContext = { active: false, type: '' };
        let isSending = false;

        function appendMessage(sender, text, alignRight = false, isError = false, businesses = []) {
    const row = document.createElement('div');
    row.style.display = 'flex';
    row.style.marginBottom = '16px';
    row.style.justifyContent = alignRight ? 'flex-end' : 'flex-start';

    const bubble = document.createElement('div');
    bubble.style.maxWidth = '78%';
    bubble.style.padding = '14px 16px';
    bubble.style.lineHeight = '1.7';
    bubble.style.borderRadius = alignRight ? '18px 18px 6px 18px' : '18px 18px 18px 6px';
    bubble.style.boxShadow = '0 2px 8px rgba(0,0,0,0.04)';
    bubble.style.whiteSpace = 'pre-wrap';

    if (alignRight) {
        bubble.style.background = 'linear-gradient(135deg, #1F9AA5 0%, #167C85 100%)';
        bubble.style.color = '#ffffff';
    } else if (isError) {
        bubble.style.background = '#fef2f2';
        bubble.style.color = '#991b1b';
        bubble.style.border = '1px solid #fecaca';
    } else {
        bubble.style.background = '#f3f4f6';
        bubble.style.color = '#111827';
    }

    let formattedText = text;

    function halkidikiEscapeRegExp(str) {
    return String(str).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function halkidikiEscapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

if (businesses && businesses.length) {
    businesses.forEach(business => {
        if (!business.name || !business.link) return;

        const originalName = business.name.trim();
        const safeName = halkidikiEscapeHtml(originalName);
        const safeUrl = encodeURI(business.link);

        const candidates = [
            originalName,
            originalName.replace(/&/g, '&amp;'),
            originalName.replace(/\s*&\s*/g, '&'),
            originalName.replace(/\s*&\s*/g, ' & ')
        ];

        candidates.forEach(candidate => {
            if (!candidate) return;

            const escapedCandidate = halkidikiEscapeRegExp(candidate);
            const regex = new RegExp(escapedCandidate, 'gi');

            formattedText = formattedText.replace(regex, function(match) {
                if (match.indexOf('<a ') !== -1 || match.indexOf('</a>') !== -1) {
                    return match;
                }

                return '<a href="' + safeUrl + '" target="_blank" rel="noopener noreferrer" style="color:#b08a3c !important; text-decoration:underline !important; font-weight:700 !important;">' + safeName + '</a>';
            });
        });
    });
}

    bubble.innerHTML = '<strong style="display:block; margin-bottom:6px;">' + sender + '</strong>' + formattedText;

    row.appendChild(bubble);
    chatBox.appendChild(row);
    chatBox.scrollTop = chatBox.scrollHeight;
}

        async function sendMessage() {
            if (isSending) return;

            const message = input.value.trim();
            if (!message) return;

            isSending = true;
            button.disabled = true;

            appendMessage('Εσύ', message, true);
            input.value = '';

            const loading = document.createElement('div');
            loading.id = 'halkidiki-ai-loading';
            loading.style.marginBottom = '12px';
            loading.innerHTML = '<div style="display:flex; justify-content:flex-start; margin-bottom:16px;"><div style="max-width:78%; 					background:#f3f4f6; color:#111827; border-radius:18px 18px 18px 6px; padding:14px 16px; line-height:1.6; box-shadow:0 2px 8px 				rgba(0,0,0,0.04);"><strong style="display:block; margin-bottom:6px;">Halkidiki Bot</strong>Σκέφτομαι...</div></div>';
            chatBox.appendChild(loading);
            chatBox.scrollTop = chatBox.scrollHeight;

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        message: message,
                        history: history,
                        pendingContext: pendingContext,
                        plannerContext: plannerContext
                    })
                });

                const data = await response.json();

                const loadingEl = document.getElementById('halkidiki-ai-loading');
                if (loadingEl) loadingEl.remove();

                if (!response.ok || !data.success) {
					appendMessage('Halkidiki Bot', data.message ? data.message : 'Κάτι πήγε στραβά.', false, true);
				} else {
					appendMessage('Halkidiki Bot', data.reply, false, false, data.businesses || []);
                    if (data.pendingContext && typeof data.pendingContext === 'object') {
                        pendingContext = data.pendingContext;
                    } else {
                        pendingContext = { pending_region: '', pending_intent: '' };
                    }
                    if (data.plannerContext && typeof data.plannerContext === 'object') {
                        plannerContext = data.plannerContext;
                    } else {
                        plannerContext = { active: false, type: '' };
                    }


					history.push({ role: 'user', content: message });
					history.push({ role: 'assistant', content: data.reply });

					if (history.length > 12) {
						history = history.slice(-12);
					}
				}					
							} catch (error) {
                const loadingEl = document.getElementById('halkidiki-ai-loading');
                if (loadingEl) loadingEl.remove();
                appendMessage('Halkidiki Bot', 'Σφάλμα δικτύου. Δοκίμασε ξανά.', false, true);
            } finally {
                isSending = false;
                button.disabled = false;
            }
        }

        button.addEventListener('click', sendMessage);
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                sendMessage();
            }
        });
    })();
		document.querySelectorAll('.halkidiki-ai-quick').forEach(btn => {
    btn.addEventListener('click', function() {
        const msg = this.getAttribute('data-msg');
        document.getElementById('halkidiki-ai-user-input').value = msg;
        document.getElementById('halkidiki-ai-send-btn').click();
    });
});
    </script>
    <?php
    return ob_get_clean();
}

add_action('admin_menu', function () {
    add_menu_page(
        'Halkidiki AI Analytics',
        'Halkidiki AI Analytics',
        'manage_options',
        'halkidiki-ai-analytics',
        'halkidiki_ai_render_analytics_page',
        'dashicons-chart-bar',
        25
    );
});
/**
analytics
**/
function halkidiki_ai_export_logs_csv() {
    if (!is_admin()) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    if (!isset($_GET['page']) || $_GET['page'] !== 'halkidiki-ai-analytics') {
        return;
    }

    if (!isset($_GET['halkidiki_export_csv']) || $_GET['halkidiki_export_csv'] !== '1') {
        return;
    }

    global $wpdb;

    $table_name = $wpdb->prefix . 'halkidiki_ai_logs';
    $rows = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY created_at DESC", ARRAY_A);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=halkidiki-ai-analytics.csv');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
	$output = fopen('php://output', 'w');

    fputcsv($output, [
        'created_at',
        'user_message',
        'detected_region',
        'detected_intent',
        'shown_businesses'
    ]);

    if (!empty($rows)) {
        foreach ($rows as $row) {
            $businesses = json_decode($row['shown_businesses'], true);

            if (!is_array($businesses)) {
                $businesses = [];
            }

            fputcsv($output, [
                $row['created_at'],
                $row['user_message'],
                $row['detected_region'],
                $row['detected_intent'],
                implode(', ', $businesses),
            ]);
        }
    }

    fclose($output);
    exit;
}
add_action('admin_init', 'halkidiki_ai_export_logs_csv');


function halkidiki_ai_render_analytics_page() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'halkidiki_ai_logs';
    $rows = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT 200");

	$total_chats = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");

$last_7_days = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_name} WHERE created_at >= %s",
        date('Y-m-d H:i:s', strtotime('-7 days'))
    )
);

$last_30_days = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_name} WHERE created_at >= %s",
        date('Y-m-d H:i:s', strtotime('-30 days'))
    )
);

	$top_intents = $wpdb->get_results("
    SELECT detected_intent, COUNT(*) as total
    FROM {$table_name}
    WHERE detected_intent <> ''
    GROUP BY detected_intent
    ORDER BY total DESC
    LIMIT 10
");

$top_regions = $wpdb->get_results("
    SELECT detected_region, COUNT(*) as total
    FROM {$table_name}
    WHERE detected_region <> ''
    GROUP BY detected_region
    ORDER BY total DESC
    LIMIT 10
");

$business_counts = [];
$all_rows_for_businesses = $wpdb->get_results("SELECT shown_businesses FROM {$table_name}");

if (!empty($all_rows_for_businesses)) {
    foreach ($all_rows_for_businesses as $log_row) {
        $items = json_decode($log_row->shown_businesses, true);
        if (!is_array($items)) {
            continue;
        }

        foreach ($items as $business_name) {
            if (empty($business_name)) {
                continue;
            }

            if (!isset($business_counts[$business_name])) {
                $business_counts[$business_name] = 0;
            }

            $business_counts[$business_name]++;
        }
    }
}

arsort($business_counts);
$top_businesses = array_slice($business_counts, 0, 10, true);

    echo '<div class="wrap">';

    echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">';
	echo '<div>';
	echo '<h1 style="margin-bottom:8px;">Halkidiki AI Analytics</h1>';
	echo '<p style="margin-top:0;">Τελευταίες ερωτήσεις και εμφανίσεις επιχειρήσεων από το AI chatbot.</p>';
	echo '</div>';
	echo '<div>';
	echo '<a href="' . esc_url(admin_url('admin.php?page=halkidiki-ai-analytics&halkidiki_export_csv=1')) . '" class="button button-primary">Export CSV</a>';
	echo '</div>';
	echo '</div>';


	echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;margin:24px 0;">';

	echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px;">';
	echo '<div style="font-size:13px;color:#6b7280;margin-bottom:8px;">Συνολικά Chats</div>';
	echo '<div style="font-size:30px;font-weight:700;line-height:1;">' . intval($total_chats) . '</div>';
	echo '</div>';

	echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px;">';
	echo '<div style="font-size:13px;color:#6b7280;margin-bottom:8px;">Τελευταίες 7 ημέρες</div>';
	echo '<div style="font-size:30px;font-weight:700;line-height:1;">' . intval($last_7_days) . '</div>';
	echo '</div>';

	echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px;">';
	echo '<div style="font-size:13px;color:#6b7280;margin-bottom:8px;">Τελευταίες 30 ημέρες</div>';
	echo '<div style="font-size:30px;font-weight:700;line-height:1;">' . intval($last_30_days) . '</div>';
	echo '</div>';

	echo '</div>';

	echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px;margin:24px 0;">';

echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px;">';
echo '<h2 style="margin-top:0;font-size:18px;">Top Intents</h2>';
if (!empty($top_intents)) {
    echo '<ul style="margin:0;padding-left:18px;">';
    foreach ($top_intents as $item) {
        echo '<li>' . esc_html($item->detected_intent) . ' (' . intval($item->total) . ')</li>';
    }
    echo '</ul>';
} else {
    echo '<p>Δεν υπάρχουν ακόμη δεδομένα.</p>';
}
echo '</div>';

echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px;">';
echo '<h2 style="margin-top:0;font-size:18px;">Top Regions</h2>';
if (!empty($top_regions)) {
    echo '<ul style="margin:0;padding-left:18px;">';
    foreach ($top_regions as $item) {
        echo '<li>' . esc_html($item->detected_region) . ' (' . intval($item->total) . ')</li>';
    }
    echo '</ul>';
} else {
    echo '<p>Δεν υπάρχουν ακόμη δεδομένα.</p>';
}
echo '</div>';

echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px;">';
echo '<h2 style="margin-top:0;font-size:18px;">Top Businesses Shown</h2>';
if (!empty($top_businesses)) {
    echo '<ul style="margin:0;padding-left:18px;">';
    foreach ($top_businesses as $name => $count) {
        echo '<li>' . esc_html($name) . ' (' . intval($count) . ')</li>';
    }
    echo '</ul>';
} else {
    echo '<p>Δεν υπάρχουν ακόμη δεδομένα.</p>';
}
echo '</div>';

echo '</div>';

    echo '<table class="widefat striped" style="margin-top:20px;">';
    echo '<thead><tr>
        <th>Ημερομηνία</th>
        <th>Μήνυμα Χρήστη</th>
        <th>Περιοχή</th>
        <th>Intent</th>
        <th>Businesses</th>
    </tr></thead>';
    echo '<tbody>';

    if (!empty($rows)) {
        foreach ($rows as $row) {
            $businesses = json_decode($row->shown_businesses, true);
            if (!is_array($businesses)) {
                $businesses = [];
            }

            echo '<tr>';
            echo '<td>' . esc_html($row->created_at) . '</td>';
            echo '<td>' . esc_html($row->user_message) . '</td>';
            echo '<td>' . esc_html($row->detected_region) . '</td>';
            echo '<td>' . esc_html($row->detected_intent) . '</td>';
            echo '<td>' . esc_html(implode(', ', $businesses)) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="5">Δεν υπάρχουν ακόμη δεδομένα.</td></tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
}
