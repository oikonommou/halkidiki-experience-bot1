<?php
/**
 * Plugin Name: SKG AI Planner
 * Description: Safe AI trip planner for SKG Experience.
 * Version: 1.0.0
 * Author: SKG Experience
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * -------------------------------------------------------
 * 1. SETTINGS
 * -------------------------------------------------------
 */

if (!defined('SKG_DEEPSEEK_API_KEY')) {
    define('SKG_DEEPSEEK_API_KEY', 'sk-d630fb971b5a4d7fa4c728b948af1998');
}

if (!defined('SKG_AI_PLUGIN_DIR')) {
    define('SKG_AI_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (!defined('SKG_AI_PLUGIN_URL')) {
    define('SKG_AI_PLUGIN_URL', plugin_dir_url(__FILE__));
}

/**
analytics
**/
register_activation_hook(__FILE__, 'skg_ai_create_logs_table');

function skg_ai_create_logs_table() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'skg_ai_logs';
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

function skg_ai_get_dataset() {
    $file = SKG_AI_PLUGIN_DIR . 'data/skg-places.json';

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

function skg_ai_normalize_text($text) {
    $text = wp_strip_all_tags($text);
    $text = trim($text);
    return mb_strtolower($text, 'UTF-8');
}

function skg_ai_limit_history($history, $max = 6) {
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

function skg_ai_get_client_key() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return 'skg_ai_rate_' . md5($ip);
}

function skg_ai_is_rate_limited() {
    $key = skg_ai_get_client_key();
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

function skg_ai_make_cache_key($prefix, $value) {
    return 'skg_ai_' . $prefix . '_' . md5(wp_json_encode($value));
}

function skg_ai_get_cached($key) {
    return get_transient($key);
}

function skg_ai_set_cached($key, $value, $seconds = 600) {
    set_transient($key, $value, $seconds);
}

/**
analytics
**/

function skg_ai_cleanup_logs() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'skg_ai_logs';

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

function skg_ai_log_interaction($user_message, $business_data = []) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'skg_ai_logs';

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
		skg_ai_cleanup_logs();
	}
}

/**
 * -------------------------------------------------------
 * LISTEO BUSINESS DETECTION + FILTERING
 * -------------------------------------------------------
 */

function skg_ai_get_listing_post_type() {
    $candidates = ['listing', 'job_listing'];

    foreach ($candidates as $candidate) {
        if (post_type_exists($candidate)) {
            return $candidate;
        }
    }

    return 'listing';
}

function skg_ai_get_listing_taxonomies() {
    $post_type = skg_ai_get_listing_post_type();
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

function skg_ai_get_taxonomy_terms_map($taxonomy) {
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
            'norm'    => skg_ai_normalize_text($term->name . ' ' . $term->slug),
        ];
    }

    return $map;
}

function skg_ai_detect_region_from_message($message, $regions_map) {
    $normalized = skg_ai_normalize_text($message);

    foreach ($regions_map as $region) {
        if (!empty($region['norm']) && strpos($normalized, $region['norm']) !== false) {
            return $region;
        }

        if (!empty($region['name']) && strpos($normalized, skg_ai_normalize_text($region['name'])) !== false) {
            return $region;
        }
    }

    $aliases = [
        'κεντρο' => 'Κέντρο',
        'κέντρο' => 'Κέντρο',
        'kentro' => 'Κέντρο',
        'καλαμαρια' => 'Καλαμαριά',
        'καλαμαριά' => 'Καλαμαριά',
        'kalamaria' => 'Καλαμαριά',
        'ανω πολη' => 'Άνω Πόλη',
        'άνω πόλη' => 'Άνω Πόλη',
        'ano poli' => 'Άνω Πόλη',
        'ladadika' => 'Λαδάδικα',
        'λαδαδικα' => 'Λαδάδικα',
        'λαδάδικα' => 'Λαδάδικα',
    ];

    foreach ($aliases as $needle => $target_name) {
        if (strpos($normalized, $needle) !== false) {
            foreach ($regions_map as $region) {
                if (skg_ai_normalize_text($region['name']) === skg_ai_normalize_text($target_name)) {
                    return $region;
                }
            }
        }
    }

    return null;
}

function skg_ai_get_region_clusters() {
    return [

        'Κέντρο' => [
            'Κέντρο',
            'Αγία Σοφία',
            'Αγία Τριάδα',
            'Άγιος Δημήτριος',
            'Άνω Πόλη',
            'Βαρδάρης',
            'Δικαστήρια',
            'Διοικητήριο',
            'Εγνατία',
            'Καμάρα',
            'Καπάνι',
            'Κασσάνδρου',
            'Λαδάδικα',
            'Νέα Παραλία',
            'Ολυμπιάδος',
            'Πλατεία Αριστοτέλους',
            'Ροτόντα',
            'Σαράντα Εκκλησιές',
            'Τριανδρία',
            'Τσιμισκή',
            'Φάληρο'
        ],

        'Ανατολικά' => [
            'Ανατολικά',
            'Ανάληψη',
            'Άνω Τούμπα',
            'Αρετσού',
            'Βούλγαρη',
            'Θέρμη',
            'Καλαμαριά',
            'Κάτω Τούμπα',
            'Νέα Κρήνη',
            'Ντεπώ',
            'Πανόραμα',
            'Πυλαία',
            'Τούμπα',
            'Χαριλάου',
            'Φάληρο',
            'Νέα Παραλία'
        ],

        'Δυτικά' => [
            'Δυτικά',
            'Αμπελόκηποι',
            'Διαβατά',
            'Εύοσμος',
            'Ιωνία',
            'Καλοχώρι',
            'Κορδελιό',
            'Μενεμένη',
            'Νεάπολη',
            'Πολίχνη',
            'Σίνδος',
            'Σταυρούπολη',
            'Συκιές',
            'Ωραιόκαστρο'
        ],

        'Καλαμαριά' => [
            'Καλαμαριά',
            'Αρετσού',
            'Νέα Κρήνη',
            'Φάληρο',
            'Νέα Παραλία',
            'Ανατολικά'
        ],

        'Φάληρο' => [
            'Φάληρο',
            'Νέα Παραλία',
            'Αγία Τριάδα',
            'Καλαμαριά',
            'Κέντρο',
            'Ανατολικά'
        ],

        'Άνω Πόλη' => [
            'Άνω Πόλη',
            'Άγιος Δημήτριος',
            'Ολυμπιάδος',
            'Κασσάνδρου',
            'Ροτόντα',
            'Καμάρα',
            'Κέντρο'
        ],

        'Τούμπα' => [
            'Τούμπα',
            'Άνω Τούμπα',
            'Κάτω Τούμπα',
            'Χαριλάου',
            'Ντεπώ',
            'Ανατολικά'
        ],

        'Πανόραμα' => [
            'Πανόραμα',
            'Θέρμη',
            'Πυλαία',
            'Ανατολικά'
        ],

        'Θέρμη' => [
            'Θέρμη',
            'Πανόραμα',
            'Πυλαία',
            'Ανατολικά'
        ],

        'Λαδάδικα' => [
            'Λαδάδικα',
            'Βαρδάρης',
            'Πλατεία Αριστοτέλους',
            'Τσιμισκή',
            'Κέντρο'
        ],

        'Νέα Παραλία' => [
            'Νέα Παραλία',
            'Φάληρο',
            'Καλαμαριά',
            'Αγία Τριάδα',
            'Κέντρο'
        ],
    ];
}

function skg_ai_get_nearby_region_names($region_name) {
    $clusters = skg_ai_get_region_clusters();

    foreach ($clusters as $cluster_name => $items) {
        if (in_array($region_name, $items, true) || $cluster_name === $region_name) {
            return $items;
        }
    }

    return [$region_name];
}

function skg_ai_get_category_families() {
    return [

        'food' => [
            'Brunch',
            'Cafe-Snacks',
            'Delicatessen',
            'Fast Food',
            'Pizza & Pasta',
            'Snack Bar',
            'Άρτος & Γλυκό',
            'Εστιατόρια – Ταβέρνες',
            'Ιχθυοπωλείο',
            'Παγωτό'
        ],

        'dessert' => [
            'Άρτος & Γλυκό',
            'Παγωτό',
            'Cafe-Snacks'
        ],

        'coffee' => [
            'Brunch',
            'Cafe-Snacks',
            'Cafe Bars',
            'Cafe & Cocktail'
        ],

        'drink' => [
            'Cafe & Cocktail',
            'Cafe Bars',
            'Clubs'
        ],

        'icecream' => [
            'Παγωτό',
            'Άρτος & Γλυκό',
            'Cafe-Snacks'
        ],

        'seafood' => [
            'Ιχθυοπωλείο',
            'Εστιατόρια – Ταβέρνες'
        ],

        'activities' => [
            'Daily Cruises',
            'Gyms',
            'Horse Riding',
            'Kart & Go',
            'Kids Club',
            'Physiotherapy & Massage',
            'Rent a Car-Moto-Bike'
        ],

        'stay' => [
            'Apartments',
            'Hotels',
            'Luxury Suites',
            'studios',
            'Villas'
        ],

        'shopping' => [
            'Beauty & Hair',
            'Cannabis Shop',
            'Cleaning service',
            'Real Estate',
            'Service car-moto-boat',
            'Αξεσουάρ - Σουβενίρ',
            'Ένδυση - Υπόδυση',
            'Κοσμήματα',
            'Οικιακά είδη',
            'Οπτικά',
            'Συντήρηση Κήπου',
            'Συστήματα Αλουμινίου',
            'Συστήματα σκίασης',
            'Υλικά οικοδομών'
        ],
    ];
}

function skg_ai_detect_business_intent($message) {
    $normalized = skg_ai_normalize_text($message);
    $fam = skg_ai_get_category_families();

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
        strpos($normalized, 'dessert') !== false ||
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
		$intent['category_keywords'] = ['Cafe & Cocktail', 'Cafe Bars', 'Clubs'];
		$intent['feature_keywords'] = ['Cafe & Cocktail', 'Cafe Bars', 'Clubs'];
		return $intent;
	}
	
	if (
        strpos($normalized, 'μπαρ') !== false ||
        strpos($normalized, 'bar') !== false ||
        strpos($normalized, 'ποτο') !== false ||
        strpos($normalized, 'ποτό') !== false ||
        strpos($normalized, 'cocktail') !== false
    ) {
        $intent['type'] = 'drink';
        $intent['category_keywords'] = $fam['drink'];
        $intent['feature_keywords'] = ['Cafe & Cocktail', 'Cafe Bars', 'Clubs'];
        return $intent;
    }

    if (
        strpos($normalized, 'club') !== false ||
        strpos($normalized, 'κλαμπ') !== false ||
        strpos($normalized, 'clubs') !== false
    ) {
        $intent['type'] = 'club';
        $intent['category_keywords'] = ['Clubs', 'Cafe Bars', 'Cafe & Cocktail'];
        $intent['feature_keywords'] = ['Clubs', 'Cafe Bars'];
        return $intent;
    }

    if (
        strpos($normalized, 'καφε') !== false ||
        strpos($normalized, 'καφέ') !== false ||
        strpos($normalized, 'cafe') !== false ||
        strpos($normalized, 'coffee') !== false
    ) {
        $intent['type'] = 'coffee';
        $intent['category_keywords'] = $fam['coffee'];
        $intent['feature_keywords'] = ['Cafe Bars'];
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
        strpos($normalized, 'bike') !== false
    ) {
        $intent['type'] = 'rent';
        $intent['category_keywords'] = ['Rent a Car-Moto-Bike'];
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
        strpos($normalized, 'activity') !== false
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
function skg_ai_find_matching_term_ids($terms_map, $keywords) {
    $matches = [];

    if (empty($terms_map) || empty($keywords)) {
        return $matches;
    }

    foreach ($terms_map as $term) {
        foreach ($keywords as $keyword) {
            $kw = skg_ai_normalize_text($keyword);

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

function skg_ai_get_filtered_businesses($message) {
    $post_type = skg_ai_get_listing_post_type();
    $taxes = skg_ai_get_listing_taxonomies();
	
	$business_cache_key = skg_ai_make_cache_key('businesses', $message);
	$cached_businesses = skg_ai_get_cached($business_cache_key);

	if ($cached_businesses !== false && is_array($cached_businesses)) {
    return $cached_businesses;
	}

    $region_map = skg_ai_get_taxonomy_terms_map($taxes['region']);
    $category_map = skg_ai_get_taxonomy_terms_map($taxes['category']);
    $feature_map = skg_ai_get_taxonomy_terms_map($taxes['feature']);

    $detected_region = skg_ai_detect_region_from_message($message, $region_map);
    $intent = skg_ai_detect_business_intent($message);

    $tax_query = [];

    if (!empty($detected_region['name']) && !empty($taxes['region'])) {
    $nearby_region_names = skg_ai_get_nearby_region_names($detected_region['name']);
    $nearby_term_ids = [];

    foreach ($region_map as $region_item) {
        foreach ($nearby_region_names as $nearby_name) {
            if (skg_ai_normalize_text($region_item['name']) === skg_ai_normalize_text($nearby_name)) {
                $nearby_term_ids[] = (int) $region_item['term_id'];
            }
        }
    }

    $nearby_term_ids = array_values(array_unique($nearby_term_ids));

    if (!empty($nearby_term_ids)) {
        $tax_query[] = [
            'taxonomy' => $taxes['region'],
            'field'    => 'term_id',
            'terms'    => $nearby_term_ids,
        ];
    }
}

    $category_term_ids = skg_ai_find_matching_term_ids($category_map, $intent['category_keywords']);
    if (!empty($category_term_ids) && !empty($taxes['category'])) {
        $tax_query[] = [
            'taxonomy' => $taxes['category'],
            'field'    => 'term_id',
            'terms'    => $category_term_ids,
        ];
    }

    $feature_term_ids = skg_ai_find_matching_term_ids($feature_map, $intent['feature_keywords']);
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

    $args = [
    'post_type'              => $post_type,
    'post_status'            => 'publish',
    'posts_per_page'         => 12,
    'no_found_rows'          => true,
    'ignore_sticky_posts'    => true,
    'orderby'                => 'rand',
    'fields'                 => 'ids',
    'update_post_meta_cache' => false,
    'update_post_term_cache' => false,
	];

    if (!empty($tax_query)) {
        $args['tax_query'] = $tax_query;
    }

    $query = new WP_Query($args);

    $results = [];

    if (!empty($query->posts)) {
    foreach ($query->posts as $post_id) {
        $title = get_the_title($post_id);
        $link = get_permalink($post_id);

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

            $results[] = [
                'name'       => $title,
                'link'       => $link,
                'categories' => $categories,
                'regions'    => $regions,
                'features'   => $features,
            ];
        }
    }

    $final_data = [
    'businesses' => $results,
    'detected_region' => $detected_region ? $detected_region['name'] : '',
    'detected_intent' => $intent['type'],
	];

	skg_ai_set_cached($business_cache_key, $final_data, 600);

	return $final_data;
	}

function skg_ai_build_businesses_text($message) {
    $data = skg_ai_get_filtered_businesses($message);
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
    }

    if (!empty($data['detected_intent'])) {
        $lines[] = "Detected business intent: " . $data['detected_intent'];
    }
	
	foreach ($businesses as $business) {
        $cat = !empty($business['categories']) ? implode(', ', $business['categories']) : 'N/A';
        $reg = !empty($business['regions']) ? implode(', ', $business['regions']) : 'N/A';
        $feat = !empty($business['features']) ? implode(', ', $business['features']) : 'N/A';

        $lines[] = "- {$business['name']} | Categories: {$cat} | Regions: {$reg} | Features: {$feat} | Link: {$business['link']}";
    }

    return implode("\n", $lines);
}

function skg_ai_is_business_request($message, $business_data = []) {
    $normalized = skg_ai_normalize_text($message);

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
        if (strpos($normalized, skg_ai_normalize_text($kw)) !== false) {
            return true;
        }
    }

    if (!empty($business_data['detected_intent']) && $business_data['detected_intent'] !== 'general') {
        return true;
    }

    return false;
}

function skg_ai_get_allowed_business_names($businesses) {
    $allowed = [];

    if (!is_array($businesses)) {
        return $allowed;
    }

    foreach ($businesses as $business) {
        if (!empty($business['name'])) {
            $name = trim(wp_strip_all_tags($business['name']));
            if ($name !== '') {
                $allowed[$name] = skg_ai_normalize_text($name);
            }
        }
    }

    return $allowed;
}

function skg_ai_extract_mentioned_allowed_businesses($reply, $allowed_businesses) {
    $mentioned = [];

    if (empty($reply) || empty($allowed_businesses)) {
        return $mentioned;
    }

    $reply_norm = skg_ai_normalize_text(wp_strip_all_tags($reply));

    foreach ($allowed_businesses as $original => $norm) {
        if ($norm !== '' && strpos($reply_norm, $norm) !== false) {
            $mentioned[] = $original;
        }
    }

    return array_values(array_unique($mentioned));
}

function skg_ai_build_partner_only_fallback_reply($message, $business_data = []) {
    $businesses = $business_data['businesses'] ?? [];
    $intent = $business_data['detected_intent'] ?? '';
    $region = $business_data['detected_region'] ?? '';

    if (empty($businesses)) {
        $text = 'Για αυτό που ζητάς';

        if (!empty($region)) {
            $text .= ' στην περιοχή ' . $region;
        }

        $text .= ' δεν βρήκα αυτή τη στιγμή κάποια πολύ ταιριαστή επιλογή.';

        $text .= ' Αν θέλεις, μπορώ να σου προτείνω κοντινή περιοχή ή να το ψάξω με πιο συγκεκριμένο στιλ, όπως πιο χαλαρό, πιο βραδινό ή πιο κεντρικό.';

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
        return 'Πες μου λίγο πιο συγκεκριμένα τι ψάχνεις και θα σου προτείνω κάτι πιο ταιριαστό.';
    }

    $prefix = 'Μπορείς να δεις';

    if (!empty($region)) {
        $prefix .= ' στην περιοχή ' . $region;
    }

    $prefix .= ': ';

    $text = $prefix . implode(', ', $parts) . '.';

    if ($intent === 'nightlife' || $intent === 'drink' || $intent === 'club') {
        $text .= ' Αν θέλεις, μπορώ να σου προτείνω και κάτι πιο συγκεκριμένο για ποτό, cocktail ή βραδινή έξοδο.';
    } elseif ($intent === 'food' || $intent === 'burger' || $intent === 'pizza' || $intent === 'seafood' || $intent === 'brunch') {
        $text .= ' Αν θέλεις, μπορώ να σου δώσω και πιο στοχευμένη πρόταση ανάλογα με το τι στιλ φαγητού προτιμάς.';
    } elseif ($intent === 'coffee' || $intent === 'dessert' || $intent === 'icecream') {
        $text .= ' Αν θέλεις, μπορώ να το κάνω και πιο συγκεκριμένο ανάλογα με το vibe που ψάχνεις.';
    }

    return $text;
}

function skg_ai_enforce_partner_only_reply($reply, $message, $business_data = []) {
    if (!skg_ai_is_business_request($message, $business_data)) {
        return $reply;
    }

    $businesses = $business_data['businesses'] ?? [];
    $allowed = skg_ai_get_allowed_business_names($businesses);
    $mentioned_allowed = skg_ai_extract_mentioned_allowed_businesses($reply, $allowed);

    if (empty($mentioned_allowed)) {
        return skg_ai_build_partner_only_fallback_reply($message, $business_data);
    }

    return $reply;
}

/**
 * -------------------------------------------------------
 * 4. DATASET TO PROMPT
 * -------------------------------------------------------
 */

function skg_ai_build_dataset_text($dataset) {
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
        'churches_monuments' => 'CHURCHES AND MONUMENTS',
        'museums' => 'MUSEUMS',
        'top_areas' => 'TOP AREAS',
        'top_landscapes' => 'TOP LANDSCAPES',
        'day_trips' => 'DAY TRIPS',
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

    if (isset($dataset['events']) && empty($dataset['events'])) {
        $lines[] = "EVENTS: No data available yet.";
    }

    return implode("\n", $lines);
}

/**
 * -------------------------------------------------------
 * 5. DEEPSEEK REQUEST
 * -------------------------------------------------------
 */

function skg_ai_call_deepseek($user_message, $history = []) {
    if (empty(SKG_DEEPSEEK_API_KEY)) {
        return 'Δεν έχει οριστεί ακόμη το DeepSeek API key στο wp-config.php.';
    }
	
	$cache_payload = [
    'message' => $user_message,
    'history' => skg_ai_limit_history($history, 6),
	];

	$ai_cache_key = skg_ai_make_cache_key('reply', $cache_payload);
	$cached_reply = skg_ai_get_cached($ai_cache_key);

	if ($cached_reply !== false && is_string($cached_reply)) {
    return $cached_reply;
	}

    $dataset = skg_ai_get_dataset();
	$dataset_text = skg_ai_build_dataset_text($dataset);
	$business_data = skg_ai_get_filtered_businesses($user_message);
	$businesses_text = skg_ai_build_businesses_text($user_message);

    $system_prompt = <<<EOT
You are the official AI guide and assistant for SKG Experience in Thessaloniki.

Your role is to help users discover Thessaloniki using the SKG Experience dataset and the filtered partner businesses.

You can act as:
- a local travel guide
- a business recommendation assistant
- a city discovery assistant
- a travel planner only when the user asks for a plan

RULES:
1. Reply in the same language as the user.
2. Use ONLY the provided SKG dataset and the filtered partner businesses.
3. Do NOT invent places, businesses, events, or facts.
4. Follow the user's request exactly.
5. If the user asks only for morning suggestions, respond ONLY with morning suggestions.
6. If the user asks only for one part of the day, answer ONLY for that part.
7. Do NOT create a full-day plan unless the user explicitly asks for a full-day plan.
8. If the user asks a simple question, give a simple direct answer. Do not force itinerary format unless requested.
9. If the user asks about food, drinks, cafes, restaurants, bars, clubs, desserts, ice cream, brunch, or similar topics, prioritize filtered partner businesses.
10. When mentioning partner businesses, write their names exactly as provided in the filtered partner businesses list.
11. If the user asks for a specific type of place, suggest 1 to 3 relevant partner businesses if available.
12. If no exact matching partner business exists, do not stop there. Use nearby relevant partner businesses when available.
13. If no partner business exists at all for that request, say it politely and then provide a helpful alternative using the SKG dataset when possible.
14. For attractions, museums, churches, monuments, areas, landscapes, and sightseeing suggestions, use the SKG dataset.
15. For hospital requests, prioritize emergency/general hospitals suitable for urgent incidents.
16. Prefer partner businesses whenever relevant.
17. When multiple partner businesses are available, vary the suggestions when possible and do not always prioritize the same one.
18. Keep the response practical, natural, elegant, and concise.
19. Use a warm, welcoming, and refined tone, suitable for a premium local guide.
20. Avoid negative openings like "Unfortunately" unless absolutely necessary.
21. When no exact area match exists but a nearby partner business is available, clearly present it as a nearby option.
22. Do not use markdown formatting.
23. Never use **, *, #, or bullet styling in your answers.
24. Do not include raw URLs unless explicitly needed.
25.Stay within the scope of Thessaloniki and the SKG Experience platform.
26. In Greek, always address the user politely in plural form.
27. Avoid imperative style in Greek. Prefer natural, elegant, welcoming phrasing.
28. For any business-related request, mention ONLY business names that exist in the FILTERED PARTNER BUSINESSES section.
29. Never invent, guess, or mention any business name outside the FILTERED PARTNER BUSINESSES section.
30. If no valid partner business is available for the exact request, say so clearly and do not mention non-partner businesses.
31. If the user asks where to eat, drink, stay, shop, or book, you must stay restricted to partner listings only.
32. Do not provide generic business suggestions without naming valid partner listings from the filtered list.

AVAILABLE SKG DATA:
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

    $history = skg_ai_limit_history($history, 6);
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
        'max_tokens' => 500,
    ];

    $response = wp_remote_post('https://api.deepseek.com/chat/completions', [
        'timeout' => 45,
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . SKG_DEEPSEEK_API_KEY,
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
    return 'Σφάλμα DeepSeek HTTP ' . $status_code . ': ' . $response_body;
	}

    if (!isset($data['choices'][0]['message']['content'])) {
        return 'Δεν βρέθηκε απάντηση από το AI.';
    }

    $reply = trim(wp_strip_all_tags($data['choices'][0]['message']['content']));
	$reply = str_replace(['**', '*', '##', '#'], '', $reply);
	$reply = preg_replace('/\s+/', ' ', $reply);
	$reply = trim($reply);

	$reply = skg_ai_enforce_partner_only_reply($reply, $user_message, $business_data);

	skg_ai_set_cached($ai_cache_key, $reply, 900);

	return $reply;
	}

/**
 * -------------------------------------------------------
 * 6. REST ENDPOINT
 * -------------------------------------------------------
 */

add_action('rest_api_init', function () {
    register_rest_route('skg-ai/v1', '/chat', [
        'methods' => 'POST',
        'callback' => 'skg_ai_chat_endpoint',
        'permission_callback' => '__return_true',
    ]);
});

function skg_ai_chat_endpoint(WP_REST_Request $request) {
    if (skg_ai_is_rate_limited()) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Περίμενε λίγα δευτερόλεπτα και ξαναστείλε μήνυμα.'
        ], 429);
    }

    $params = $request->get_json_params();

    $message = isset($params['message']) ? sanitize_textarea_field($params['message']) : '';
    $history = isset($params['history']) ? $params['history'] : [];

    if (empty($message)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Δεν βρέθηκε μήνυμα.'
        ], 400);
    }

    $reply = skg_ai_call_deepseek($message, $history);
	$business_data = skg_ai_get_filtered_businesses($message);

	$business_cards = [];
	if (!empty($business_data['businesses']) && is_array($business_data['businesses'])) {
    	$business_cards = array_slice($business_data['businesses'], 0, 3);
	}
	
	skg_ai_log_interaction($message, $business_data);
	
	return new WP_REST_Response([
    'success' => true,
    'reply' => $reply,
    'businesses' => $business_cards,
	], 200);
}

/**
 * -------------------------------------------------------
 * 7. SHORTCODE UI
 * -------------------------------------------------------
 */

add_shortcode('skg_ai_planner', 'skg_ai_planner_shortcode');

function skg_ai_planner_shortcode() {
    ob_start();
    ?>
    <div id="skg-ai-planner" style="max-width: 900px; margin: 40px auto; font-family: inherit;">
    <div style="background: linear-gradient(135deg, #1F9AA5 0%, #167C85 100%); color: #fff; border-radius: 22px 22px 0 0; padding: 28px 28px 22px 28px; box-shadow: 0 10px 35px rgba(0,0,0,0.10);">
        <div style="font-size: 13px; letter-spacing: 1px; text-transform: uppercase; opacity: 0.75; margin-bottom: 10px;">
            SKG Experience
        </div>
        <h2 style="margin: 0 0 10px 0; font-size: 30px; line-height: 1.2; color: #fff;">
            AI Travel Planner
        </h2>
        <p style="margin: 0; color: rgba(255,255,255,0.82); font-size: 16px; line-height: 1.6; max-width: 720px;">
            Ζήτησε πρόγραμμα για τη Θεσσαλονίκη με βάση περιοχή, ώρα της ημέρας, αξιοθέατα και συνεργαζόμενα μαγαζιά.
        </p>
    </div>

    <div style="border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 22px 22px; background: #ffffff; box-shadow: 0 10px 35px rgba(0,0,0,0.08); overflow: hidden;">
        
        <div style="display:flex; flex-wrap:wrap; gap:10px; padding:18px 20px; background:#f8fafc; border-bottom:1px solid #eef2f7;">

    <button class="skg-ai-quick" data-msg="Θέλω πρωινό πρόγραμμα στο κέντρο">
        Πρωινό στο κέντρο
    </button>

    <button class="skg-ai-quick" data-msg="Θέλω βραδινή έξοδο στο κέντρο με ποτό">
        Βραδινή έξοδος
    </button>

    <button class="skg-ai-quick" data-msg="Θέλω να δω μουσεία στη Θεσσαλονίκη">
        Μουσεία
    </button>

    <button class="skg-ai-quick" data-msg="Θέλω εστιατόριο στο κέντρο">
        Φαγητό στο κέντρο
    </button>

    <button class="skg-ai-quick" data-msg="Θέλω βόλτα στην Καλαμαριά">
        Καλαμαριά
    </button>

</div>

<style>
.skg-ai-quick{
    border:1px solid #e5e7eb;
    background:#fff;
    border-radius:999px;
    padding:8px 14px;
    font-size:13px;
    cursor:pointer;
    color:#374151;
    transition:all .2s ease;
}

.skg-ai-quick:hover{
    background:#1F9AA5;
    color:#fff;
    border-color:#1F9AA5;
}
</style>

        <div id="skg-ai-chat-box" style="height: 500px; overflow-y: auto; padding: 24px; background: #fcfcfd;">
            <div style="display: flex; margin-bottom: 16px;">
                <div style="max-width: 78%; background: #f3f4f6; color: #111827; border-radius: 18px 18px 18px 6px; padding: 14px 16px; line-height: 1.6; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
                    <strong style="display:block; margin-bottom:6px;">SKG Bot</strong>
                    Γεια σας! Μπορώ να σας βοηθήσω με προτάσεις για τη Θεσσαλονίκη, ανάλογα με την περιοχή, την ώρα της ημέρας και το είδος της εμπειρίας που αναζητάτε.
                    <div style="margin-top: 10px; font-size: 13px; color: #6b7280;">
                        Παράδειγμα: Θα ήθελα ένα βραδινό πρόγραμμα στο κέντρο με φαγητό και ποτό.
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
                    id="skg-ai-user-input"
                    placeholder="Π.χ. Θέλω μόνο πρωινό πρόγραμμα στο κέντρο με μουσείο και καφέ"
                    style="flex: 1; padding: 16px 18px; border: 1px solid #d1d5db; border-radius: 14px; background: #fff; font-size: 15px; outline: none; box-shadow: inset 0 1px 2px rgba(0,0,0,0.02);"
                />
                <button
                    type="button"
                    id="skg-ai-send-btn"
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
        const chatBox = document.getElementById('skg-ai-chat-box');
        const input = document.getElementById('skg-ai-user-input');
        const button = document.getElementById('skg-ai-send-btn');
        const endpoint = '<?php echo esc_url(rest_url('skg-ai/v1/chat')); ?>';

        let history = [];
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

    if (businesses && businesses.length) {
        businesses.forEach(business => {
            if (!business.name || !business.link) return;

            const escapedName = business.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const regex = new RegExp(escapedName, 'gi');

            formattedText = formattedText.replace(
                regex,
                '<a href="' + business.link + '" target="_blank" rel="noopener noreferrer" style="color:#b08a3c; text-decoration:underline; font-weight:700;">' + business.name + '</a>'
            );
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
            loading.id = 'skg-ai-loading';
            loading.style.marginBottom = '12px';
            loading.innerHTML = '<div style="display:flex; justify-content:flex-start; margin-bottom:16px;"><div style="max-width:78%; 					background:#f3f4f6; color:#111827; border-radius:18px 18px 18px 6px; padding:14px 16px; line-height:1.6; box-shadow:0 2px 8px 				rgba(0,0,0,0.04);"><strong style="display:block; margin-bottom:6px;">SKG Bot</strong>Σκέφτομαι...</div></div>';
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
                        history: history
                    })
                });

                const data = await response.json();

                const loadingEl = document.getElementById('skg-ai-loading');
                if (loadingEl) loadingEl.remove();

                if (!response.ok || !data.success) {
					appendMessage('SKG Bot', data.message ? data.message : 'Κάτι πήγε στραβά.', false, true);
				} else {
					appendMessage('SKG Bot', data.reply, false, false, data.businesses || []);


					history.push({ role: 'user', content: message });
					history.push({ role: 'assistant', content: data.reply });

					if (history.length > 12) {
						history = history.slice(-12);
					}
				}					
							} catch (error) {
                const loadingEl = document.getElementById('skg-ai-loading');
                if (loadingEl) loadingEl.remove();
                appendMessage('SKG Bot', 'Σφάλμα δικτύου. Δοκίμασε ξανά.', false, true);
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
		document.querySelectorAll('.skg-ai-quick').forEach(btn => {
    btn.addEventListener('click', function() {
        const msg = this.getAttribute('data-msg');
        document.getElementById('skg-ai-user-input').value = msg;
        document.getElementById('skg-ai-send-btn').click();
    });
});
    </script>
    <?php
    return ob_get_clean();
}

add_action('admin_menu', function () {
    add_menu_page(
        'SKG AI Analytics',
        'SKG AI Analytics',
        'manage_options',
        'skg-ai-analytics',
        'skg_ai_render_analytics_page',
        'dashicons-chart-bar',
        25
    );
});
/**
analytics
**/
function skg_ai_export_logs_csv() {
    if (!is_admin()) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    if (!isset($_GET['page']) || $_GET['page'] !== 'skg-ai-analytics') {
        return;
    }

    if (!isset($_GET['skg_export_csv']) || $_GET['skg_export_csv'] !== '1') {
        return;
    }

    global $wpdb;

    $table_name = $wpdb->prefix . 'skg_ai_logs';
    $rows = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY created_at DESC", ARRAY_A);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=skg-ai-analytics.csv');
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
add_action('admin_init', 'skg_ai_export_logs_csv');


function skg_ai_render_analytics_page() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'skg_ai_logs';
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
	echo '<h1 style="margin-bottom:8px;">SKG AI Analytics</h1>';
	echo '<p style="margin-top:0;">Τελευταίες ερωτήσεις και εμφανίσεις επιχειρήσεων από το AI chatbot.</p>';
	echo '</div>';
	echo '<div>';
	echo '<a href="' . esc_url(admin_url('admin.php?page=skg-ai-analytics&skg_export_csv=1')) . '" class="button button-primary">Export 		CSV</a>';
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