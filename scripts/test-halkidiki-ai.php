<?php
// Minimal harness for route-level checks without full WP bootstrap.
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/../');
function add_action() {}
function add_shortcode() {}
function register_rest_route() {}
function register_activation_hook() {}
function add_menu_page() {}
function is_admin(){return false;}
function current_user_can(){return true;}
function admin_url($p=''){return $p;}
function rest_url($p=''){return $p;}
function plugin_dir_path(){ return __DIR__ . '/../'; }
function plugin_dir_url(){ return ''; }
function wp_strip_all_tags($s){ return strip_tags((string)$s); }
function sanitize_text_field($s){ return $s; }
function sanitize_textarea_field($s){ return $s; }
function wp_json_encode($v){ return json_encode($v, JSON_UNESCAPED_UNICODE); }
function wp_trim_words($t,$n,$m='...'){ $a=preg_split('/\s+/u',trim($t)); return count($a)>$n?implode(' ',array_slice($a,0,$n)).$m:$t; }
function post_type_exists(){ return false; }
function get_object_taxonomies(){ return []; }
function taxonomy_exists(){ return true; }
function get_terms(){
    return [
        (object)['term_id'=>1,'name'=>'Πευκοχώρι','slug'=>'pefkochori'],
        (object)['term_id'=>2,'name'=>'Άφυτος','slug'=>'afytos'],
    ];
}

require __DIR__ . '/../halkidiki-ai-planner.php';

$tests = [
    ['ευχαριστώ', [], ['active'=>false], 'smalltalk'],
    ['θέλω να μου κάνεις ένα πρόγραμμα', [], ['active'=>false], 'planner_clarification'],
    ['απο το πευκοχωρι ξεκινω και θελω παραλια φαγητο βολτα και νυχτερινη εξοδο', [], ['active'=>true,'type'=>'day_plan'], 'planner_reply'],
    ['μπορείς να μου κάνεις ένα πρόγραμμα στο Πευκοχώρι', [], ['active'=>false], 'planner_reply'],
    ['κάνε μου πρόγραμμα στην Άφυτο με φαγητό και ποτό', [], ['active'=>false], 'planner_reply'],
    ['Θέλω φαγητο στο πευκοχωρι', [], ['active'=>false], 'business_reply'],
    ['καφε', [], ['active'=>false], 'business_clarification'],
];

$ok = 0;
foreach ($tests as $t) {
    $r = halkidiki_ai_resolve_route_clean($t[0], $t[1], $t[2]);
    $pass = ($r['route'] === $t[3]);
    echo ($pass ? "PASS" : "FAIL") . " | {$t[0]} => {$r['route']} (expected {$t[3]})\n";
    if ($pass) $ok++;
}
echo "Summary: {$ok}/" . count($tests) . " passed\n";

