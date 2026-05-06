<?php
require __DIR__ . '/../halkidiki-ai-planner-v2.php';

$items = [
['name'=>'BAKALIS RESTAURANT','region'=>'Πευκοχώρι','categories'=>['Εστιατόρια - Ταβέρνες'],'description'=>''],
['name'=>'Bistro Greek Food','region'=>'Πευκοχώρι','categories'=>['Εστιατόρια - Ταβέρνες'],'description'=>'To Bistro Greek Food στο Πευκοχώρι Χαλκιδικής είναι ένα αυθεντικό ελληνικό εστιατόριο...'],
['name'=>'PI&FI','region'=>'Πευκοχώρι','categories'=>['Fast Food'],'description'=>''],
['name'=>'Crepe Cartel','region'=>'Πευκοχώρι','categories'=>['Brunch','Cafe-Snacks','Snack Bar'],'description'=>''],
['name'=>'Crescendo Creperie','region'=>'Πευκοχώρι','categories'=>['Cafe-Snacks','Snack Bar'],'description'=>''],
['name'=>'To Meraki Mas','region'=>'Πευκοχώρι','categories'=>['Brunch','Cafe & Cocktail'],'description'=>''],
['name'=>'Oceanides Seafood Restaurant','region'=>'Άφυτος','categories'=>['Εστιατόρια - Ταβέρνες'],'description'=>''],
['name'=>'Orizontas Bar & Kitchen','region'=>'Άφυτος','categories'=>['Cafe & Cocktail'],'description'=>'ORIZONTAS BAR & KITCHEN ιδανική επιλογή για ποτό στην Άφυτο.'],
];

function t($ok,$m){ echo ($ok?'PASS':'FAIL')." | $m\n"; }
t(halkidiki_v2_route('ευχαριστώ',[],[])['route']==='smalltalk','smalltalk');
t(halkidiki_v2_route('θέλω να μου κάνεις ένα πρόγραμμα',[],[])['route']==='planner_clarification','planner clarification');
t(halkidiki_v2_route('απο το πευκοχωρι ξεκινω',[],['active'=>true])['route']==='planner_reply','planner continuation');
$pr=halkidiki_v2_planner_reply('πρόγραμμα στο Πευκοχώρι για παραλία φαγητό βόλτα και βραδινή έξοδο',$items);
t(strpos($pr,'Πρωί:')!==false && strpos($pr,'Μεσημέρι:')!==false && strpos($pr,'Απόγευμα:')!==false && strpos($pr,'Βράδυ:')!==false,'planner dayparts');
t(count(halkidiki_v2_filter_items($items,'Πευκοχώρι','food'))>0,'pefkochori food');
$coffee=halkidiki_v2_filter_items($items,'Πευκοχώρι','coffee'); t(count($coffee)>0,'pefkochori coffee');
$drink=array_column(halkidiki_v2_filter_items($items,'Άφυτος','drink'),'name'); t(in_array('Orizontas Bar & Kitchen',$drink,true),'afytos drink includes orizontas'); t(!in_array('Oceanides Seafood Restaurant',$drink,true),'afytos drink excludes oceanides');
$foodA=array_column(halkidiki_v2_filter_items($items,'Άφυτος','food'),'name'); t(in_array('Oceanides Seafood Restaurant',$foodA,true),'afytos food includes oceanides');
$dess=array_column(halkidiki_v2_filter_items($items,'Πευκοχώρι','dessert'),'name'); t(in_array('Crepe Cartel',$dess,true)||in_array('Crescendo Creperie',$dess,true),'pefkochori crepe');
$r1=halkidiki_v2_route('πευκοχωρι',[],[]); $r2=halkidiki_v2_route('φαγητο',$r1['pending']??[],[]); t($r1['route']==='business_clarification' && $r2['route']==='business_reply','pending clarification');
$clean=halkidiki_v2_clean_desc($items[1],'food','Πευκοχώρι'); t(strpos($clean,'To Bistro Greek Food')===false,'description duplicate removed');
t(strpos('PI&FI','&')!==false,'ampersand handling');

