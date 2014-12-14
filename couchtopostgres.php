<?php

ini_set("memory_limit","500M");
error_reporting(E_ALL);

$PGDB_USER   = 'myuser';
$PGDB_PASS   = 'mypassword';
$PGDB_HOST   = '192.168.0.100'; 
$PGDB_NAME   = 'mydatabase';
$PGDB_TABLE  = 'mytable';

$COUCH_URL   = 'http://192.168.0.200:5984';
$COUCH_DB    = 'mycouchdb';

function loadDoc($couch_doc_id){
        global $COUCH_URL, $COUCH_DB;
        $curl_out = exec('/usr/local/bin/curl -s -X GET \''.$COUCH_URL.'/'.$COUCH_DB.'/'.$couch_doc_id.'\'',$aout,$bout);
        $aout = implode("\n", $aout);
        $obj = json_decode($aout, true);
        return $obj;
}


$dbconn = pg_connect("host=$PGDB_HOST dbname=$PGDB_NAME user=$PGDB_USER password=") or die('Could not connect: ' . pg_last_error()); 

//First run can take a while on big dbs
//perhaps replace with a design doc view instead 
//but this makes no changes to couchdb data
$tmp_view  = 'function(doc) {';
$tmp_view .= "     emit(doc._id, doc._rev);";  
$tmp_view .= "}";

$cmd_line = "curl -s -X POST '$COUCH_URL/$COUCH_DB/_temp_view?include_docs=false' -H 'Content-Type: application/json' ";
$cmd_line .= "-d '{\"map\":\"".$tmp_view."\"}'";

//echo $cmd_line."\n";
$curl_out = exec($cmd_line,$aout,$bout);

$aout = implode("\n", $aout);

//$obj = json_decode($json, true);
$obj = json_decode($aout, true);
//print_r($obj);
//print_r($obj['rows']);

if(!isset($obj['rows'])) {
        die("\nNo rows returned in couchdb reply check: $COUCH_DB exists \n\n");
}

$rows = $obj['rows'];

if(sizeof($rows) == 0){
        die("\nNo documents found in couchdb named: $COUCH_DB \n\n");
}

$insert_count = 0;
$update_count = 0;



//Test and create table if it doesnt exist
$res = pg_query($dbconn,"SELECT EXISTS (
                            SELECT 1 
                            FROM   pg_catalog.pg_class c
                            JOIN   pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                            WHERE  n.nspname = 'public'
                            AND    c.relname = '".$PGDB_TABLE."'
                            AND    c.relkind = 'r')") 
        or die("Could not execute this select statement: ".pg_last_error());
$row = pg_fetch_row($res);
$table_exists = $row[0];
if($table_exists == 'f'){
        $res = pg_query($dbconn,"CREATE TABLE ".$PGDB_TABLE." (id text, doc jsonb,  CONSTRAINT ".$PGDB_TABLE."_pkey PRIMARY KEY (id))") 
               or die("Could not execute this select statement: ".pg_last_error());
        echo "Created table: $PGDB_TABLE \n";
}



//create array of id and rev of existing docs in postgres
$pg_docs = array();
$res = pg_query($dbconn,"SELECT id, doc->>'_rev' as rev from ".$PGDB_TABLE) 
        or die("Could not execute this select statement: ".pg_last_error());
while ($arr = pg_fetch_array($res)){
        $pg_docs[$arr[0]] = $arr[1];
}

$pg_start_count = sizeof($pg_docs);


foreach($rows as $row){

        $id = $row['id'];
        $rev = $row['value'];

        if(isset($pg_docs[$id])){
                if($rev != $pg_docs[$id]){
                        $doc = loadDoc($id);
                        $json_doc = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS);
                        pg_query($dbconn,"UPDATE ".$PGDB_TABLE." SET doc='".$json_doc."' WHERE id='".$id."'") 
                                or die("Could not execute this insert statement: ".pg_last_error());
                        echo ',';
                        $update_count++;
                }
                //}else{
                //      echo '-';
                //}
                unset($pg_docs[$id]); //done with this doc so remove to check if any left at end 
        }else{
                //insert doc into postgres
                $doc = loadDoc($id);
                $json_doc = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS);
                pg_query($dbconn,"INSERT INTO ".$PGDB_TABLE." (id, doc) VALUES ('".$id."','".$json_doc."')") 
                        or die("Could not execute this insert statement: ".pg_last_error());
                echo ".";
                $insert_count++;
        }
}

//echo 'pg_docs left:'.sizeof($pg_docs)."\n";
//print_r($pg_docs);
if(sizeof($pg_docs) > 0){  //should only be deleted docs left
        foreach(array_keys($pg_docs) as $pg_doc_id){
                pg_query($dbconn,"DELETE FROM ".$PGDB_TABLE." WHERE id='".$pg_doc_id."'") 
                        or die("Could not execute this insert statement: ".pg_last_error());
        }
}

//check number of docs correct
$res = pg_query($dbconn,"SELECT count(id) AS mycount FROM ".$PGDB_TABLE) 
        or die("Could not execute this insert statement: ".pg_last_error());
$row = pg_fetch_row($res);
$pg_end_count = $row[0];

echo "\n";
echo '--------------------------'."\n";
echo 'couch docs:          '.sizeof($rows)."\n";
echo 'postgres start docs: '.$pg_start_count."\n";
echo 'postgres end docs:   '.$pg_end_count."\n";
echo 'inserted docs:       '.$insert_count."\n";
echo 'update docs:         '.$update_count."\n";
echo '--------------------------'."\n";



?>
