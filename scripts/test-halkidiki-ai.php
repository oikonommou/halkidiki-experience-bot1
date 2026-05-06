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
function get_transient(){ return false; }
function set_transient(){ return true; }
function post_type_exists(){ return false; }
function get_object_taxonomies(){ return []; }
function taxonomy_exists(){ return true; }
class WP_Query { public $posts=[]; public function __construct($a=[]) { $this->posts=[]; } }
function get_the_title(){ return ''; }
function get_permalink(){ return ''; }
function get_the_excerpt(){ return ''; }
function get_post_field(){ return ''; }
function wp_get_post_terms(){ return []; }
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

echo "\nCategory/content tests:\n";
$mock = [
 ['name'=>'BAKALIS RESTAURANT','categories'=>['Εστιατόρια - Ταβέρνες'],'features'=>[],'description'=>''],
 ['name'=>'Bistro Greek Food','categories'=>['Εστιατόρια - Ταβέρνες'],'features'=>[],'description'=>'To Bistro Greek Food στο Πευκοχώρι Χαλκιδικής είναι ένα αυθεντικό ελληνικό εστιατόριο...'],
 ['name'=>'PI&FI','categories'=>['Fast Food'],'features'=>[],'description'=>''],
 ['name'=>'Crepe Cartel','categories'=>['Brunch','Cafe-Snacks','Snack Bar'],'features'=>[],'description'=>''],
 ['name'=>'Crescendo Creperie','categories'=>['Cafe-Snacks','Snack Bar'],'features'=>[],'description'=>''],
 ['name'=>'To Meraki Mas','categories'=>['Brunch','Cafe & Cocktail'],'features'=>[],'description'=>''],
 ['name'=>'Oceanides Seafood Restaurant','categories'=>['Εστιατόρια - Ταβέρνες'],'features'=>[],'description'=>''],
 ['name'=>'Orizontas Bar & Kitchen','categories'=>['Cafe & Cocktail'],'features'=>[],'description'=>'ORIZONTAS BAR & KITCHEN ιδανική επιλογή για ποτό στην Άφυτο.'],
];

$intentCoffee = halkidiki_ai_detect_intent_clean('καφε που να πιω στο πευκοχωρι');
$coffee = halkidiki_ai_filter_businesses_by_intent($mock, $intentCoffee);
echo (count($coffee) > 0 ? "PASS" : "FAIL") . " | coffee has options\n";

$intentDrink = halkidiki_ai_detect_intent_clean('Θέλω να πιω ποτό στην Άφυτο');
$drink = halkidiki_ai_filter_businesses_by_intent($mock, $intentDrink);
$drinkNames = array_map(fn($i)=>$i['name'],$drink);
echo (in_array('Orizontas Bar & Kitchen',$drinkNames,true) ? "PASS" : "FAIL") . " | drink includes Orizontas\n";
echo (!in_array('Oceanides Seafood Restaurant',$drinkNames,true) ? "PASS" : "FAIL") . " | drink excludes Oceanides\n";

$intentFood = halkidiki_ai_detect_intent_clean('θέλω να φάω κάτι στην Άφυτο');
$food = halkidiki_ai_filter_businesses_by_intent($mock, $intentFood);
$foodNames = array_map(fn($i)=>$i['name'],$food);
echo (in_array('Oceanides Seafood Restaurant',$foodNames,true) ? "PASS" : "FAIL") . " | food includes Oceanides\n";

$intentDessert = halkidiki_ai_detect_intent_clean('θέλω να φάω μια κρέπα στο Πευκοχώρι');
$dess = halkidiki_ai_filter_businesses_by_intent($mock, $intentDessert);
$dessNames = array_map(fn($i)=>$i['name'],$dess);
echo ((in_array('Crepe Cartel',$dessNames,true)||in_array('Crescendo Creperie',$dessNames,true)) ? "PASS" : "FAIL") . " | dessert includes crepe options\n";

$clean = halkidiki_ai_clean_business_description($mock[1], 'food', 'Πευκοχώρι');
echo (strpos($clean, 'To Bistro Greek Food') === false ? "PASS" : "FAIL") . " | description cleanup removes duplicate name\n";

$plan = halkidiki_ai_build_planner_reply_clean('πρόγραμμα στο Πευκοχώρι για παραλία φαγητό βόλτα και βραδινή έξοδο');
$planText = $plan['reply'] ?? '';
echo ((strpos($planText,'Πρωί:')!==false && strpos($planText,'Μεσημέρι:')!==false && strpos($planText,'Απόγευμα:')!==false && strpos($planText,'Βράδυ:')!==false) ? "PASS" : "FAIL") . " | planner structure has day parts\n";
